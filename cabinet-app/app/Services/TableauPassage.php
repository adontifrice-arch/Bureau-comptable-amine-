<?php

namespace App\Services;

use App\Models\Client;

/**
 * Tableau de détermination du résultat fiscal (note commune n° 26/2016) :
 * annexe 1 pour les personnes physiques et les sociétés de personnes et assimilées, annexe 2 pour les sociétés passibles de l'IS.
 * Chaque ligne : code => [numéro, libellé]. Les « autres » réintégrations et déductions sont saisies librement.
 */
class TableauPassage
{
    public const AUTRES_LIGNES = 3;

    private const RESIDENCES = 'résidences secondaires, avions et bateaux de plaisance ne faisant pas l’objet de l’exploitation';
    private const VEHICULES = 'véhicules de tourisme d’une puissance fiscale supérieure à 9 CV ne faisant pas l’objet de l’exploitation';

    /** Réintégrations de l'annexe 1 (personnes physiques, sociétés de personnes et assimilées). */
    public const REINTEGRATIONS_1 = [
        'Charges non déductibles' => [
            'r1_1' => ['1.1', 'Rémunérations de l’exploitant individuel, ou des associés en nom des sociétés de personnes et assimilées'],
            'r1_2' => ['1.2', 'Charges relatives aux '.self::RESIDENCES],
            'r1_3' => ['1.3', 'Charges relatives aux '.self::VEHICULES],
            'r1_4a' => ['1.4', 'Cadeaux et frais de réception non déductibles'],
            'r1_4b' => ['1.4', 'Cadeaux et frais de réception excédentaires'],
            'r1_5' => ['1.5', 'Commissions, courtages, ristournes commerciales ou autres, vacations, honoraires et rémunérations de performance non déclarés dans la déclaration d’employeur'],
            'r1_6a' => ['1.6', 'Dons et subventions non déductibles'],
            'r1_6b' => ['1.6', 'Dons et subventions excédentaires'],
            'r1_7' => ['1.7', 'Abandon des créances non déductibles'],
            'r1_8' => ['1.8', 'Pertes de change non réalisées'],
            'r1_9' => ['1.9', 'Gains de change non réalisés antérieurement non imposés'],
            'r1_10' => ['1.10', 'Intérêts servis à l’exploitant ou aux associés des sociétés de personnes et assimilées'],
            'r1_11' => ['1.11', 'Charges d’une valeur supérieure ou égale à 5 000 dinars payées en espèces'],
            'r1_12' => ['1.12', 'Impôts directs supportés aux lieu et place d’autrui'],
            'r1_13' => ['1.13', 'Taxe de voyages'],
            'r1_14' => ['1.14', 'Transactions, amendes, confiscations et pénalités non déductibles'],
        ],
        'Amortissements' => [
            'r2_1a' => ['2.1', 'Amortissements non déductibles : '.self::RESIDENCES],
            'r2_1b' => ['2.1', 'Amortissements non déductibles : '.self::VEHICULES],
            'r2_1c' => ['2.1', 'Amortissements non déductibles : terrains et fonds de commerce'],
            'r2_1d' => ['2.1', 'Amortissements non déductibles : actifs d’une valeur supérieure ou égale à 5 000 dinars payés en espèces'],
            'r2_2a' => ['2.2', 'Partie des amortissements ayant dépassé la limite autorisée par la législation en vigueur'],
            'r2_2b' => ['2.2', 'Partie des amortissements correspondant à une période inférieure à la période autorisée (leasing ou ijara)'],
        ],
        'Provisions' => [
            'r3_1' => ['3.1', 'Provisions non déductibles'],
            'r3_2a' => ['3.2', 'Provisions déductibles : créances douteuses'],
            'r3_2b' => ['3.2', 'Provisions déductibles : dépréciation des actions cotées en bourse'],
            'r3_2c' => ['3.2', 'Provisions déductibles : dépréciation des stocks destinés à la vente'],
        ],
        'Produits non comptabilisés ou insuffisamment comptabilisés' => [
            'r4_1a' => ['4.1', 'Intérêts des créances non commerciales non décomptés'],
            'r4_1b' => ['4.1', 'Intérêts des créances non commerciales insuffisamment décomptés'],
            'r4_2a' => ['4.2', 'Plus-value de cession des actifs non comptabilisée'],
            'r4_2b' => ['4.2', 'Plus-value de cession des actifs insuffisamment comptabilisée'],
        ],
    ];

