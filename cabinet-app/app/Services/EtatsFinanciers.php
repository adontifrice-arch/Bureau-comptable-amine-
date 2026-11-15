<?php

namespace App\Services;

use App\Models\Client;

/**
 * États financiers complets (système comptable des entreprises, NC 01 — modèle autorisé), établis depuis la balance :
 * bilan (actif A1–A9, capitaux propres et passifs CP1–CP6), état de résultat (R1–R8…), état des flux de trésorerie
 * (méthode indirecte, à partir des variations du bilan) et notes détaillant chaque rubrique (N, N-1, variation).
 */
class EtatsFinanciers
{
    /** Libellés des rubriques ; « note » : code de la note de détail. */
    public const RUBRIQUES = [
        'A1' => 'Immobilisations incorporelles', 'A2' => 'Immobilisations corporelles', 'A3' => 'Immobilisations financières',
        'A4' => 'Autres actifs non courants', 'A5' => 'Stocks', 'A6' => 'Clients et comptes rattachés', 'A7' => 'Autres actifs courants',
        'A8' => 'Placements et autres actifs financiers', 'A9' => 'Liquidités et équivalents de liquidités',
        'CP1' => 'Capitaux propres', 'CP2' => 'Emprunts', 'CP3' => 'Autres passifs financiers et provisions', 'CP4' => 'Fournisseurs et comptes rattachés',
        'CP5' => 'Autres passifs courants', 'CP6' => 'Concours bancaires et autres passifs financiers',
        'R1' => 'Revenus', 'R2' => 'Autres produits d’exploitation', 'R3' => 'Production immobilisée', 'R4' => 'Achats de marchandises consommés',
        'R5' => 'Achats d’approvisionnements consommés', 'R6' => 'Charges de personnel', 'R7' => 'Dotations aux amortissements et aux provisions',
        'R8' => 'Autres charges d’exploitation',
    ];

    /** Comptes regroupés à deux chiffres dans les notes (tiers, services, charges et produits divers) ; les autres à trois chiffres. */
    private const GROUPES_DEUX = ['42', '43', '44', '45', '46', '47', '48', '53', '54', '55', '58', '61', '62', '63', '65', '66', '67', '73', '74', '75', '77', '78', '79'];

    /** Rubrique d'un compte de bilan selon son solde ($s : débiteur > 0) : [clé, montant affiché]. */
    private static function bilan(string $c, float $s): ?array
    {
        $p2 = substr($c, 0, 2); $p3 = substr($c, 0, 3);
        switch ($c[0]) {
            case '1':
                return match (true) {
                    $p2 === '10' => ['capital', -$s], $p2 === '11' => ['reserves', -$s], $p2 === '14' => ['acp', -$s],
                    $p2 === '12' || $p2 === '13' => ['report', -$s], $p2 === '15' => ['provisions', -$s], $p2 === '16' => ['emprunts', -$s],
                    default => ['apf', -$s],                                                           // 17, 18
                };
            case '2':
                return match (true) {
                    in_array($p3, ['281', '291'], true) => ['A1_amort', -$s],
                    in_array($p3, ['295', '296', '297'], true) => ['A3_amort', -$s],
                    $p2 === '28' || $p2 === '29' => ['A2_amort', -$s],
                    $p2 === '21' => ['A1', $s], $p2 === '25' || $p2 === '26' => ['A3', $s], $p2 === '27' => ['A4', $s],
                    default => ['A2', $s],                                                             // 22, 23, 24
                };
            case '3':
                return $p2 === '39' ? ['A5_amort', -$s] : ['A5', $s];
            case '4':
                if ($p2 === '49') return [$p3 === '491' ? 'A6_amort' : 'A7_amort', -$s];
                if ($s >= 0) return [$p2 === '41' ? 'A6' : 'A7', $s];
                return [$p2 === '40' ? 'CP4' : 'CP5', -$s];
            case '5':
                if ($p2 === '59') return ['A8_amort', -$s];
                if ($s >= 0) return [in_array($p2, ['50', '51', '52'], true) ? 'A8' : 'A9', $s];
                return ['CP6', -$s];
        }
        return null;
    }

