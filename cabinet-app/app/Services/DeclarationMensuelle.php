<?php

namespace App\Services;

/**
 * Déclaration mensuelle des impôts (التصريح الشهري بالأداءات, modèle DGI 2026).
 *
 * Chaque rubrique porte une base, un taux et un montant : champs[code] = ['b' => base, 't' => taux, 'm' => montant].
 * Modes : bt (montant = base × taux %), tm (taux à saisir), q (montant = quantité × tarif unitaire),
 * bm (base et montant saisis, taux variable), m (montant seul), b (base seule, à titre d'information).
 * Sens : I (impôt dû) ou II (déduction) pour la TFP, le droit de consommation et la TVA.
 * Entête et pénalités : champs['acte'], champs['pen'][section].
 */
class DeclarationMensuelle
{
    public const SECTIONS = [
        'rs' => 'Retenues à la source',
        'tfp' => 'Taxe de formation professionnelle',
        'fop' => 'Contribution au FOPROLOS',
        'dc' => 'Droit de consommation',
        'tva' => 'Taxe sur la valeur ajoutée',
        'aut' => 'Autres taxes',
        'tim' => 'Droit de timbre',
        'tcl' => 'Taxes revenant aux collectivités locales',
    ];

    public const ACTES = ['0' => 'Spontanée', '1' => 'Régularisation', '2' => 'Rectificative', '3' => 'Taxation d’office', '4' => 'Cessation d’activité'];

    /** Cases « nature de l'impôt ou de la taxe » de l'entête du modèle : clé => [libellé, libellé arabe, section]. */
    public const IMPOTS = [
        'rs' => ['Retenue à la source', 'الخصم من المورد', 'rs'],
        'tfp' => ['Taxe de formation professionnelle', 'الأداء على التكوين المهني', 'tfp'],
        'fop' => ['FOPROLOS', 'المساهمة في صندوق النهوض بالمسكن لفائدة الأجراء', 'fop'],
        'dc' => ['Droit de consommation', 'المعلوم على الاستهلاك', 'dc'],
        'tva' => ['TVA', 'الأداء على القيمة المضافة', 'tva'],
        'aut' => ['Autres taxes sur le chiffre d’affaires', 'معاليم أخرى على رقم المعاملات', 'aut'],
        'tim' => ['Droit de timbre', 'معلوم الطابع الجبائي', 'tim'],
        'tcl_ent' => ['Taxe sur les établissements (TCL)', 'المعلوم على المؤسسات', 'tcl'],
        'tcl_hot' => ['Taxe hôtelière', 'المعلوم على النزل', 'tcl'],
        'tcl_lic' => ['Droit de licence', 'معلوم الإجازة', 'tcl'],
    ];

    /** Rubriques des taxes des collectivités locales, par case de l'entête. */
    public const TCL = ['tcl_ent' => ['tcl_loc', 'tcl_exp', 'tcl_hom', 'tcl_is'], 'tcl_hot' => ['tcl_hotel'], 'tcl_lic' => ['tcl_lic']];