    /** Déductions de l'annexe 1. */
    public const DEDUCTIONS_1 = [
        'd1' => ['1', 'Reprise sur provisions réintégrées au résultat fiscal de l’année de leur constitution'],
        'd2' => ['2', 'Amortissements excédentaires réintégrés aux résultats des années antérieures'],
        'd3' => ['3', 'Gains de change réintégrés aux résultats des années antérieures'],
        'd4' => ['4', 'Gains de change non réalisés'],
        'd5' => ['5', 'Pertes de change antérieurement constatées'],
        'd6' => ['6', '50 % des salaires servis aux demandeurs d’emploi recrutés pour la première fois'],
    ];

    /** Réintégrations de l'annexe 2 (sociétés passibles de l'impôt sur les sociétés). */
    public const REINTEGRATIONS_2 = [
        'Charges non déductibles' => [
            'r1_1' => ['1.1', 'Charges relatives aux établissements situés à l’étranger'],
            'r1_2' => ['1.2', 'Quote-part des frais de siège imputable aux établissements situés à l’étranger (frais du siège × CA de l’établissement stable / CA total)'],
            'r1_3' => ['1.3', 'Charges relatives aux '.self::RESIDENCES],
            'r1_4' => ['1.4', 'Charges relatives aux '.self::VEHICULES],
            'r1_5a' => ['1.5', 'Cadeaux et frais de réception non déductibles'],
            'r1_5b' => ['1.5', 'Cadeaux et frais de réception excédentaires'],
            'r1_6' => ['1.6', 'Commissions, courtages, ristournes commerciales ou autres, vacations, honoraires et rémunérations de performance non déclarés dans la déclaration de l’employeur'],
            'r1_7a' => ['1.7', 'Dons et subventions non déductibles'],
            'r1_7b' => ['1.7', 'Dons et subventions excédentaires'],
            'r1_8' => ['1.8', 'Jetons de présence excédentaires'],
            'r1_9' => ['1.9', 'Abandon de créances non déductibles'],
            'r1_10' => ['1.10', 'Pertes de change non réalisées'],
            'r1_11' => ['1.11', 'Gains de change non réalisés antérieurement non imposés'],
            'r1_12' => ['1.12', 'Rémunérations excédentaires des titres participatifs et des comptes courants associés'],
            'r1_13' => ['1.13', 'Charges d’une valeur supérieure ou égale à 5 000 dinars payées en espèces'],
            'r1_14' => ['1.14', 'Moins-value de cession des titres des organismes de placement collectif en valeurs mobilières dans la limite des dividendes réalisés'],
            'r1_15' => ['1.15', 'Impôts directs supportés aux lieu et place d’autrui'],
            'r1_16' => ['1.16', 'Taxe de voyages'],
            'r1_17' => ['1.17', 'Transactions, amendes, confiscations et pénalités non déductibles'],
            'r1_18' => ['1.18', 'Dépenses excédentaires engagées pour la réalisation des opérations d’essaimage'],
        ],
        'Amortissements' => [
            'r2_1a' => ['2.1', 'Amortissements non déductibles : établissements situés à l’étranger'],
            'r2_1b' => ['2.1', 'Amortissements non déductibles : '.self::RESIDENCES],
            'r2_1c' => ['2.1', 'Amortissements non déductibles : '.self::VEHICULES],
            'r2_1d' => ['2.1', 'Amortissements non déductibles : terrains et fonds de commerce'],
            'r2_1e' => ['2.1', 'Amortissements non déductibles : actifs d’une valeur supérieure ou égale à 5 000 dinars payés en espèces'],
            'r2_2a' => ['2.2', 'Partie des amortissements ayant dépassé la limite autorisée par la législation en vigueur'],
            'r2_2b' => ['2.2', 'Partie des amortissements correspondant à une période inférieure à la période autorisée (leasing ou ijara)'],
        ],
        'Provisions' => [
            'r3_1' => ['3.1', 'Provisions non déductibles'],
            'r3_2a' => ['3.2', 'Provisions déductibles : créances douteuses (autres que celles constituées par les établissements de crédit)'],
            'r3_2b' => ['3.2', 'Provisions déductibles : dépréciation des actions cotées en bourse (autres que celles constituées par les sociétés d’investissement à capital risque)'],
            'r3_2c' => ['3.2', 'Provisions déductibles : dépréciation des stocks destinés à la vente'],
            'r3_2d' => ['3.2', 'Provisions déductibles : risque d’exigibilité des engagements techniques (compagnies d’assurance)'],
        ],
        'Produits non comptabilisés ou insuffisamment comptabilisés' => [
            'r4_1a' => ['4.1', 'Intérêts des comptes courants associés et des créances non commerciales non décomptés'],
            'r4_1b' => ['4.1', 'Intérêts des comptes courants associés et des créances non commerciales insuffisamment décomptés'],
            'r4_2a' => ['4.2', 'Plus-value de cession des actifs non comptabilisée'],
            'r4_2b' => ['4.2', 'Plus-value de cession des actifs insuffisamment comptabilisée'],
        ],
    ];

