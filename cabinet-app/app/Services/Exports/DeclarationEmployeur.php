<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Declaration;

/**
 * Déclaration d'employeur sur support magnétique (cahier des charges DGI « EMPCCA », année 2025).
 *
 * - DECEMP_AA : tableau récapitulatif, 51 enregistrements de 38 caractères (000 entête, 49 lignes de retenues, 999 total).
 * - ANXEMP_n_AA_1 : une annexe par fichier (n = 1 à 7), enregistrements de 399 caractères :
 *   ANXDEB00 (entête « En »), ANXBEN0n (bénéficiaires « Ln »), ANXFIN0n (totaux « Tn »).
 * Codage ASCII, CRLF ; alphanumérique cadré à gauche (espaces), numérique cadré à droite (zéros) ;
 * montants en millimes, positifs, sans signe ; taux 999V99 ; dates JJMMAAAA.
 *
 * Lignes d'annexe stockées dans Declaration::annexes['a1'…'a7'] ; chaque ligne garde aussi brut / ret / net
 * (montant servi, retenue, net) pour les récapitulatifs. L'entête du déposant et le tableau récapitulatif
 * sont dans Declaration::champs (acte, activite, ville, rue, num, cp ; r[code] = [a => assiette, r => retenue]).
 */
class DeclarationEmployeur extends FichierDgi
{
    public const ANNEXES = [
        'a1' => 'Annexe I : traitements, salaires, pensions et rentes viagères, contribution sociale de solidarité',
        'a2' => 'Annexe II : honoraires, commissions, courtages, loyers, jetons de présence… servis à des résidents',
        'a3' => 'Annexe III : revenus des capitaux mobiliers, intérêts des comptes spéciaux d’épargne et des prêts',
        'a4' => 'Annexe IV : montants servis à des personnes non résidentes ou non établies',
        'a5' => 'Annexe V : acquisitions de marchandises, matériels et services ≥ 1 000 D et autres retenues',
        'a6' => 'Annexe VI : ristournes, ventes aux forfaitaires, jeux, ventes aux distributeurs, espèces, boissons alcoolisées',
        'a7' => 'Annexe VII : montants payés pour autrui',
    ];

    /** Types d'identifiant admis par annexe (1 MF, 2 CIN, 3 carte de séjour, 4 non domicilié ni établi). */
    public const IDK = ['a1' => [2, 3], 'a2' => [1, 2], 'a3' => [1, 2, 3, 4], 'a4' => [3, 4], 'a5' => [1, 2, 3, 4], 'a6' => [1, 2, 3, 4], 'a7' => [1, 2, 3, 4]];

    public const TYPES_ID = ['1' => 'Matricule fiscal', '2' => 'Carte d’identité nationale', '3' => 'Carte de séjour', '4' => 'Non domicilié ni établi'];