    /** Rubrique d'un compte de gestion : [clé, montant affiché] (produits positifs dans leurs rubriques, charges positives dans les leurs). */
    private static function resultat(string $c, float $s): array
    {
        $p2 = substr($c, 0, 2); $p3 = substr($c, 0, 3); $p4 = substr($c, 0, 4);
        if ($c[0] === '7') {
            return match (true) {
                $p2 === '70' => ['R1', -$s], $p2 === '72' => ['R3', -$s], $p2 === '71' => ['vspf', $s],
                $p3 === '736' => ['ago', -$s], $p2 === '73' || $p2 === '74' || $p2 === '79' => ['R2', -$s],
                $p3 === '781' => ['R7', $s],                                                           // reprises : en moins des dotations
                $p3 === '754' || $p3 === '757' => ['pp', -$s], $p2 === '75' || $p3 === '786' => ['cfn', $s],
                $p2 === '77' => ['ext', -$s],
                default => ['R2', -$s],
            };
        }
        return match (true) {
            in_array($p3, ['607'], true) || in_array($p4, ['6037', '6097'], true) => ['R4', $s],
            in_array($p3, ['601', '602', '608'], true) || in_array($p4, ['6031', '6032', '6091', '6092', '6098'], true) => ['R5', $s],
            $p2 === '64' => ['R6', $s], $p3 === '686' => ['cfn', $s], $p2 === '68' => ['R7', $s],
            $p2 === '65' => ['cfn', $s], $p2 === '63' => ['apo', $s],
            $p3 === '691' => ['is', $s], $p3 === '695' => ['ais', $s], $p3 === '697' || $p2 === '67' => ['ext', -$s],
            default => ['R8', $s],                                                                     // 604, 605, 606, 61, 62, 66
        };
    }

    private static function groupe(string $c): string
    {
        return in_array(substr($c, 0, 2), self::GROUPES_DEUX, true) ? substr($c, 0, 2) : substr($c, 0, 3);
    }