    /** Déductions de l'annexe 2. */
    public const DEDUCTIONS_2 = [
        'd1' => ['1', 'Produits réalisés par les établissements situés à l’étranger'],
        'd2' => ['2', 'Reprise sur provisions réintégrées au résultat fiscal de l’année de leur constitution'],
        'd3' => ['3', 'Amortissements excédentaires réintégrés aux résultats des années antérieures'],
        'd4' => ['4', 'Gains de change réintégrés aux résultats des années antérieures'],
        'd5' => ['5', 'Gains de change non réalisés'],
        'd6' => ['6', 'Pertes de change antérieurement constatées'],
        'd7' => ['7', '50 % des salaires servis aux demandeurs d’emploi recrutés pour la première fois'],
    ];

    /** Annexe applicable : 1 pour une personne physique ou une société de personnes (SNC, SCS…), 2 pour une société passible de l'IS. */
    public static function annexe(Client $c): int
    {
        return $c->estPhysique() || preg_match('/\b(snc|scs|soci[ée]t[ée] de personnes|en nom collectif|commandite simple)\b/iu', (string) $c->forme) ? 1 : 2;
    }

    public static function reintegrations(int $annexe): array
    {
        return $annexe === 1 ? self::REINTEGRATIONS_1 : self::REINTEGRATIONS_2;
    }

    public static function deductions(int $annexe): array
    {
        return $annexe === 1 ? self::DEDUCTIONS_1 : self::DEDUCTIONS_2;
    }

    /** Numéros des « autres » réintégrations et déductions dans l'annexe. */
    public static function numeroAutres(int $annexe): array
    {
        return ['rei' => '5', 'ded' => $annexe === 1 ? '7' : '8'];
    }

    /**
     * Données du tableau à partir de la saisie : montants par ligne (codes connus seulement), autres lignes libres,
     * et totaux des réintégrations et déductions (hors impôt sur les bénéfices comptabilisé, ajouté à part).
     */
    public static function donnees(int $annexe, array $saisie): array
    {
        $codesRei = array_merge(...array_map('array_keys', array_values(self::reintegrations($annexe))));
        $codesDed = array_keys(self::deductions($annexe));
        $lignes = fn (array $codes, $x) => array_filter(array_map(fn ($v) => $v === null || $v === '' ? null : r3((float) $v), array_intersect_key((array) $x, array_flip($codes))), fn ($v) => $v !== null && $v != 0);
        $autres = fn ($x) => array_values(array_filter(array_map(fn ($l) => ['lib' => trim((string) ($l['lib'] ?? '')), 'm' => r3((float) ($l['m'] ?? 0))], (array) $x), fn ($l) => $l['m'] != 0 || $l['lib'] !== ''));

        $d = ['rei_l' => $lignes($codesRei, $saisie['rei_l'] ?? []), 'rei_autres' => $autres($saisie['rei_autres'] ?? []),
            'ded_l' => $lignes($codesDed, $saisie['ded_l'] ?? []), 'ded_autres' => $autres($saisie['ded_autres'] ?? [])];
        $d['rei'] = r3(array_sum($d['rei_l']) + array_sum(array_column($d['rei_autres'], 'm')));
        $d['ded'] = r3(array_sum($d['ded_l']) + array_sum(array_column($d['ded_autres'], 'm')));
        return $d;
    }

    /**
     * Données à afficher : un tableau enregistré avant le détail par ligne (totaux seuls) présente ses totaux
     * comme une « autre » réintégration ou déduction, pour ne rien perdre.
     */
    public static function pourAffichage(?array $L): array
    {
        $L = (array) $L;
        $detail = array_key_exists('rei_l', $L) || array_key_exists('ded_l', $L);
        $global = 'Montant global (saisie antérieure)';
        return [
            'rei_l' => $L['rei_l'] ?? [], 'ded_l' => $L['ded_l'] ?? [],
            'rei_autres' => $detail ? ($L['rei_autres'] ?? []) : (! empty($L['rei']) ? [['lib' => $global, 'm' => (float) $L['rei']]] : []),
            'ded_autres' => $detail ? ($L['ded_autres'] ?? []) : (! empty($L['ded']) ? [['lib' => $global, 'm' => (float) $L['ded']]] : []),
        ];
    }
}