    /**
     * Zones propres à chaque annexe, à partir de la position 239 de l'enregistrement bénéficiaire :
     * clé => [libellé, nature, longueur] ; nature : m (montant), t (taux %), n (entier), d (date), c (code), x (espaces réservés).
     */
    public const ZONES = [
        'a1' => [
            'sit' => ['Situation familiale', 'c', 1], 'enf' => ['Enfants à charge', 'n', 2], 'deb' => ['Début de la période', 'd', 8], 'fin' => ['Fin de la période', 'd', 8],
            'jours' => ['Durée (jours)', 'n', 3], 'imp' => ['Revenu imposable', 'm', 15], 'nat' => ['Avantages en nature', 'm', 15], 'brut' => ['Revenu brut imposable', 'm', 15],
            'reinv' => ['Revenu réinvesti', 'm', 15], 'ret' => ['Retenue (régime commun)', 'm', 15], 'ret_etr' => ['Retenue (étrangers, 20 %)', 'm', 15],
            'css' => ['Contribution sociale de solidarité', 'm', 15], 'net' => ['Net servi', 'm', 15], '_' => ['', 'x', 19],
        ],
        'a2' => [
            'nat' => ['Type de montant', 'c', 1], 'm_res' => ['Honoraires, commissions, courtages, loyers, BNC, performance', 'm', 15],
            'm_reel' => ['Honoraires servis aux PM et PP au régime réel', 'm', 15], 'm_cons' => ['Jetons de présence, conseils, actions et parts sociales', 'm', 15],
            'm_occ' => ['Travail occasionnel ou accidentel', 'm', 15], 'm_pvi' => ['Plus-value immobilière (prix dans l’acte)', 'm', 15],
            'm_hotel' => ['Loyers des hôtels', 'm', 15], 'm_art' => ['Artistes et créateurs', 'm', 15], 'ret_tva' => ['Retenue TVA (≥ 1 000 D, secteur public)', 'm', 15],
            'ret' => ['Retenue opérée', 'm', 15], 'net' => ['Net servi', 'm', 15], '_' => ['', 'x', 10],
        ],
        'a3' => [
            'm_epar' => ['Intérêts des comptes spéciaux d’épargne', 'm', 15], 'm_cap' => ['Intérêts des autres capitaux mobiliers', 'm', 15],
            'm_pret' => ['Intérêts des prêts (banques non établies)', 'm', 15], 'ret' => ['Retenue opérée', 'm', 15], 'net' => ['Net servi', 'm', 15], '_' => ['', 'x', 86],
        ],
        'a4' => [
            'nat' => ['Type de montant', 'c', 1], 't1' => ['Taux', 't', 5], 'm1' => ['Honoraires, commissions, courtages, loyers, autres revenus', 'm', 15],
            't2' => ['Taux', 't', 5], 'm2' => ['Construction, montage, contrôle (≤ 6 mois)', 'm', 15], 't3' => ['Taux', 't', 5], 'm3' => ['Plus-value immobilière', 'm', 15],
            't4' => ['Taux', 't', 5], 'm4' => ['Plus-value de cession d’actions et parts', 'm', 15], 't5' => ['Taux', 't', 5], 'm5' => ['Valeurs mobilières, jetons de présence', 'm', 15],
            'm6' => ['Paradis fiscal', 'm', 15], 'ret_tva' => ['Retenue TVA 100 % (non établis)', 'm', 15], 'ret' => ['Retenue opérée', 'm', 15], 'net' => ['Net servi', 'm', 15],
        ],
        'a5' => [
            'm_10' => ['Acquisitions ≥ 1 000 D – entreprises à l’IS de 10 %', 'm', 15], 'm_15' => ['Acquisitions ≥ 1 000 D – entreprises à l’IS de 15 % / 20 %', 'm', 15],
            'm_23' => ['Acquisitions ≥ 1 000 D – entreprises unipersonnelles (déduction 2/3)', 'm', 15], 'm_aut' => ['Acquisitions ≥ 1 000 D – autres entreprises', 'm', 15],
            'ret_tva' => ['Retenue TVA sur acquisitions ≥ 1 000 D', 'm', 15], 'ret_livr' => ['Retenue 3 % (livraison de ventes en ligne)', 'm', 15],
            'ret' => ['Retenue opérée', 'm', 15], 'net' => ['Net servi', 'm', 15], '_' => ['', 'x', 41],
        ],
        'a6' => [
            'nat' => ['Type de ristournes', 'c', 1], 'rist' => ['Ristournes commerciales et non commerciales', 'm', 15], 'v_forf' => ['Ventes aux forfaitaires', 'm', 15],
            'av_forf' => ['Avance due (forfaitaires)', 'm', 15], 'jeux' => ['Gains des jeux de pari, de hasard et de loterie', 'm', 15], 'ret_jeux' => ['Retenue (jeux)', 'm', 15],
            'v_distr' => ['Ventes aux distributeurs (≤ 20 000 D/an)', 'm', 15], 'ret_distr' => ['Retenue (distributeurs)', 'm', 15], 'especes' => ['Montants perçus en espèces', 'm', 15],
            'v_alc' => ['Ventes de vin, bière et boissons alcoolisées', 'm', 15], 'av_alc' => ['Avance due (boissons alcoolisées)', 'm', 15], '_' => ['', 'x', 10],
        ],
        'a7' => [
            'nat' => ['Type du montant payé pour autrui', 'c', 2], 'mt' => ['Montant payé', 'm', 15], 'ret' => ['Retenue à la source', 'm', 15], 'net' => ['Net servi', 'm', 15], '_' => ['', 'x', 114],
        ],
    ];

