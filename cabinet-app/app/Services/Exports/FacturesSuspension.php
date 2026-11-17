<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Declaration;

/**
 * Liste détaillée des factures émises en suspension des taxes sur le chiffre d'affaires :
 * fichier « FAC_Tn_AA » (cahier des charges DGI 2007) et contrôles repris de l'outil officiel fachtva2012.
 * Tous les enregistrements font 331 caractères : EF (220 + 111 espaces), DF, TF.
 */
class FacturesSuspension extends FichierDgi
{
    /** Type d'identifiant du client (zone DF10). */
    public const TYPES_ID = ['1' => 'Matricule fiscal', '2' => 'Carte d’identité nationale', '3' => 'Carte de séjour (étrangers)', '4' => 'Non domicilié ni établi en Tunisie'];

    public static function prefixe(): string
    {
        return 'FAC';
    }

    /** Identifiant client sur 13 caractères : matricule fiscal, ou numéro à 8 chiffres cadré par des zéros suivi de 5 espaces. */
    public static function identifiantClient(?string $idk, ?string $id): string
    {
        if ($idk === '1') return self::x(self::matricule($id)['brut'] ?? strtoupper((string) $id), 13);
        return str_pad(substr(preg_replace('/\D/', '', (string) $id), -8), 8, '0', STR_PAD_LEFT).'     ';
    }

    public static function controles(array $l, array $entete): array
    {
        $e = [];
        $fac = (string) ($l['fac'] ?? '');
        if ($fac === '' || mb_strlen($fac) > 20) $e[] = 'N° de facture : 1 à 20 caractères.';
        if (empty($l['fac_date'])) $e[] = 'Date de facture manquante.';
        $idk = (string) ($l['idk'] ?? '');
        if (! isset(self::TYPES_ID[$idk])) $e[] = 'Type d’identifiant du client manquant (1 à 4).';
        elseif ($idk === '1') { if ($m = self::erreurMatricule($l['id'] ?? null)) $e[] = $m; }
        elseif (! preg_match('/^\d{1,8}$/', (string) ($l['id'] ?? ''))) $e[] = 'Identifiant du client : 8 chiffres au plus.';
        if (trim((string) ($l['nom'] ?? '')) === '' || mb_strlen((string) $l['nom']) > 40) $e[] = 'Nom ou raison sociale du client : 1 à 40 caractères.';
        if (mb_strlen((string) ($l['adr'] ?? '')) > 120) $e[] = 'Adresse du client : 120 caractères au plus.';
        $att = (string) ($l['att'] ?? '');
        if ($att === '' || mb_strlen($att) > 20) $e[] = 'N° de l’autorisation administrative : 1 à 20 caractères.';
        if (empty($l['att_date'])) $e[] = 'Date de l’autorisation administrative manquante.';
        if ((float) ($l['ht'] ?? 0) <= 0) $e[] = 'Prix HT invalide.';
        if ((float) ($l['tva'] ?? 0) + (float) ($l['fodec'] ?? 0) + (float) ($l['dc'] ?? 0) > (float) ($l['ht'] ?? 0)) $e[] = 'Les taxes suspendues ne doivent pas dépasser le prix HT.';
        return $e;
    }

    /** Totaux pour le bordereau de transmission et la liste détaillée. */
    public static function totaux(Declaration $d): array
    {
        $L = collect($d->annexes['l'] ?? []);
        return ['lignes' => $L->count(), 'factures' => $L->count(), 'ht' => r3($L->sum('ht')), 'fodec' => r3($L->sum('fodec')), 'dc' => r3($L->sum('dc')), 'tva' => r3($L->sum('tva'))];
    }

    public static function fichier(Client $c, Declaration $d): string
    {
        $ent = self::cle($c, $d);
        $out = [self::entete($c, $d).str_repeat(' ', 111)];
        $t = ['ht' => 0, 'fodec' => 0, 'dc' => 0, 'tva' => 0]; $n = 0;
        foreach ($d->annexes['l'] ?? [] as $l) {
            $m = []; foreach ($t as $k => $_) { $m[$k] = self::millimes($l[$k] ?? 0); $t[$k] += $m[$k]; }
            $out[] = 'DF'.$ent.self::n(++$n, 6).self::x($l['fac'] ?? '', 20).self::date($l['fac_date'] ?? null).self::n($l['idk'] ?? '', 1)
                .self::identifiantClient($l['idk'] ?? null, $l['id'] ?? null).self::x($l['nom'] ?? '', 40).self::x($l['adr'] ?? '', 120)
                .self::n(self::ascii($l['att'] ?? ''), 20).self::date($l['att_date'] ?? null).self::n($m['ht'], 15)
                .self::taux($l['fodec_taux'] ?? 0).self::n($m['fodec'], 15).self::taux($l['dc_taux'] ?? 0).self::n($m['dc'], 15)
                .self::taux($l['taux'] ?? 0).self::n($m['tva'], 15);
        }
        $out[] = 'TF'.$ent.self::n($n, 6).str_repeat(' ', 230).self::n($t['ht'], 15).'00000'.self::n($t['fodec'], 15).'00000'.self::n($t['dc'], 15).'00000'.self::n($t['tva'], 15);
        return implode("\r\n", $out)."\r\n";
    }
}
