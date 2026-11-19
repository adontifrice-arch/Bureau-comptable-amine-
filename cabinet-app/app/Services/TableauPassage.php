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

    /** Provisions déductibles dans la limite de 50 % du résultat fiscal. */
    public const PROVISIONS_1 = [
        'p1' => 'Provisions pour créances douteuses',
        'p2' => 'Provisions pour dépréciation des stocks destinés à la vente',
        'p3' => 'Provisions pour dépréciation de la valeur des actions cotées à la bourse des valeurs mobilières de Tunis',
    ];

    public const PROVISIONS_2 = self::PROVISIONS_1 + [
        'p4' => 'Provisions pour risque d’exigibilité des engagements techniques (compagnies d’assurance)',
    ];

    /** Annexe 2 : moins-value de levée d'option déductible dans la limite de 5 % du résultat fiscal après provisions. */
    public const MOINS_VALUE_OPTION = 'Déduction de la moins-value provenant de la levée de l’option par les salariés de souscription au capital des sociétés ou d’acquisition de leurs actions ou parts sociales, dans la limite de 5 % du résultat fiscal après déduction des provisions';

    /** III- Amortissements différés et déficits reportés : code => [numéro, libellé, signe]. */
    public const DEFICITS = [
        'a1' => ['1', 'Réintégration des amortissements de l’exercice', 1],
        'a2' => ['2', 'Déduction des déficits reportés', -1],
        'a3' => ['3', 'Déduction des amortissements de l’exercice', -1],
        'a4' => ['4', 'Déduction des amortissements différés en périodes déficitaires', -1],
    ];

    private const PV_BOURSE = [
        'Plus-value de cession des actions dans le cadre d’une opération d’introduction en bourse',
        'Plus-value de cession des actions cotées à la bourse des valeurs mobilières de Tunis cédées après l’expiration de l’année suivant celle de leur acquisition ou de leur souscription',
        'Plus-value de cession des actions et des parts sociales réalisée par l’intermédiaire des sociétés d’investissement à capital risque (totalement ou dans la limite de 50 %)',
        'Plus-value de cession des parts des fonds communs de placement à risque (totalement ou dans la limite de 50 %)',
        'Plus-value de cession des parts des fonds d’amorçage',
    ];

    private const PV_RESTRUCTURATION = [
        'Plus-value provenant de l’apport d’actions ou de parts sociales au capital de la société mère ou de la société holding dans le cadre des opérations de restructuration des entreprises ayant pour objet l’introduction de la société mère ou de la société holding à la bourse',
        'Plus-value provenant de la cession totale ou partielle des éléments de l’actif constituant une unité indépendante et autonome suite au départ à la retraite du propriétaire de l’entreprise ou à cause de l’incapacité de poursuivre la gestion de l’entreprise',
        'Plus-value provenant de la cession des entreprises en difficultés économiques dans le cadre du règlement judiciaire prévu par la loi relative au redressement des entreprises',
        'Intérêts des dépôts et de titres en devises ou en dinars convertibles',
    ];

    /** IV- Bénéfices ou revenus exceptionnels non imposables, par annexe. */
    public static function exceptionnels(int $annexe): array
    {
        $l = $annexe === 1
            ? [...self::PV_BOURSE, ...self::PV_RESTRUCTURATION]
            : ['Dividendes et assimilés distribués par des sociétés établies en Tunisie (revenus des parts des fonds communs de placement en valeurs mobilières, des fonds d’amorçage et des fonds communs de placement à risque)',
                ...self::PV_BOURSE,
                'Plus-value d’apport dans le cadre d’une opération de fusion ou de scission totale de sociétés, ou d’une opération d’apport des entreprises individuelles dans le capital d’une société (au niveau de la société absorbée, scindée ou ayant fait l’apport)',
                ...self::PV_RESTRUCTURATION];
        $r = [];
        foreach ($l as $i => $lib) $r['x'.($i + 1)] = [(string) ($i + 1), $lib];
        return $r;
    }

    /** VI- Bénéfices et gains exceptionnels non éligibles aux avantages fiscaux (lignes F6005 82 à 90). */
    public const NON_ELIGIBLES = [
        'Revenus accessoires' => ['v1' => ['1.1', 'Loyers'], 'v2' => ['1.1', 'Revenus de capitaux mobiliers'], 'v3' => ['1.1', 'Dividendes de source étrangère'],
            'v7' => ['1.1', 'Autres revenus accessoires']],
        'Gains exceptionnels' => ['v4' => ['1.2', 'Plus-value de cession des immeubles bâtis et non bâtis et des fonds de commerce'],
            'v5' => ['1.2', 'Gains de change non rattachés à l’activité principale'], 'v6' => ['1.2', 'Plus-value provenant de la cession des titres'],
            'v8' => ['1.2', 'Autres gains exceptionnels']],
    ];

    /** VII- Déductions des bénéfices au titre de l'exploitation (lignes F6005 93 à 96), puis revenus réinvestis (99) et, annexe 2, cinquième de la plus-value de fusion (100). */
    public static function finales(int $annexe): array
    {
        $l = [
            'e1' => ['Déduction des bénéfices au titre de l’exportation', -1],
            'e5' => ['Déduction des bénéfices au titre du développement régional', -1],
            'e6' => ['Déduction des bénéfices au titre du développement agricole', -1],
            'e2' => [$annexe === 1 ? 'Autres déductions des bénéfices au titre de l’exploitation (dont bénéfices soumis à l’avance de 10 %)' : 'Autres déductions des bénéfices au titre de l’exploitation', -1],
            'e3' => ['Déduction des revenus réinvestis', -1],
        ];
        if ($annexe === 2) $l['e4'] = ['Réintégration du cinquième de la plus-value provenant d’opérations de fusion, scission ou d’apport (dans la limite de 50 %) pour la société absorbante ou bénéficiaire de la scission ou de l’apport d’entreprise individuelle', 1];
        return $l;
    }

    /**
     * Ligne du tableau F6005 de la liasse fiscale (cahier des charges) correspondant à chaque ligne du tableau de passage.
     * Les réintégrations sans ligne propre dans la liasse (jetons de présence, autres) vont en 44, les autres déductions en 53.
     */
    public static function lignesF6005(int $annexe): array
    {
        $commun = ['p1' => 57, 'p2' => 58, 'p3' => 59, 'p4' => 60, 'mv' => 62, 'a1' => 64, 'a2' => 65, 'a3' => 66, 'a4' => 67,
            'v1' => 82, 'v2' => 83, 'v3' => 84, 'v7' => 85, 'v4' => 87, 'v5' => 88, 'v6' => 89, 'v8' => 90,
            'e1' => 93, 'e5' => 94, 'e6' => 95, 'e2' => 96, 'e3' => 99, 'e4' => 100,
            'r2_2a' => 31, 'r2_2b' => 32, 'r3_1' => 34, 'r3_2a' => 35, 'r3_2b' => 36, 'r3_2c' => 37, 'r3_2d' => 38,
            'r4_1a' => 40, 'r4_1b' => 41, 'r4_2a' => 42, 'r4_2b' => 43];
        return $commun + ($annexe === 1 ? [
            'r1_1' => 4, 'r1_2' => 7, 'r1_3' => 8, 'r1_4a' => 9, 'r1_4b' => 10, 'r1_5' => 11, 'r1_6a' => 12, 'r1_6b' => 13, 'r1_7' => 14, 'r1_8' => 15,
            'r1_9' => 16, 'r1_10' => 17, 'r1_11' => 19, 'r1_12' => 21, 'r1_13' => 22, 'r1_14' => 23,
            'r2_1a' => 27, 'r2_1b' => 28, 'r2_1c' => 29, 'r2_1d' => 30,
            'd1' => 47, 'd2' => 48, 'd3' => 49, 'd4' => 50, 'd5' => 51, 'd6' => 52,
            'x1' => 70, 'x2' => 71, 'x3' => 72, 'x4' => 73, 'x5' => 74, 'x6' => 76, 'x7' => 77, 'x8' => 78, 'x9' => 79,
        ] : [
            'r1_1' => 5, 'r1_2' => 6, 'r1_3' => 7, 'r1_4' => 8, 'r1_5a' => 9, 'r1_5b' => 10, 'r1_6' => 11, 'r1_7a' => 12, 'r1_7b' => 13, 'r1_8' => 44,
            'r1_9' => 14, 'r1_10' => 15, 'r1_11' => 16, 'r1_12' => 18, 'r1_13' => 19, 'r1_14' => 20, 'r1_15' => 21, 'r1_16' => 22, 'r1_17' => 23, 'r1_18' => 24,
            'r2_1a' => 26, 'r2_1b' => 27, 'r2_1c' => 28, 'r2_1d' => 29, 'r2_1e' => 30,
            'd1' => 46, 'd2' => 47, 'd3' => 48, 'd4' => 49, 'd5' => 50, 'd6' => 51, 'd7' => 52,
            'x1' => 69, 'x2' => 70, 'x3' => 71, 'x4' => 72, 'x5' => 73, 'x6' => 74, 'x7' => 75, 'x8' => 76, 'x9' => 77, 'x10' => 78, 'x11' => 79,
        ]);
    }

    /**
     * Montants saisis du tableau de passage, rangés par ligne F6005 (lignes de détail seulement, totaux calculés par la liasse).
     * Un passage enregistré en totaux seuls donne ses totaux en 44 (autres réintégrations) et 53 (autres déductions).
     */
    public static function vecteurF6005(int $annexe, array $L): array
    {
        $map = self::lignesF6005($annexe);
        $v = [];
        $ajoute = function (?int $l, $m) use (&$v) { if ($l && (float) $m != 0) $v[$l] = round(($v[$l] ?? 0) + (float) $m, 3); };
        if (! array_intersect(['rei_l', 'ded_l', 'rei_autres', 'ded_autres', 'suite'], array_keys($L))) {   // passage enregistré en totaux seuls
            $ajoute(44, $L['rei'] ?? 0); $ajoute(53, $L['ded'] ?? 0);
            return $v;
        }
        foreach ([...(array) ($L['rei_l'] ?? []), ...(array) ($L['ded_l'] ?? []), ...(array) ($L['suite'] ?? [])] as $code => $m) $ajoute($map[$code] ?? null, $m);
        foreach ((array) ($L['rei_autres'] ?? []) as $a) $ajoute(44, $a['m'] ?? 0);
        foreach ((array) ($L['ded_autres'] ?? []) as $a) $ajoute(53, $a['m'] ?? 0);
        return $v;
    }

    public static function provisions(int $annexe): array
    {
        return $annexe === 1 ? self::PROVISIONS_1 : self::PROVISIONS_2;
    }

    /** Codes des montants des sections III à VII propres à l'annexe. */
    public static function codesSuite(int $annexe): array
    {
        return array_merge(array_keys(self::provisions($annexe)), $annexe === 2 ? ['mv'] : [], array_keys(self::DEFICITS),
            array_keys(self::exceptionnels($annexe)), array_merge(...array_map('array_keys', array_values(self::NON_ELIGIBLES))), array_keys(self::finales($annexe)));
    }

    /**
     * Calcul complet du tableau, du résultat comptable après impôt au résultat imposable, par les règles du tableau F6005
     * du cahier des charges de la liasse fiscale (LiasseFiscale::resultatFiscal) : provisions limitées à 50 % du résultat fiscal,
     * moins-value de levée d'option (annexe 2) et résultat avant déficits ramené à zéro au plus bas, cas déficitaires
     * (lignes 66 à 101 à zéro et déficit reportable en 107), résultat avant déduction des bénéfices d'exploitation (80) au moins nul.
     */
    public static function calcul(int $annexe, float $res, float $impots, array $L): array
    {
        $F = LiasseFiscale::resultatFiscal($res + $impots, self::vecteurF6005($annexe, $L));
        return ['F' => $F, 'avant_prov' => $F[55], 'prov_total' => r3($F[57] + $F[58] + $F[59] + $F[60]), 'prov_limite' => r3(max(0, $F[56]) / 2),
            'prov' => r3($F[56] - $F[61]), 'apres_prov' => $F[61], 'mv' => $F[62], 'avant_deficits' => $F[63], 'apres_deficits' => $F[68],
            'V' => $F[80], 'VI' => $F[92], 'apres_exploitation' => $F[98], 'imposable' => $F[101],
            'deficitaire' => $F[55] < 0 || ($F[55] >= 0 && $F[107] < 0), 'deficit_reportable' => $F[107]];
    }

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
        $autres = fn ($x) => array_values(array_filter(array_map(fn ($l) => ['lib' => trim((string) ($l['lib'] ?? '')), 'm' => r3((float) ($l['m'] ?? 0))], (array) $x), fn ($l) => $l['m'] != 0));   // ligne sans montant ignorée

        $d = ['rei_l' => $lignes($codesRei, $saisie['rei_l'] ?? []), 'rei_autres' => $autres($saisie['rei_autres'] ?? []),
            'ded_l' => $lignes($codesDed, $saisie['ded_l'] ?? []), 'ded_autres' => $autres($saisie['ded_autres'] ?? [])];
        $d['rei'] = r3(array_sum($d['rei_l']) + array_sum(array_column($d['rei_autres'], 'm')));
        $d['ded'] = r3(array_sum($d['ded_l']) + array_sum(array_column($d['ded_autres'], 'm')));
        // Sections III à VII
        $d['suite'] = $lignes(self::codesSuite($annexe), $saisie['suite'] ?? []);
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
            'rei_l' => $L['rei_l'] ?? [], 'ded_l' => $L['ded_l'] ?? [], 'suite' => $L['suite'] ?? [],
            'rei_autres' => $detail ? ($L['rei_autres'] ?? []) : (! empty($L['rei']) ? [['lib' => $global, 'm' => (float) $L['rei']]] : []),
            'ded_autres' => $detail ? ($L['ded_autres'] ?? []) : (! empty($L['ded']) ? [['lib' => $global, 'm' => (float) $L['ded']]] : []),
        ];
    }
}