    /** Valeurs des zones codées. */
    public const CODES = [
        'a1.sit' => ['1' => 'Célibataire', '2' => 'Marié(e)', '3' => 'Divorcé(e)', '4' => 'Veuf / veuve'],
        'a2.nat' => ['1' => 'Honoraires', '2' => 'Commissions', '3' => 'Courtages', '4' => 'Loyers', '5' => 'Rémunérations des activités non commerciales', '6' => 'Rémunérations de la performance', '0' => 'Charge à payer ou sans retenue'],
        'a4.nat' => ['1' => 'Honoraires', '2' => 'Commissions', '3' => 'Courtages', '4' => 'Loyers', '5' => 'Activités non commerciales', '6' => 'Autres revenus',
            '7' => 'Sans déclaration d’existence – paradis fiscal', '8' => 'Sans déclaration d’existence – autres établissements stables', '9' => 'Rémunérations de la performance', '0' => 'Sans retenue'],
        'a6.nat' => ['1' => 'Ristournes commerciales', '2' => 'Ristournes non commerciales', '0' => 'Pas de ristournes'],
        'a7.nat' => ['1' => 'Traitements, salaires, pensions', '2' => 'Honoraires', '3' => 'Commissions', '4' => 'Courtages', '5' => 'Loyers', '6' => 'Activités non commerciales',
            '7' => 'Honoraires (PM et PP au régime réel)', '8' => 'Artistes et créateurs', '15' => 'Intérêts des comptes spéciaux d’épargne', '16' => 'Intérêts des prêts (banques non établies)',
            '17' => 'Autres capitaux mobiliers', '19' => 'Non-résidents : construction, montage, contrôle (≤ 6 mois)', '20' => 'Valeurs mobilières, jetons de présence (non-résidents)',
            '21' => 'Paradis fiscal', '22' => 'Acquisitions ≥ 1 000 D', '23' => 'Montants ≥ 1 000 D – autres opérations', '24' => 'Retenue TVA – établissements publics',
            '25' => 'Retenue TVA – non établis', '26' => 'Redevance de compensation', '27' => 'Honoraires (PP hors régime réel)', '28' => 'Avance sur boissons alcoolisées', '29' => 'Livraison de ventes en ligne'],
    ];

    /** Lignes 01 à 49 du récapitulatif : code => [libellé, taux % (null : zone réservée 00000), enregistrement CSS sans taux]. */
    public const RECAP = [
        '010' => ['Traitements, salaires, pensions et rentes viagères (régime commun)', null],
        '170' => ['Traitements, salaires et avantages payés aux étrangers', 20],
        '300' => ['Contribution sociale de solidarité sur les traitements et salaires', 'css'],
        '021' => ['Commissions, courtages, loyers, rémunérations BNC – personnes physiques résidentes', 10],
        '022' => ['Commissions, courtages, loyers, rémunérations BNC – personnes morales résidentes', 10],
        '023' => ['Commissions, courtages, loyers, rémunérations – personnes physiques non domiciliées', 15],
        '024' => ['Commissions, courtages, loyers, rémunérations – personnes morales non domiciliées', 15],
        '027' => ['Honoraires – personnes physiques hors régime réel', 10],
        '030' => ['Honoraires – personnes morales et personnes physiques au régime réel', 3],
        '400' => ['Rémunérations des artistes et créateurs', 5],
        '040' => ['Loyers des hôtels', 5],
        '260' => ['Rémunérations de la performance dans la prestation de services', 10],
        '060' => ['Intérêts des comptes spéciaux d’épargne, prêts budgétaires, bons du Trésor', 20],
        '071' => ['Autres capitaux mobiliers – personnes physiques résidentes', 20],
        '072' => ['Autres capitaux mobiliers – personnes morales résidentes', 20],
        '073' => ['Autres capitaux mobiliers – personnes physiques non résidentes', 20],
        '074' => ['Autres capitaux mobiliers – personnes morales non résidentes', 20],
        '241' => ['Actions et parts sociales – personnes physiques résidentes', 10],
        '242' => ['Actions et parts sociales – non résidents', 10],
        '091' => ['Jetons de présence et rémunérations des conseils – PP résidentes', 20],
        '092' => ['Jetons de présence et rémunérations des conseils – PM résidentes', 20],
        '093' => ['Jetons de présence et rémunérations des conseils – PP non résidentes', 20],
        '094' => ['Jetons de présence et rémunérations des conseils – PM non résidentes', 20],
        '100' => ['Travail occasionnel ou accidentel', 15],
        '110' => ['Intérêts des prêts – banques non établies en Tunisie', 10],
        '121' => ['Plus-value immobilière – résidents et établis', 2.5],
        '122' => ['Plus-value immobilière – personnes physiques non résidentes', 2.5],
        '123' => ['Plus-value immobilière – personnes morales non établies', 15],
        '132' => ['Acquisitions ≥ 1 000 D – entreprises à l’IS de 20 %', 1],
        '133' => ['Acquisitions ≥ 1 000 D – entreprises à l’IS de 10 %', 0.5],
        '134' => ['Acquisitions ≥ 1 000 D – entreprises unipersonnelles (déduction 2/3)', 0.5],
        '135' => ['Acquisitions ≥ 1 000 D – autres entreprises', 1.5],
        '140' => ['Retenue TVA – montants ≥ 1 000 D payés par l’État et le secteur public', 25],
        '150' => ['Retenue TVA – opérations avec des personnes non établies', 100],
        '160' => ['Non-résidents établis : construction, montage, contrôle (≤ 6 mois)', 0],
        '270' => ['Sans déclaration d’existence – résidents d’un paradis fiscal', 25],
        '271' => ['Sans déclaration d’existence – autres établissements stables', 15],
        '200' => ['Avance sur les ventes aux forfaitaires', 1],
        '500' => ['Avance sur les ventes de vin, bière et boissons alcoolisées', 5],
        '191' => ['Plus-value de cession d’actions – PP non résidentes', 10],
        '192' => ['Plus-value de cession d’actions – PM non résidentes', 20],
        '051' => ['Autres rémunérations – personnes physiques non établies', 15],
        '052' => ['Autres rémunérations – personnes morales non établies', 15],
        '220' => ['Rémunérations servies dans un paradis fiscal', 25],
        '250' => ['Commissions des distributeurs d’opérateurs téléphoniques – PP', 1.5],
        '251' => ['Commissions des distributeurs d’opérateurs téléphoniques – PM', 1],
        '280' => ['Jeux de pari, de hasard et de loterie', 25],
        '290' => ['Ventes aux intervenants de la distribution (≤ 20 000 D/an)', 3],
        '600' => ['Livraison de ventes en ligne sans carte d’identification fiscale', 3],
    ];