    /** Rubriques : section => [code => [libellé, taux par défaut, mode, sens]]. */
    public const LIGNES = [
        'rs' => [
            'rs1' => ['1- Traitements, salaires, pensions et rentes viagères (régime commun)', null, 'bm'],
            'rs2' => ['2- Traitements, salaires et avantages payés aux étrangers', 20, 'bt'],
            'rs3' => ['3- Contribution sociale de solidarité sur les traitements et salaires', null, 'bm'],
            'rs4a' => ['4- Commissions, courtages, loyers et rémunérations non commerciales – résidents, personnes physiques', 10, 'bt'],
            'rs4b' => ['4- … – résidents, personnes morales', 10, 'bt'],
            'rs4c' => ['4- … – non-résidents, personnes physiques', 15, 'bt'],
            'rs4d' => ['4- … – non-résidents, personnes morales', 15, 'bt'],
            'rs5' => ['5- Honoraires des personnes physiques hors régime réel', 10, 'bt'],
            'rs6' => ['6- Honoraires des personnes morales et des personnes physiques au régime réel', 3, 'bt'],
            'rs7' => ['7- Rémunérations des artistes et créateurs', 5, 'bt'],
            'rs8' => ['8- Loyers des hôtels (personnes morales et régime réel)', 5, 'bt'],
            'rs9' => ['9- Rémunérations de la performance dans la prestation de services pour autrui', 10, 'bt'],
            'rs10' => ['10- Intérêts des comptes spéciaux d’épargne, prêts et bons du Trésor, emprunts nationaux', 20, 'bt'],
            'rs11a' => ['11- Revenus des autres capitaux mobiliers – résidents, personnes physiques', 20, 'bt'],
            'rs11b' => ['11- … – résidents, personnes morales', 20, 'bt'],
            'rs11c' => ['11- … – non-résidents, personnes physiques', 20, 'bt'],
            'rs11d' => ['11- … – non-résidents, personnes morales', 20, 'bt'],
            'rs12a' => ['12- Dividendes – personnes physiques résidentes', 10, 'bt'],
            'rs12b' => ['12- Dividendes – personnes morales et personnes physiques non résidentes', 10, 'bt'],
            'rs13a' => ['13- Rémunérations des membres des conseils et comités – résidents, personnes physiques', 20, 'bt'],
            'rs13b' => ['13- … – résidents, personnes morales', 20, 'bt'],
            'rs13c' => ['13- … – non-résidents, personnes physiques', 20, 'bt'],
            'rs13d' => ['13- … – non-résidents, personnes morales', 20, 'bt'],
            'rs14' => ['14- Rémunérations d’un travail occasionnel ou accidentel', 15, 'bt'],
            'rs15' => ['15- Intérêts des prêts payés aux banques non établies en Tunisie', 10, 'bt'],
            'rs16a' => ['16- Prix de cession d’immeubles et de droits immobiliers – résidents ou établis', 2.5, 'bt'],
            'rs16b' => ['16- … – non-résidents, personnes physiques', 2.5, 'bt'],
            'rs16c' => ['16- … – personnes morales non établies', 15, 'bt'],
            'rs17a' => ['17- Acquisitions ≥ 1 000 D TTC – entreprises soumises à l’IS de 20 %', 1, 'bt'],
            'rs17b' => ['17- … – entreprises soumises à l’IS de 10 %', 0.5, 'bt'],
            'rs17c' => ['17- … – entreprises individuelles bénéficiant de la déduction des deux tiers', 0.5, 'bt'],
            'rs17d' => ['17- … – autres entreprises', 1.5, 'bt'],
            'rs18' => ['18- Retenue de TVA sur les montants ≥ 1 000 D payés par l’État et le secteur public', 25, 'bt'],
            'rs19' => ['19- Retenue de TVA sur les opérations avec des personnes non établies en Tunisie', 100, 'bt'],
            'rs20' => ['20- Non-résidents établis : construction, montage, autres services (≤ 6 mois)', 15, 'tm'],
            'rs21a' => ['21- Non-résidents sans déclaration d’existence – établissement d’un paradis fiscal', 25, 'bt'],
            'rs21b' => ['21- … – autres établissements stables', 15, 'bt'],
            'rs22' => ['22- Avance sur les ventes aux forfaitaires (BIC et BNC)', 1, 'bt'],
            'rs23' => ['23- Avance sur les ventes de vin, bière et boissons alcoolisées', 5, 'bt'],
            'rs24a' => ['24- Plus-value de cession d’actions et parts par des non-résidents – personnes physiques', 10, 'bt'],
            'rs24b' => ['24- … – personnes morales', 20, 'bt'],
            'rs25a' => ['25- Autres rémunérations payées à des non-résidents – personnes physiques', 15, 'bt'],
            'rs25b' => ['25- … – personnes morales', 15, 'bt'],
            'rs26' => ['26- Rémunérations versées à des résidents d’un paradis fiscal', 25, 'bt'],
            'rs27a' => ['27- Commissions des distributeurs agréés des opérateurs télécoms – personnes physiques', 1.5, 'bt'],
            'rs27b' => ['27- … – personnes morales', 1, 'bt'],
            'rs28' => ['28- Droits d’enregistrement sur les marchés publics', null, 'm'],
            'rs29' => ['29- Gains des jeux de pari, de hasard et de loterie', 25, 'bt'],
            'rs30' => ['30- Ventes aux intervenants de la distribution (≤ 20 000 D par an)', 3, 'bt'],
            'rs31' => ['31- Livraison de ventes en ligne sans carte d’identification fiscale', 3, 'bt'],
        ],
        'tfp' => [
            'tfp1' => ['Industries manufacturières', 1, 'bt', 'I'],
            'tfp2' => ['Autres activités', 2, 'bt', 'I'],
            'tfp_d' => ['Déductions : avance, excédent, ristournes non encore déduits', null, 'm', 'II'],
        ],
        'fop' => [
            'fop' => ['Contribution au fonds de promotion du logement des salariés', 1, 'bt'],
        ],
        'dc' => [
            'dc1' => ['Chiffre d’affaires hors droit de consommation et TVA', null, 'tm', 'I'],
            'dc_al' => ['Achats locaux', null, 'tm', 'II'],
            'dc_ai' => ['Achats importés', null, 'tm', 'II'],
            'dc_prec' => ['Excédent du mois précédent', null, 'm', 'II'],
        ],
        'tva' => [
            'tva7' => ['Chiffre d’affaires taxable à 7 %', 7, 'bt', 'I'],
            'tva13' => ['Chiffre d’affaires taxable à 13 %', 13, 'bt', 'I'],
            'tva19' => ['Chiffre d’affaires taxable à 19 %', 19, 'bt', 'I'],
            'tvax1' => ['Chiffre d’affaires taxable à un autre taux (6 %, 12 %, 18 %)', null, 'tm', 'I'],
            'tvax2' => ['Chiffre d’affaires taxable à un autre taux', null, 'tm', 'I'],
            'tva_soi' => ['Livraisons à soi-même', 19, 'tm', 'I'],
            'tva_rev' => ['Reversement de TVA', null, 'm', 'I'],
            'tvd_imm' => ['Achats d’immeubles (TVA déductible)', null, 'bm', 'II'],
            'tvd_eql' => ['Achats d’équipements locaux', null, 'bm', 'II'],
            'tvd_eqi' => ['Achats d’équipements importés', null, 'bm', 'II'],
            'tvd_al' => ['Autres achats locaux', null, 'bm', 'II'],
            'tvd_ai' => ['Autres achats importés', null, 'bm', 'II'],
            'tvd_r25' => ['TVA retenue à la source (25 %) sur les montants ≥ 1 000 D', 25, 'bt', 'II'],
            'tvd_r100' => ['TVA due sur les opérations avec des non-établis (100 %)', 100, 'bt', 'II'],
            'tvd_transp' => ['TVA forfaitaire sur les moyens de transport', null, 'm', 'II'],
            'tvd_soi' => ['Livraisons à soi-même (déduction)', null, 'm', 'II'],
            'tvd_annul' => ['Régularisation : résiliations et annulations', null, 'm', 'II'],
            'tvd_aut' => ['Régularisation : autres opérations', null, 'm', 'II'],
            'tva_prec' => ['Crédit de TVA du mois précédent', null, 'm', 'C'],
            'tva_restit' => ['Montant restitué (déclarations rectificatives)', null, 'm', 'R'],
            'inf_susp' => ['Pour information : achats en suspension de TVA', null, 'b'],
            'inf_exo' => ['Pour information : achats exonérés', null, 'b'],
            'inf_nass' => ['Pour information : achats auprès de non-assujettis', null, 'b'],
            'inf_exp' => ['Pour information : exportations de biens et services', null, 'b'],
            'inf_vsusp' => ['Pour information : ventes en suspension de TVA', null, 'b'],
            'inf_caexo' => ['Pour information : chiffre d’affaires exonéré', null, 'b'],
        ],
        'aut' => [
            'au1a' => ['1- FODEC (industrie, services, artisanat) : droit professionnel', 1, 'bt'],
            'au1b' => ['1- FODEC : droit sur les conserves alimentaires', 1, 'bt'],
            'au2a' => ['2- Fonds agricole et pêche : produits de la pêche', 2, 'bt'],
            'au2b' => ['2- … légumineuses et soja', 2.5, 'bt'],
            'au2c' => ['2- … fruits et légumes', 2, 'bt'],
            'au2d' => ['2- … viandes (D/kg)', 0.05, 'q'],
            'au3a' => ['3- Fonds du tourisme : établissements et restaurants touristiques', 1, 'bt'],
            'au3b' => ['3- … vols première classe et affaires (D/passager)', 60, 'q'],
            'au3c' => ['3- … autres vols (D/passager)', 40, 'q'],
            'au4' => ['4- Taxe au profit du fonds des télécommunications et des TIC', 5, 'bt'],
            'au5a' => ['5- Fonds national de l’emploi : thé et café (D/kg)', 0.15, 'q'],
            'au5b' => ['5- … droit compensatoire sur le ciment (D/t)', 2, 'q'],
            'au5c' => ['5- … vente de ciment (D/t)', 1, 'q'],
            'au6a' => ['6- Tomates de transformation : producteurs (D/kg)', 0.005, 'q'],
            'au6b' => ['6- … unités de transformation (D/kg)', 0.028, 'q'],
            'au7' => ['7- Taxe pour la protection de l’environnement', 7, 'bt'],
            'au8a' => ['8- Fonds de transition énergétique : climatiseurs', null, 'm'],
            'au8b' => ['8- … lampes et tubes', null, 'm'],
            'au8c' => ['8- … produits énergivores', null, 'm'],
            'au9' => ['9- Jeux et concours par moyens de télécommunication', 40, 'bt'],
            'au10' => ['10- Fonds d’encouragement à la création littéraire et artistique', 1, 'bt'],
            'au11' => ['11- Fonds du repos biologique (pêche)', 1, 'bt'],
            'au12a' => ['12- Fonds de compensation : établissements touristiques, cafés, boissons', 3, 'bt'],
            'au12b' => ['12- … bars, discothèques, cabarets, pâtisseries', 5, 'bt'],
            'au13a' => ['13- Fonds des calamités agricoles : fruits et légumes', 1, 'bt'],
            'au13b' => ['13- … céréales', 1, 'bt'],
            'au13c' => ['13- … huile d’olive', 1, 'bt'],
            'au13d' => ['13- … produits de la pêche', 1, 'bt'],
            'au14' => ['14- Taxe de séjour dans les établissements touristiques', null, 'm'],
            'au15' => ['15- Fonds de soutien de la santé publique', 1, 'bt'],
            'au16' => ['16- Taxe sur le sucre (D/kg)', 0.1, 'q'],
            'au17a' => ['17- Dérivés du lait : beurre (D/kg)', 1.5, 'q'],
            'au17b' => ['17- … crème (D/kg)', 2, 'q'],
            'au17c' => ['17- … fromages (D/kg)', 3, 'q'],
            'au18a' => ['18- Sécurité sociale : recharge de téléphone mobile', null, 'm'],
            'au18b' => ['18- … location de voitures (D/jour)', 2, 'q'],
            'au19' => ['19- Fonds de promotion des personnes handicapées', 1, 'bt'],
        ],
        'tim' => [
            'tim1' => ['1- Factures et montants des services téléphoniques et internet', null, 'm'],
            'tim2' => ['2- Tickets de vente (nombre de tickets)', 0.1, 'q'],
            'tim3a' => ['3- Factures des grandes surfaces de 50 à 100 D (nombre)', 1.5, 'q'],
            'tim3b' => ['3- Factures des grandes surfaces de plus de 100 D (nombre)', 2, 'q'],
            'tim4' => ['4- Factures et autres documents (nombre)', 1, 'q'],
            'tim5' => ['4- Autres documents (montant)', null, 'm'],
        ],
        'tcl' => [
            'tcl_loc' => ['Taxe sur les établissements : chiffre d’affaires local brut', 0.2, 'bt'],
            'tcl_exp' => ['… chiffre d’affaires à l’exportation', 0.1, 'bt'],
            'tcl_hom' => ['… entreprises soumises à l’homologation des prix', 0.1, 'bt'],
            'tcl_is' => ['… calculée sur 25 % de l’impôt sur les sociétés', 25, 'bt'],
            'tcl_hotel' => ['Taxe hôtelière : chiffre d’affaires brut', 2, 'bt'],
            'tcl_lic' => ['Droit de licence sur les débits de boissons', null, 'm'],
        ],
    ];

