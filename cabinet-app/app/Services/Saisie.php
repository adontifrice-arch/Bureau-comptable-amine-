<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Paramètres de saisie d'un dossier client (bouton « Paramétrage » de la comptabilité) :
 * valeurs proposées pour les factures d'achat et de vente, compte de banque, compte d'attente
 * et règles d'affectation des lignes de relevé bancaire (mot-clé du libellé → compte).
 */
class Saisie
{
    /** Taux de TVA en vigueur en Tunisie, proposés à la saisie. */
    public const TAUX_TVA = [19, 13, 7, 0];

    /**
     * « comptes » : comptes proposés à la saisie des factures, chacun avec son taux de TVA ; le premier est proposé par défaut.
     * « compte » et « tva_taux » : compte et taux par défaut (le premier compte proposé, sinon le taux général).
     */
    public const DEFAUTS = [
        'achat' => ['compte_tiers' => '401', 'compte' => '', 'tva_taux' => 19, 'timbre' => 1, 'retenue_taux' => 0, 'tva_deductible' => true, 'comptes' => []],
        'vente' => ['compte_tiers' => '411', 'compte' => '705', 'tva_taux' => 19, 'timbre' => 1, 'compte_timbre' => '437',
            'comptes' => [['compte' => '705', 'tva_taux' => 19]]],
        'banque' => ['compte' => '532', 'attente' => '461', 'regles' => [
            // Dans l'ordre : la première règle trouvée s'applique (« COMMISSION PRELEVEMENT … CNSS » reste un frais bancaire)
            ['mot_cle' => 'AGIOS', 'compte' => '6511'],
            ['mot_cle' => 'FRAIS', 'compte' => '6278'],
            ['mot_cle' => 'COMMISSION', 'compte' => '6278'],
            ['mot_cle' => 'COMM ', 'compte' => '6278'],
            ['mot_cle' => 'TENUE DE COMPTE', 'compte' => '6278'],
            ['mot_cle' => 'CNSS', 'compte' => '45311'],
            ['mot_cle' => 'SALAIRE', 'compte' => '425'],
            ['mot_cle' => 'REM TPE', 'compte' => '411000'],      // remises de paiements par carte (après « COMM » : la commission reste un frais)
        ]],
    ];

    private static function cle(int $client): string
    {
        return 'saisie.'.$client;
    }

    public static function parametres(int $client): array
    {
        $p = Setting::get(self::cle($client), []);
        $r = [];
        foreach (self::DEFAUTS as $k => $d) $r[$k] = array_merge($d, (array) ($p[$k] ?? []));
        foreach (['achat', 'vente'] as $k) {
            // Paramétrage antérieur à compte unique : il devient la première proposition
            if (isset($p[$k]) && ! array_key_exists('comptes', (array) $p[$k])) {
                $r[$k]['comptes'] = ($p[$k]['compte'] ?? '') !== '' ? [['compte' => (string) $p[$k]['compte'], 'tva_taux' => (float) ($p[$k]['tva_taux'] ?? 19)]] : [];
            }
            $r[$k]['comptes'] = array_values(array_map(fn ($c) => ['compte' => (string) $c['compte'], 'tva_taux' => (float) $c['tva_taux']], (array) $r[$k]['comptes']));
            $r[$k]['compte'] = $r[$k]['comptes'][0]['compte'] ?? '';
        }
        return $r;
    }

    /** Propositions sous forme { compte: taux } pour les formulaires de facture. */
    public static function tauxParCompte(array $section): array
    {
        $t = [];
        foreach ($section['comptes'] ?? [] as $c) $t[$c['compte']] ??= (float) $c['tva_taux'];
        return $t;
    }

    /** Taux proposé pour une nouvelle facture : celui du premier compte proposé, sinon le taux général. */
    public static function tauxParDefaut(array $section): float
    {
        return (float) ($section['comptes'][0]['tva_taux'] ?? $section['tva_taux']);
    }

    public static function enregistrer(int $client, array $p): void
    {
        Setting::put(self::cle($client), $p);
    }

    public static function achat(int $client): array { return self::parametres($client)['achat']; }
    public static function vente(int $client): array { return self::parametres($client)['vente']; }
    public static function banque(int $client): array { return self::parametres($client)['banque']; }
}