    // ---------- Noms des fichiers ----------

    public static function prefixe(): string { return 'DECEMP'; }

    public static function nomRecap(int $annee): string { return 'DECEMP_'.substr((string) $annee, -2); }

    public static function nomAnnexe(string $ax, int $annee): string { return 'ANXEMP_'.substr($ax, 1).'_'.substr((string) $annee, -2).'_1'; }

    // ---------- Lignes ----------

    /** Ligne normalisée : zones numériques, codes, et montants de synthèse brut / ret / net. */
    public static function normaliser(string $ax, array $l): array
    {
        if (! isset(self::ZONES[$ax])) return $l;
        // Lignes saisies avant le cahier des charges : montant brut repris dans la zone principale
        $principal = ['a1' => 'imp', 'a2' => 'm_res', 'a3' => 'm_cap', 'a4' => 'm1', 'a5' => 'm_aut', 'a6' => 'rist', 'a7' => 'mt'][$ax];
        $montants = array_keys(array_filter(self::ZONES[$ax], fn ($z) => $z[1] === 'm'));
        $aucun = ! array_filter($montants, fn ($k) => isset($l[$k]) && $l[$k] !== '' && $l[$k] !== null && ! in_array($k, ['ret', 'net', 'brut'], true));
        if ($ax === 'a1') { if (! isset($l['imp']) && isset($l['brut'])) $l['imp'] = $l['brut']; }
        elseif ($aucun && isset($l['brut'])) $l[$principal] = $l['brut'];
        foreach (self::ZONES[$ax] as $k => [, $nat]) {
            if ($nat === 'm' || $nat === 't') $l[$k] = r3((float) ($l[$k] ?? 0));
            elseif ($nat === 'n') $l[$k] = (int) ($l[$k] ?? 0);
            elseif ($nat === 'c') $l[$k] = (string) ($l[$k] ?? '');
        }
        if ($ax === 'a1') {
            $l['brut'] = r3($l['imp'] + $l['nat']);
            $l['net'] = $l['net'] ?: r3($l['brut'] - $l['ret'] - $l['ret_etr'] - $l['css']);
        }
        // Synthèse (récapitulatifs, impression) : brut = montant servi, retenues = total des retenues ; la zone « ret » reste celle du cahier des charges
        $l['brut'] = self::servi($ax, $l);
        $l['retenues'] = self::retenues($ax, $l);
        if ($ax === 'a6') $l['ret'] = $l['retenues'];
        if (! in_array($ax, ['a1', 'a6'], true) && ($l['net'] ?? 0) == 0 && $l['brut'] > 0) $l['net'] = r3($l['brut'] - $l['retenues']);
        $l['net'] = r3((float) ($l['net'] ?? ($ax === 'a6' ? $l['brut'] - $l['retenues'] : 0)));
        $l['taux'] = $l['brut'] > 0 && $ax !== 'a1' ? round($l['retenues'] / $l['brut'] * 100, 2) : null;
        return $l;
    }