    /** Montants par rubrique et détail par groupe de comptes, pour un exercice. */
    private static function exercice(int $client, int $annee): array
    {
        $m = []; $d = [];
        $balance = Compta::balance($client, $annee);
        foreach ($balance as $b) {
            $s = round($b['sd'] - $b['sc'], 3);
            if (abs($s) < 0.0005) continue;
            $c = (string) $b['compte'];
            [$cle, $v] = in_array($c[0], ['6', '7'], true) ? self::resultat($c, $s) : (self::bilan($c, $s) ?? [null, 0]);
            if (! $cle) continue;
            $m[$cle] = round(($m[$cle] ?? 0) + $v, 3);
            $base = str_replace('_amort', '', $cle);
            $g = str_ends_with($cle, '_amort') ? '_amort' : self::groupe($c);
            if (preg_match('/^(A\d|CP\d|R\d)$/', $base) || in_array($cle, ['capital', 'reserves', 'report', 'acp', 'emprunts', 'apf', 'provisions'], true)) {
                $note = match ($cle) { 'capital', 'reserves', 'report', 'acp' => 'CP1', 'emprunts' => 'CP2', 'apf', 'provisions' => 'CP3', default => $base };
                $d[$note][$g] = round(($d[$note][$g] ?? 0) + $v, 3);
            }
        }
        $v = fn ($k) => (float) ($m[$k] ?? 0);

        // État de résultat (modèle autorisé)
        $R = ['R1' => $v('R1'), 'R2' => $v('R2'), 'R3' => $v('R3'), 'vspf' => $v('vspf'), 'R4' => $v('R4'), 'R5' => $v('R5'), 'R6' => $v('R6'), 'R7' => $v('R7'), 'R8' => $v('R8'),
            'cfn' => $v('cfn'), 'pp' => $v('pp'), 'ago' => $v('ago'), 'apo' => $v('apo'), 'is' => $v('is'), 'ais' => $v('ais'), 'ext' => $v('ext'), 'modif' => 0.0];
        $R['tpe'] = round($R['R1'] + $R['R2'] + $R['R3'], 3);
        $R['tce'] = round($R['vspf'] + $R['R4'] + $R['R5'] + $R['R6'] + $R['R7'] + $R['R8'], 3);
        $R['rex'] = round($R['tpe'] - $R['tce'], 3);
        $R['rav'] = round($R['rex'] - $R['cfn'] + $R['pp'] + $R['ago'] - $R['apo'], 3);
        $R['rap'] = round($R['rav'] - $R['is'] - $R['ais'], 3);
        $R['rn'] = round($R['rap'] + $R['ext'], 3);
        $R['ram'] = round($R['rn'] + $R['modif'], 3);

        // Bilan
        $A = [];
        foreach (['A1', 'A2', 'A3', 'A5', 'A6', 'A7', 'A8'] as $k) $A[$k] = ['brut' => $v($k), 'amort' => $v($k.'_amort'), 'net' => round($v($k) - $v($k.'_amort'), 3)];
        foreach (['A4', 'A9'] as $k) $A[$k] = ['brut' => $v($k), 'amort' => 0.0, 'net' => $v($k)];
        $A['immo'] = round($A['A1']['net'] + $A['A2']['net'] + $A['A3']['net'], 3);
        $A['anc'] = round($A['immo'] + $A['A4']['net'], 3);
        $A['ac'] = round($A['A5']['net'] + $A['A6']['net'] + $A['A7']['net'] + $A['A8']['net'] + $A['A9']['net'], 3);
        $A['total'] = round($A['anc'] + $A['ac'], 3);

        $P = ['capital' => $v('capital'), 'reserves' => $v('reserves'), 'acp' => $v('acp'), 'report' => $v('report'), 'resultat' => $R['ram'],
            'provisions' => $v('provisions'), 'apf' => $v('apf'), 'emprunts' => $v('emprunts'), 'CP4' => $v('CP4'), 'CP5' => $v('CP5'), 'CP6' => $v('CP6')];
        $P['cpar'] = round($P['capital'] + $P['reserves'] + $P['acp'] + $P['report'], 3);
        $P['cp'] = round($P['cpar'] + $P['resultat'], 3);
        $P['pnc'] = round($P['emprunts'] + $P['apf'] + $P['provisions'], 3);
        $P['pc'] = round($P['CP4'] + $P['CP5'] + $P['CP6'], 3);
        $P['passifs'] = round($P['pnc'] + $P['pc'], 3);
        $P['total'] = round($P['cp'] + $P['passifs'], 3);

        return ['A' => $A, 'P' => $P, 'R' => $R, 'detail' => $d, 'n' => count($balance)];
    }