    /** Toutes les rubriques : code => [libellé, taux, mode, sens, section]. */
    public static function rubriques(): array
    {
        $R = [];
        foreach (self::LIGNES as $s => $L) foreach ($L as $k => $def) $R[$k] = [$def[0], $def[1], $def[2], $def[3] ?? 'I', $s];
        return $R;
    }

    /** Rubrique : base, taux (saisi ou par défaut) et montant (saisi, sinon calculé). */
    public static function valeur(array $f, string $code): array
    {
        [, $t0, $mode] = self::rubriques()[$code];
        $x = (array) ($f[$code] ?? []);
        $b = (float) ($x['b'] ?? 0);
        $t = isset($x['t']) && $x['t'] !== '' && $x['t'] !== null ? (float) $x['t'] : (float) $t0;
        $calc = match ($mode) { 'bt', 'tm' => r3($b * $t / 100), 'q' => r3($b * $t), default => 0.0 };
        $m = isset($x['m']) && $x['m'] !== '' && $x['m'] !== null ? r3((float) $x['m']) : $calc;
        if ($mode === 'b') $m = 0.0;
        return ['b' => r3($b), 't' => $t, 'm' => $m, 'calc' => $calc];
    }

    /** Champs de l'ancien formulaire simplifié convertis en rubriques. */
    public static function convertir(array $f): array
    {
        if (! array_intersect(array_keys($f), ['ca19', 'ca13', 'ca7', 'tvac', 'rsal', 'rhon', 'rmar'])) return $f;
        $n = fn ($k) => (float) ($f[$k] ?? 0);
        $o = ['acte' => $f['acte'] ?? '0'];
        foreach (['ca7' => 'tva7', 'ca13' => 'tva13', 'ca19' => 'tva19'] as $a => $b) if ($n($a)) $o[$b] = ['b' => $n($a)];
        $o['tvd_al'] = ['m' => $n('tvad1')]; $o['tvd_eql'] = ['m' => $n('tvad2')]; $o['tva_prec'] = ['m' => $n('tvacr')];
        $o['inf_caexo'] = ['b' => $n('caex')]; $o['inf_exp'] = ['b' => $n('caxp')];
        $o['rs1'] = ['m' => $n('rsal')]; $o['rs3'] = ['m' => $n('css')]; $o['rs6'] = ['m' => $n('rhon')]; $o['rs17d'] = ['m' => $n('rmar')]; $o['rs28'] = ['m' => $n('raut')];
        $o['tfp2'] = ['m' => $n('tfp')]; $o['fop'] = ['m' => $n('fop')]; $o['tim5'] = ['m' => $n('tim')]; $o['au14'] = ['m' => $n('autr')];
        return array_filter($o, fn ($v) => ! is_array($v) || array_filter($v));
    }