    /** Montant servi (brut) d'une ligne. */
    public static function servi(string $ax, array $l): float
    {
        $k = ['a1' => ['brut'], 'a2' => ['m_res', 'm_reel', 'm_cons', 'm_occ', 'm_pvi', 'm_hotel', 'm_art'], 'a3' => ['m_epar', 'm_cap', 'm_pret'],
            'a4' => ['m1', 'm2', 'm3', 'm4', 'm5', 'm6'], 'a5' => ['m_10', 'm_15', 'm_23', 'm_aut'], 'a6' => ['rist', 'v_forf', 'jeux', 'v_distr', 'especes', 'v_alc'], 'a7' => ['mt']][$ax];
        return r3(array_sum(array_map(fn ($x) => (float) ($l[$x] ?? 0), $k)));
    }

    /** Total des retenues d'une ligne (impôt, TVA, CSS, avances). */
    public static function retenues(string $ax, array $l): float
    {
        $k = ['a1' => ['ret', 'ret_etr', 'css'], 'a2' => ['ret', 'ret_tva'], 'a3' => ['ret'], 'a4' => ['ret', 'ret_tva'], 'a5' => ['ret', 'ret_tva', 'ret_livr'],
            'a6' => ['av_forf', 'ret_jeux', 'ret_distr', 'av_alc'], 'a7' => ['ret']][$ax];
        return r3(array_sum(array_map(fn ($x) => (float) ($l[$x] ?? 0), $k)));
    }

    /** Bénéficiaire personne morale : matricule de catégorie M, ou case cochée pour un non-résident. */
    public static function morale(array $l): bool
    {
        if ((string) ($l['idk'] ?? '') === '1') return (self::matricule($l['id'] ?? '')['cat'] ?? '') === 'M';
        return (string) ($l['idk'] ?? '') === '4' && ! empty($l['pm']);
    }

    // ---------- Tableau récapitulatif ----------

    /** Assiettes et retenues des lignes 01 à 49 déduites des annexes : code => [a, r]. */
    public static function recapitulatif(array $annexes): array
    {
        $R = array_fill_keys(array_keys(self::RECAP), ['a' => 0.0, 'r' => 0.0]);
        $add = function (string $code, float $a, float $r) use (&$R) { $R[$code]['a'] += $a; $R[$code]['r'] += $r; };
        // Répartit la retenue d'une ligne entre ses montants, au prorata montant × taux
        $repartir = function (array $parts, float $ret) use ($add) {
            $parts = array_filter($parts, fn ($p) => $p[1] > 0);
            if (! $parts) return;
            $poids = array_map(fn ($p) => $p[1] * max(self::RECAP[$p[0]][1] ?: 1, 0.01), $parts);
            $s = array_sum($poids);
            foreach (array_values($parts) as $i => [$code, $mt]) $add($code, $mt, $s > 0 ? $ret * array_values($poids)[$i] / $s : 0);
        };
        foreach ($annexes as $ax => $L) {
            if (! isset(self::ZONES[$ax])) continue;
            foreach ($L as $l) {
                $l = self::normaliser($ax, $l);
                $pm = self::morale($l);
                $nonres = in_array((string) ($l['idk'] ?? ''), ['3', '4'], true);
                switch ($ax) {
                    case 'a1':
                        $l['ret_etr'] > 0 ? $add('170', $l['brut'], $l['ret_etr']) : $add('010', $l['brut'], $l['ret']);
                        $add('300', 0, $l['css']);
                        break;
                    case 'a2':
                        if ((string) $l['nat'] === '0' && $l['ret'] == 0 && $l['ret_tva'] == 0) break;
                        $res = match ((string) $l['nat']) { '1' => '027', '6' => '260', default => $pm ? '022' : '021' };
                        $repartir([[$res, $l['m_res']], ['030', $l['m_reel']], [$pm ? '092' : '091', $l['m_cons']], ['100', $l['m_occ']], ['121', $l['m_pvi']], ['040', $l['m_hotel']], ['400', $l['m_art']]], $l['ret']);
                        if ($l['ret_tva'] > 0) $add('140', r3($l['ret_tva'] / 0.25), $l['ret_tva']);
                        break;
                    case 'a3':
                        $repartir([['060', $l['m_epar']], [$nonres ? ($pm ? '074' : '073') : ($pm ? '072' : '071'), $l['m_cap']], ['110', $l['m_pret']]], $l['ret']);
                        break;
                    case 'a4':
                        $m1 = match ((string) $l['nat']) { '6' => $pm ? '052' : '051', '7' => '270', '8' => '271', '9' => '260', default => $pm ? '024' : '023' };
                        $repartir([[$m1, $l['m1']], ['160', $l['m2']], [$pm ? '123' : '122', $l['m3']], [$pm ? '192' : '191', $l['m4']], [$pm ? '094' : '093', $l['m5']], ['220', $l['m6']]], $l['ret']);
                        if ($l['ret_tva'] > 0) $add('150', $l['ret_tva'], $l['ret_tva']);
                        break;
                    case 'a5':
                        $repartir([['133', $l['m_10']], ['132', $l['m_15']], ['134', $l['m_23']], ['135', $l['m_aut']]], $l['ret']);
                        if ($l['ret_tva'] > 0) $add('140', r3($l['ret_tva'] / 0.25), $l['ret_tva']);
                        if ($l['ret_livr'] > 0) $add('600', r3($l['ret_livr'] / 0.03), $l['ret_livr']);
                        break;
                    case 'a6':
                        $add('200', $l['v_forf'], $l['av_forf']); $add('280', $l['jeux'], $l['ret_jeux']);
                        $add('290', $l['v_distr'], $l['ret_distr']); $add('500', $l['v_alc'], $l['av_alc']);
                        break;
                }
            }
        }
        return array_map(fn ($x) => ['a' => r3($x['a']), 'r' => r3($x['r'])], $R);
    }

