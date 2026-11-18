<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * Échéancier clients et fournisseurs à une date d'arrêté, à partir des lignes non lettrées des comptes de tiers.
 *
 * - Les pièces (factures, notes de frais…) sont les lignes dans le sens du compte : débit pour les clients (41),
 *   crédit pour les fournisseurs (40) et le personnel (42). Échéance = date de la pièce + délai de paiement du dossier.
 * - Les lignes de sens contraire (règlements, avances, avoirs non lettrés) forment la colonne « non affecté ».
 * - Tranches : à échoir à plus de 30 jours, à échoir sous 30 jours, échu de 1 à 30, 31 à 60, 61 à 90 et plus de 90 jours.
 * Montants dans le sens du compte : ce que le client doit, ce qui est dû au fournisseur.
 */
class Echeancier
{
    public const TRANCHES = ['echoir30p' => 'À échoir > 30 j', 'echoir30' => 'À échoir ≤ 30 j', 'echu30' => 'Échu 1 à 30 j',
        'echu60' => 'Échu 31 à 60 j', 'echu90' => 'Échu 61 à 90 j', 'echu90p' => 'Échu > 90 j'];

    /** Délais de paiement par défaut (jours) : fournisseurs, clients, personnel. */
    public const DELAIS = ['40' => 30, '41' => 30, '42' => 0];

    public static function delais(int $client): array
    {
        $d = (array) Setting::get('echeancier.'.$client, []);
        $out = [];
        foreach (self::DELAIS as $k => $v) $out[$k] = isset($d[$k]) ? max(0, (int) $d[$k]) : $v;
        return $out;
    }

    public static function enregistrerDelais(int $client, array $delais): void
    {
        Setting::put('echeancier.'.$client, array_map(fn ($v) => max(0, (int) $v), array_intersect_key($delais, self::DELAIS)));
    }

    /** Tranche d'une échéance par rapport à la date d'arrêté. */
    public static function tranche(string $echeance, string $date): string
    {
        $retard = (int) Carbon::parse($echeance)->diffInDays(Carbon::parse($date), false);   // > 0 : échu depuis n jours
        return match (true) {
            $retard <= -31 => 'echoir30p',
            $retard <= 0 => 'echoir30',
            $retard <= 30 => 'echu30',
            $retard <= 60 => 'echu60',
            $retard <= 90 => 'echu90',
            default => 'echu90p',
        };
    }

    public static function calculer(int $client, string $date, ?string $section = null, ?string $compte = null): array
    {
        $delais = self::delais($client);
        $NL = Lettrage::nonLettres($client, $date, $section, $compte);
        $vide = array_fill_keys(array_keys(self::TRANCHES), 0.0) + ['non_affecte' => 0.0, 'total' => 0.0];
        $sections = [];
        foreach ($NL['sections'] as $rc => $s) {
            $sens = (string) $rc === '41' ? 1 : -1;                                 // clients : débit ; fournisseurs, personnel : crédit
            $S = $vide; $tiers = [];
            foreach ($s['comptes'] as $c) {
                $t = $vide + ['compte' => $c['compte'], 'libelle' => $c['libelle'], 'pieces' => []];
                foreach ($c['lignes'] as $l) {
                    $m = r3($sens * ($l['debit'] - $l['credit']));
                    if ($m > 0) {
                        $ech = Carbon::parse($l['date'])->addDays($delais[$rc] ?? 0)->format('Y-m-d');
                        $tr = self::tranche($ech, $date);
                        $t[$tr] += $m;
                        $t['pieces'][] = $l + ['montant' => $m, 'echeance' => $ech, 'tranche' => $tr,
                            'retard' => max(0, (int) Carbon::parse($ech)->diffInDays(Carbon::parse($date), false))];
                    } else {
                        $t['non_affecte'] += $m;
                        $t['pieces'][] = $l + ['montant' => $m, 'echeance' => null, 'tranche' => 'non_affecte', 'retard' => 0];
                    }
                    $t['total'] += $m;
                }
                foreach (array_keys($vide) as $k) { $t[$k] = r3($t[$k]); $S[$k] += $t[$k]; }
                $tiers[] = $t;
            }
            $sections[$rc] = ['titre' => $s['titre'], 'delai' => $delais[$rc] ?? 0, 'tiers' => $tiers, 'T' => array_map('r3', $S)];
        }
        return ['sections' => $sections, 'delais' => $delais];   // pas de total général : créances et dettes ne s'additionnent pas
    }
}