    /** Flux de trésorerie de l'exercice $N (méthode indirecte, modèle autorisé) à partir des variations du bilan depuis $N1. */
    private static function flux(array $N, array $N1): array
    {
        $delta = fn (callable $f) => round($f($N) - $f($N1), 3);
        $F = ['rn' => $N['R']['rn']];
        $F['amort'] = $delta(fn ($x) => $x['A']['A1']['amort'] + $x['A']['A2']['amort'] + $x['A']['A3']['amort'] + $x['A']['A5']['amort'] + $x['A']['A6']['amort']
            + $x['A']['A7']['amort'] + $x['A']['A8']['amort'] + $x['P']['provisions']);
        $F['stocks'] = -$delta(fn ($x) => $x['A']['A5']['brut']);
        $F['creances'] = -$delta(fn ($x) => $x['A']['A6']['brut']);
        $F['autres_actifs'] = -$delta(fn ($x) => $x['A']['A7']['brut'] + $x['A']['A4']['net']);
        $F['dettes'] = $delta(fn ($x) => $x['P']['CP4'] + $x['P']['CP5']);
        $F['pmv'] = 0.0; $F['transferts'] = 0.0; $F['autres_exp'] = 0.0;
        $F['exp'] = round($F['rn'] + $F['amort'] + $F['stocks'] + $F['creances'] + $F['autres_actifs'] + $F['dettes'], 3);
        $dImmo = $delta(fn ($x) => $x['A']['A1']['brut'] + $x['A']['A2']['brut']);
        $dFin = $delta(fn ($x) => $x['A']['A3']['brut']);
        $F['acq'] = $dImmo > 0 ? -$dImmo : 0.0; $F['ces'] = $dImmo < 0 ? -$dImmo : 0.0;
        $F['acq_fin'] = $dFin > 0 ? -$dFin : 0.0; $F['ces_fin'] = $dFin < 0 ? -$dFin : 0.0; $F['autres_inv'] = 0.0;
        $F['inv'] = round($F['acq'] + $F['ces'] + $F['acq_fin'] + $F['ces_fin'], 3);
        $F['emission'] = $delta(fn ($x) => $x['P']['capital']);
        $affectation = round($delta(fn ($x) => $x['P']['reserves'] + $x['P']['report']) - $N1['R']['ram'], 3);      // < 0 : distribution
        $F['dividendes'] = min(0.0, $affectation);
        $dEmp = $delta(fn ($x) => $x['P']['emprunts']);
        $F['emprunts'] = max(0.0, $dEmp); $F['remboursements'] = min(0.0, $dEmp);
        $F['autres_fin'] = round(max(0.0, $affectation) + $delta(fn ($x) => $x['P']['acp'] + $x['P']['apf']), 3);
        $F['fin'] = round($F['emission'] + $F['dividendes'] + $F['emprunts'] + $F['remboursements'] + $F['autres_fin'], 3);
        $F['change'] = 0.0;
        $F['tresorerie_debut'] = round($N1['A']['A9']['net'] - $N1['P']['CP6'], 3);
        $F['tresorerie_fin'] = round($N['A']['A9']['net'] - $N['P']['CP6'], 3);
        $F['variation'] = round($F['tresorerie_fin'] - $F['tresorerie_debut'], 3);
        $F['autres_postes'] = round($F['variation'] - $F['exp'] - $F['inv'] - $F['fin'] - $F['change'], 3);     // placements, écarts…

        return $F;
    }

    /** États de l'exercice N et de N-1, flux de trésorerie et notes. */
    public static function calculer(Client $client, int $annee): array
    {
        $N = self::exercice($client->id, $annee);
        $N1 = self::exercice($client->id, $annee - 1);
        $N2 = self::exercice($client->id, $annee - 2);
        $F = self::flux($N, $N1);
        $F1 = self::flux($N1, $N2);

        // Notes : rubriques non nulles, lignes par groupe de comptes (N, N-1, variation)
        $notes = [];
        foreach (array_keys(self::RUBRIQUES) as $code) {
            $groupes = array_unique(array_merge(array_keys($N['detail'][$code] ?? []), array_keys($N1['detail'][$code] ?? [])));
            if (! $groupes) continue;
            $lignes = [];
            foreach ($groupes as $g) {
                if ($g === '_amort') continue;
                $lignes[] = ['libelle' => Compta::libelle($g) ?: $g, 'compte' => $g, 'n' => (float) ($N['detail'][$code][$g] ?? 0), 'n1' => (float) ($N1['detail'][$code][$g] ?? 0)];
            }
            usort($lignes, fn ($a, $b) => strcmp($a['compte'], $b['compte']));
            $amort = in_array('_amort', $groupes, true) ? ['n' => (float) ($N['detail'][$code]['_amort'] ?? 0), 'n1' => (float) ($N1['detail'][$code]['_amort'] ?? 0)] : null;
            $notes[$code] = ['titre' => self::RUBRIQUES[$code], 'lignes' => $lignes, 'amort' => $amort,
                'brut' => ['n' => round(array_sum(array_column($lignes, 'n')), 3), 'n1' => round(array_sum(array_column($lignes, 'n1')), 3)]];
        }

        return ['annee' => $annee, 'N' => $N, 'N1' => $N1, 'F' => $F, 'F1' => $F1, 'notes' => $notes,
            'equilibre' => abs($N['A']['total'] - $N['P']['total']) < 0.01, 'vide' => $N['n'] === 0];
    }

    /** Montant au format des états financiers : 1 234,567 ; négatif entre parenthèses. */
    public static function montant($v): string
    {
        $v = round((float) $v, 3);
        $t = number_format(abs($v), 3, ',', ' ');
        return $v < 0 ? '('.$t.')' : $t;
    }
}