    /** Lignes du récapitulatif retenues pour le fichier : saisies (champs r) sinon calculées depuis les annexes. */
    public static function lignesRecap(Declaration $d): array
    {
        $calc = self::recapitulatif($d->annexes ?? []);
        $saisi = (array) (($d->champs ?? [])['r'] ?? []);
        $R = [];
        foreach (self::RECAP as $code => $def) {
            $s = $saisi[$code] ?? null;
            $R[$code] = $s !== null ? ['a' => r3((float) ($s['a'] ?? 0)), 'r' => r3((float) ($s['r'] ?? 0))] : $calc[$code];
        }
        return $R;
    }

    public static function totalRetenues(Declaration $d): float
    {
        return r3(array_sum(array_column(self::lignesRecap($d), 'r')));
    }

    // ---------- Contrôles ----------

    /** Anomalies d'une ligne d'annexe (règles du cahier des charges). */
    public static function controlesLigne(string $ax, array $l, int $annee): array
    {
        $E = [];
        $l = self::normaliser($ax, $l);
        $idk = (string) ($l['idk'] ?? '');
        if (! in_array((int) $idk, self::IDK[$ax], true)) $E[] = 'Type d’identifiant non admis dans cette annexe ('.implode(', ', array_map(fn ($k) => self::TYPES_ID[$k], self::IDK[$ax])).').';
        $id = strtoupper(preg_replace('/[\s\/.\-]/', '', (string) ($l['id'] ?? '')));
        if ($idk === '1') {
            $p = self::matricule($id);
            if (! $p || $p['tva'] === '') $E[] = 'Matricule fiscal du bénéficiaire sur 13 caractères attendu (ex. 1234567ABM000).';
            elseif (! self::cleValide($p['num'], $p['cle'])) $E[] = 'Clé du matricule fiscal du bénéficiaire incorrecte.';
            elseif ($p['etab'] !== '000') $E[] = 'Établissement secondaire du bénéficiaire : 000 attendu.';
        } elseif (in_array($idk, ['2', '3', '4'], true) && ! preg_match('/^\d{1,8}$/', $id)) {
            $E[] = 'Identifiant du bénéficiaire : 8 chiffres au plus.';
        }
        if (trim((string) ($l['nom'] ?? '')) === '') $E[] = 'Nom du bénéficiaire manquant.';
        foreach (self::ZONES[$ax] as $k => [$lib, $nat, $len]) {
            if ($nat === 'm' && ($l[$k] < 0 || strlen((string) self::millimes($l[$k])) > $len)) $E[] = "$lib : montant invalide.";
        }
        if ($ax === 'a1') {
            if (! isset(self::CODES['a1.sit'][(string) ($l['sit'] ?? '')])) $E[] = 'Situation familiale à renseigner (1 à 4).';
            $deb = $l['deb'] ?? null; $fin = $l['fin'] ?? null;
            if (! $deb || ! $fin) $E[] = 'Période de travail à renseigner.';
            elseif ($deb > $fin || substr($deb, 0, 4) != $annee || substr($fin, 0, 4) != $annee) $E[] = "Période de travail hors de l’année $annee ou inversée.";
            if ($l['jours'] < 1 || $l['jours'] > 366) $E[] = 'Durée de la période (jours) invalide.';
            if (abs($l['brut'] - $l['imp'] - $l['nat']) > 0.001) $E[] = 'Revenu brut imposable ≠ revenu imposable + avantages en nature.';
        }
        if ($ax === 'a2' && (((string) $l['nat'] === '0') !== ($l['ret'] == 0))) $E[] = 'Type de montant 0 si et seulement si la retenue est nulle (charge à payer).';
        if ($ax === 'a2' && ! isset(self::CODES['a2.nat'][(string) $l['nat']])) $E[] = 'Type de montant invalide.';
        if ($ax === 'a4' && (((string) $l['nat'] === '0') !== ($l['ret'] == 0))) $E[] = 'Type de montant 0 si et seulement si la retenue est nulle.';
        if ($ax === 'a6' && (((string) $l['nat'] === '0') !== ($l['rist'] == 0))) $E[] = 'Type de ristournes 0 si et seulement si les ristournes sont nulles.';
        if ($ax === 'a7' && ! isset(self::CODES['a7.nat'][(string) $l['nat']])) $E[] = 'Type du montant payé pour autrui à renseigner.';
        return $E;
    }