    /**
     * Totaux : par section (montant dû), TVA (I, II, solde, crédit), pénalités et total à payer.
     * tvap : solde de TVA (positif à payer, négatif crédit à reporter) ; tot : total à payer.
     */
    public static function calcul(array $f): array
    {
        $f = self::convertir($f);
        $S = array_fill_keys(array_keys(self::SECTIONS), ['I' => 0.0, 'II' => 0.0, 'B' => 0.0]);
        $C = 0.0; $R = 0.0; $tcl = array_fill_keys(array_keys(self::TCL), 0.0);
        foreach (self::rubriques() as $k => [, , $mode, $sens, $s]) {
            if ($mode === 'b') continue;
            ['m' => $m, 'b' => $b] = self::valeur($f, $k);
            match ($sens) { 'II' => $S[$s]['II'] += $m, 'C' => $C += $m, 'R' => $R += $m, default => $S[$s]['I'] += $m };
            // Base de l'impôt : bases des rubriques dues, hors quantités et hors base de la CSS (celle des salaires, déjà comptée)
            if ($sens === 'I' && in_array($mode, ['bt', 'tm', 'bm'], true) && $k !== 'rs3') $S[$s]['B'] += $b;
            foreach (self::TCL as $c => $L) if (in_array($k, $L, true)) $tcl[$c] += $m;
        }
        $o['tcl'] = array_map('r3', $tcl);
        $o['sections'] = [];
        foreach ($S as $s => $x) {
            $solde = r3($x['I'] - $x['II']);
            if ($s === 'tva') {
                $solde = r3($solde - $C + $R);
                $o['tva_I'] = r3($x['I']); $o['tva_II'] = r3($x['II']); $o['tva_prec'] = r3($C);
                $o['tvap'] = $solde; $o['credit'] = max(0.0, -$solde);
            }
            $o['sections'][$s] = ['I' => r3($x['I']), 'II' => r3($x['II']), 'du' => max(0.0, $solde), 'pen' => r3((float) (($f['pen'] ?? [])[$s] ?? 0)), 'base' => r3($x['B'])];
        }
        $o['tot'] = r3(array_sum(array_map(fn ($x) => $x['du'] + $x['pen'], $o['sections'])));
        return $o;
    }

    /** Sections concernées (montant dû ou rubrique renseignée). */
    public static function concernees(array $f): array
    {
        $c = self::calcul($f);
        return array_keys(array_filter($c['sections'], fn ($x) => $x['I'] > 0 || $x['du'] > 0));
    }

    /** Cases cochées de l'entête : celles enregistrées, sinon déduites des montants déclarés. */
    public static function impots(array $f): array
    {
        if (isset($f['imp'])) return array_values(array_intersect(array_keys(self::IMPOTS), (array) $f['imp']));
        $c = self::calcul($f); $s = self::concernees($f);
        return array_values(array_filter(array_keys(self::IMPOTS), fn ($k) => self::IMPOTS[$k][2] === 'tcl' ? $c['tcl'][$k] > 0 : in_array($k, $s, true)));
    }
}