    /** Anomalies de l'entête du déposant. */
    public static function controlesEntete(Client $c, array $e): array
    {
        $E = [];
        if ($m = self::erreurMatricule($c->matricule_fiscal)) $E[] = 'Déclarant : '.$m;
        elseif (self::matricule($c->matricule_fiscal)['etab'] !== '000') $E[] = 'Déclarant : établissement secondaire 000 attendu.';
        if (trim((string) ($e['activite'] ?? '')) === '') $E[] = 'Activité du déclarant à renseigner.';
        if (trim((string) ($e['ville'] ?? '')) === '' || trim((string) ($e['rue'] ?? '')) === '') $E[] = 'Adresse du déclarant (ville et rue) à renseigner.';
        if (! preg_match('/^\d{4}$/', (string) ($e['cp'] ?? ''))) $E[] = 'Code postal du déclarant (4 chiffres) à renseigner.';
        return $E;
    }

    public static function controles(array $l, array $entete): array
    {
        return self::controlesLigne($entete['ax'] ?? 'a1', $l, (int) ($entete['annee'] ?? now()->year));
    }

    // ---------- Fichiers ----------

    private static function mfDeclarant(Client $c): string
    {
        $p = self::matricule($c->matricule_fiscal);
        return $p ? $p['num'].$p['cle'].$p['cat'].$p['etab'] : str_pad(substr(preg_replace('/\D/', '', (string) $c->matricule_fiscal), 0, 7), 7, '0', STR_PAD_LEFT).'  000';
    }

    private static function idBeneficiaire(array $l): string
    {
        $id = strtoupper(preg_replace('/[\s\/.\-]/', '', (string) ($l['id'] ?? '')));
        if ((string) ($l['idk'] ?? '') === '1') return self::x(($p = self::matricule($id)) ? $p['num'].$p['cle'].$p['tva'].$p['cat'].$p['etab'] : $id, 13);
        return self::n(preg_replace('/\D/', '', $id), 8).'     ';
    }

    private static function montant($v): string { return self::n(max(0, self::millimes($v)), 15); }

    /** Fichier récapitulatif DECEMP_AA. */
    public static function fichier(Client $c, Declaration $d): string
    {
        $an = (int) $d->periode;
        $presence = '';
        foreach (array_keys(self::ANNEXES) as $ax) $presence .= empty($d->annexes[$ax]) ? '1' : '0';
        $L = ['000'.self::mfDeclarant($c).$an.$presence.str_repeat(' ', 12)];
        foreach (self::lignesRecap($d) as $code => $x) {
            $taux = self::RECAP[$code][1];
            $L[] = $taux === 'css' ? $code.str_repeat(' ', 20).self::montant($x['r'])
                : $code.self::montant($x['a']).($taux === null ? '00000' : self::taux($taux)).self::montant($x['r']);
        }
        $L[] = '999'.str_repeat(' ', 20).self::montant(self::totalRetenues($d));
        return implode("\r\n", $L)."\r\n";
    }

    /** Fichier d'une annexe ANXEMP_n_AA_1. */
    public static function fichierAnnexe(Client $c, Declaration $d, string $ax): string
    {
        $an = (int) $d->periode; $n = substr($ax, 1); $mf = self::mfDeclarant($c).$an;
        $e = (array) ($d->champs ?? []);
        $lignes = array_map(fn ($l) => self::normaliser($ax, $l), array_values($d->annexes[$ax] ?? []));
        $L = ['E'.$n.$mf.'An'.$n.self::n((int) ($e['acte'] ?? 0), 1).self::n(count($lignes), 6).self::x($c->nom, 40).self::x($e['activite'] ?? '', 40)
            .self::x($e['ville'] ?? '', 40).self::x($e['rue'] ?? '', 72).self::n(preg_replace('/\D/', '', (string) ($e['num'] ?? '')), 4).self::n(preg_replace('/\D/', '', (string) ($e['cp'] ?? '')), 4).str_repeat(' ', 171)];
        $T = [];
        foreach ($lignes as $i => $l) {
            $s = 'L'.$n.$mf.self::n($i + 1, 6).self::n((int) ($l['idk'] ?? 0), 1).self::idBeneficiaire($l).self::x($l['nom'] ?? '', 40)
                .self::x($l['act'] ?? '', 40).self::x($l['adr'] ?? '', 120);
            foreach (self::ZONES[$ax] as $k => [, $nat, $len]) {
                $s .= match ($nat) {
                    'm' => self::montant($l[$k] ?? 0), 't' => self::taux($l[$k] ?? 0), 'n', 'c' => self::n((int) ($l[$k] ?? 0), $len),
                    'd' => self::date($l[$k] ?? null), default => str_repeat(' ', $len),
                };
                if ($nat === 'm') $T[$k] = ($T[$k] ?? 0) + self::millimes($l[$k] ?? 0);
            }
            $L[] = $s;
        }
        $tot = fn (array $k) => implode('', array_map(fn ($x) => self::n($T[$x] ?? 0, 15), $k));
        $fin = 'T'.$n.$mf.match ($ax) {
            'a1' => str_repeat(' ', 242).$tot(['imp', 'nat', 'brut', 'reinv', 'ret', 'ret_etr', 'css', 'net']).str_repeat(' ', 19),
            'a2' => str_repeat(' ', 221).$tot(['m_res', 'm_reel', 'm_cons', 'm_occ', 'm_pvi', 'm_hotel', 'm_art', 'ret_tva', 'ret', 'net']).str_repeat(' ', 10),
            'a3' => str_repeat(' ', 220).$tot(['m_epar', 'm_cap', 'm_pret', 'ret', 'net']).str_repeat(' ', 86),
            'a4' => str_repeat(' ', 221).'00000'.$tot(['m1']).'00000'.$tot(['m2']).'00000'.$tot(['m3']).'00000'.$tot(['m4']).'00000'.$tot(['m5']).$tot(['m6', 'ret_tva', 'ret', 'net']),
            'a5' => str_repeat(' ', 220).$tot(['m_10', 'm_15', 'm_23', 'm_aut', 'ret_tva', 'ret_livr', 'ret', 'net']).str_repeat(' ', 41),
            'a6' => str_repeat(' ', 221).$tot(['rist', 'v_forf', 'av_forf', 'jeux', 'ret_jeux', 'v_distr', 'ret_distr', 'especes', 'v_alc', 'av_alc']).str_repeat(' ', 10),
            'a7' => str_repeat(' ', 222).$tot(['mt', 'ret', 'net']).str_repeat(' ', 114),
        };
        $L[] = $fin;
        return implode("\r\n", $L)."\r\n";
    }

    /** Archive ZIP : DECEMP_AA et les annexes comportant des lignes ; renvoie le chemin du fichier temporaire. */
    public static function archive(Client $c, Declaration $d): string
    {
        $an = (int) $d->periode;
        $chemin = tempnam(sys_get_temp_dir(), 'emp');
        $z = new \ZipArchive();
        $z->open($chemin, \ZipArchive::OVERWRITE);
        $z->addFromString(self::nomRecap($an), self::fichier($c, $d));
        foreach (array_keys(self::ANNEXES) as $ax) if (! empty($d->annexes[$ax])) $z->addFromString(self::nomAnnexe($ax, $an), self::fichierAnnexe($c, $d, $ax));
        $z->close();
        return $chemin;
    }
}
