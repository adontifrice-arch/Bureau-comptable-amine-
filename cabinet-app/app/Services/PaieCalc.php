<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Paie;
use App\Models\Salarie;
use App\Models\Setting;
use Carbon\Carbon;

/** Calcul de paie selon la législation tunisienne (paramétrable). */
class PaieCalc
{
    public static function bareme(float $base, string $bareme): float
    {
        $prev = 0; $t = 0;
        foreach (preg_split('/\r?\n/', $bareme) as $l) {
            $a = preg_split('/\s+/', trim($l));
            if (count($a) < 2 || ! is_numeric($a[0]) || ! is_numeric($a[1])) continue;
            [$pl, $tx] = [(float) $a[0], (float) $a[1]];
            if ($base > $prev) $t += (min($base, $pl) - $prev) * $tx / 100;
            $prev = max($prev, $pl);
        }
        return $t;
    }

    /**
     * Paramètres de paie d'un dossier : taux et barème du cabinet, complétés par les renseignements employeur
     * (CNSS salariale, accident du travail, TFP, FOPROLOS, arrondi, régularisation de l'IRPP, tri).
     */
    public static function parametres(?Client $client): array
    {
        $P = Setting::paie();
        $k = $client?->paie_config ?? [];
        if (isset($k['cnssS']) && is_numeric($k['cnssS'])) $P['cnssS'] = (float) $k['cnssS'];
        if (isset($k['at']) && is_numeric($k['at'])) { $P['cnssP'] = r3($P['cnssP'] - ($P['at'] ?? 0) + (float) $k['at']); $P['at'] = (float) $k['at']; }
        $P['tfp'] = ($k['tfp_actif'] ?? true) ? (float) ($k['tfp'] ?? $P['tfp']) : 0.0;
        $P['fop'] = ($k['fop_actif'] ?? true) ? (float) ($k['fop'] ?? $P['fop']) : 0.0;
        $P['arrondi'] = max(0, min(3, (int) ($k['arrondi'] ?? 3)));
        $P['regul_irpp'] = (bool) ($k['regul_irpp'] ?? false);
        $P['tri'] = in_array($k['tri'] ?? null, ['nom', 'matricule'], true) ? $k['tri'] : 'nom';
        // Pointage : 26 jours ouvrables par mois au régime de 48 h (6 jours), 22 jours au régime de 40 h ou moins (5 jours).
        $h = (float) ($k['heures_semaine'] ?? 0) ?: 48.0;
        $P['heures_semaine'] = $h;
        $P['jours_mois'] = $h <= 40 ? 22 : 26;
        $P['heures_mois'] = r3($h * 52 / 12);
        $P['maj_hs'] = (float) ($k['majoration_hs'] ?? 25);
        return $P;
    }

    /**
     * Paie d'un salarié pour le mois. Sans régularisation, l'IRPP et la CSS sont calculés sur le salaire du mois annualisé (× 12).
     * Avec la régularisation mensuelle ($cumul = mois déjà payés de l'année : n, brut, cnssS, ir, css), le salaire imposable est
     * annualisé à partir du cumul de l'année et l'impôt du mois est l'impôt dû à date diminué des retenues déjà opérées.
     * Les retenues et charges sont arrondies au nombre de décimales choisi ; le net est le brut diminué des retenues.
     * Pointage ($jours, $hs) : le salaire de base est proratisé sur les jours travaillés (jours ouvrables du mois selon le régime horaire),
     * les heures supplémentaires sont payées au taux horaire (base ÷ heures mensuelles) majoré.
     * Régime horaire : le salaire de base de la fiche est le taux horaire et le pointage donne les heures travaillées (mois complet par défaut).
     * Employé non assujetti à la CNSS : ni cotisation salariale ni cotisation patronale.
     * Déductions de l'assiette annuelle : frais professionnels, chef de famille, enfants (détail par rang : à charge, étudiant, infirme),
     * parents à charge, assurance vie, CEA et intérêts bancaires déclarés sur la fiche.
     */
    public static function calcul(Salarie $e, float $prime, array $P, ?array $cumul = null, ?float $jours = null, float $hs = 0): array
    {
        $d = (int) ($P['arrondi'] ?? 3);
        $ar = fn ($v) => round((float) $v, $d);
        $horaire = $e->regime === 'horaire';
        $Hm = (float) ($P['heures_mois'] ?? 208);
        $J = $horaire ? $Hm : (int) ($P['jours_mois'] ?? 26);
        if ($horaire) {
            $taux = (float) $e->salaire_base;
            $base = r3($taux * $Hm);
            $salaire = r3($taux * max($jours ?? $Hm, 0));
        } else {
            $base = (float) $e->salaire_base;
            $salaire = $jours === null ? $base : r3($base * min(max($jours, 0), $J) / $J);
            $taux = $Hm > 0 ? $base / $Hm : 0;
        }
        $hsMontant = r3($hs * $taux * (1 + ($P['maj_hs'] ?? 25) / 100));
        $brut = r3($salaire + $hsMontant + $prime);
        $assujetti = $e->cnss_assujetti ?? true;
        $cs = $assujetti ? $ar($brut * $P['cnssS'] / 100) : 0.0;
        $regul = ! empty($P['regul_irpp']) && $cumul !== null;
        $n = $regul ? $cumul['n'] + 1 : 12;
        $bAn = $regul ? ($cumul['brut'] + $brut) * 12 / $n : $brut * 12;
        $cAn = $regul ? ($cumul['cnssS'] + $cs) * 12 / $n : $cs * 12;
        $fp = min($P['frais'] / 100 * ($bAn - $cAn), $P['fraisMax']);
        $fam = ($e->chef_famille ? $P['chef'] : 0) + static::deductionEnfants($e, $P)
            + (int) $e->parents_charge * min(($P['parentTaux'] ?? 5) / 100 * max(0, $bAn - $cAn - $fp), $P['parentMax'] ?? 150);
        $autres = (float) $e->assurance_vie + (float) $e->cea + (float) $e->interets_bancaires;
        $bi = max(0, $bAn - $cAn - $fp - $fam - $autres);
        if ($regul) {
            $ir = $ar(max(-$cumul['ir'], static::bareme($bi, $P['bar']) * $n / 12 - $cumul['ir']));
            $css = $ar(max(-$cumul['css'], $bi * $P['css'] / 100 * $n / 12 - $cumul['css']));
        } else {
            $ir = $ar(static::bareme($bi, $P['bar']) / 12);
            $css = $ar($bi * $P['css'] / 100 / 12);
        }
        $ras = r3($ir + $css);
        $net = r3($brut - $cs - $ras);
        $cp = $assujetti ? $ar($brut * $P['cnssP'] / 100) : 0.0;
        $tfp = $ar($brut * $P['tfp'] / 100);
        $fop = $ar($brut * $P['fop'] / 100);
        return ['salarie_id' => $e->id, 'nom' => $e->nomComplet(), 'regime' => $horaire ? 'horaire' : 'mensuel', 'poste' => $e->poste, 'cin' => $e->cin, 'cnss' => $e->cnss, 'matricule' => $e->matricule,
            'base' => $base, 'jours' => $jours ?? $J, 'jours_mois' => $J, 'salaire' => $salaire, 'hs' => $hs, 'hs_montant' => $hsMontant,
            'prime' => $prime, 'brut' => $brut, 'cnssS' => $cs, 'fp' => r3($fp), 'fam' => r3($fam), 'autres_deductions' => r3($autres), 'bi' => r3($bi),
            'ir' => $ir, 'css' => $css, 'ras' => $ras, 'net' => $net, 'cnssP' => $cp, 'tfp' => $tfp, 'fop' => $fop,
            'cout' => r3($brut + $cp + $tfp + $fop)];
    }

    /**
     * Résultats de paie d'un mois : la paie clôturée telle qu'elle a été figée, sinon le calcul à la volée
     * (primes saisies du brouillon) pour les salariés embauchés au plus tard ce mois-là.
     * Aucun calcul pour un mois futur sans paie, sauf $futur (écran de calcul, clôture).
     * Avec la régularisation de l'IRPP, les mois précédents de l'année sont parcourus pour établir le cumul de chaque salarié.
     */
    public static function mois(Client $client, string $mois, bool $futur = false): array
    {
        $P = static::parametres($client);
        if (! $P['regul_irpp']) return static::calculerMois($client, $mois, $P, null, $futur);
        $cumul = [];
        for ($m = Carbon::parse(substr($mois, 0, 4).'-01-01'); $m->format('Y-m') < $mois; $m->addMonth()) {
            foreach (static::calculerMois($client, $m->format('Y-m'), $P, $cumul, false) as $x) {
                $c = $cumul[$x['salarie_id']] ?? ['n' => 0, 'brut' => 0, 'cnssS' => 0, 'ir' => 0, 'css' => 0];
                $cumul[$x['salarie_id']] = ['n' => $c['n'] + 1, 'brut' => $c['brut'] + $x['brut'], 'cnssS' => $c['cnssS'] + $x['cnssS'], 'ir' => $c['ir'] + $x['ir'], 'css' => $c['css'] + $x['css']];
            }
        }
        return static::calculerMois($client, $mois, $P, $cumul, $futur);
    }

    private static function calculerMois(Client $client, string $mois, array $P, ?array $cumul, bool $futur): array
    {
        $paie = Paie::where('client_id', $client->id)->where('mois', $mois)->first();
        if ($paie && $paie->statut !== 'brouillon' && $paie->resultats) return $paie->resultats;
        if (! $paie && ! $futur && $mois > now()->format('Y-m')) return [];
        $fin = Carbon::parse($mois.'-01')->endOfMonth();
        $vide = ['n' => 0, 'brut' => 0, 'cnssS' => 0, 'ir' => 0, 'css' => 0];
        return static::trier(Salarie::where('client_id', $client->id)->get(), $P['tri'] ?? 'nom')
            ->filter(fn ($e) => (! $e->date_embauche || $e->date_embauche->lte($fin)) && static::payeLeMois($e, $mois))
            ->map(function ($e) use ($paie, $P, $cumul, $vide) {
                $pt = ($paie?->pointage ?? [])[$e->id] ?? [];
                return static::calcul($e, (float) (($paie?->primes ?? [])[$e->id] ?? 0), $P, $cumul === null ? null : ($cumul[$e->id] ?? $vide),
                    isset($pt['jours']) && $pt['jours'] !== '' ? (float) $pt['jours'] : null, (float) ($pt['hs'] ?? 0));
            })->values()->all();
    }

    /**
     * Déduction annuelle pour enfants. Avec le détail de la fiche (4 premiers enfants au plus) : enfant infirme, étudiant
     * ou simplement à charge, chacun selon son montant ; sinon le nombre d'enfants à charge au montant de base.
     */
    public static function deductionEnfants(Salarie $e, array $P): float
    {
        $detail = array_slice(array_values((array) $e->enfants_detail), 0, (int) $P['enfMax']);
        if (! $detail) return $P['enf'] * min((int) $e->enfants, $P['enfMax']);
        $t = 0.0;
        foreach ($detail as $x) {
            $t += ! empty($x['infirme']) ? ($P['enfInf'] ?? 2000) : (! empty($x['etudiant']) ? ($P['enfEtu'] ?? 1000) : (! empty($x['a_charge']) ? $P['enf'] : 0));
        }
        return $t;
    }

    /** L'employé figure dans la paie du mois : ni sorti avant le début du mois, ni suspendu, ni sorti sans date de sortie. */
    public static function payeLeMois(Salarie $e, string $mois): bool
    {
        if ($e->etat_presence === 'suspendu') return false;
        if ($e->date_sortie) return $e->date_sortie->format('Y-m') >= $mois;
        return $e->etat_presence !== 'sorti';
    }

    /** Pointage saisi ou importé, nettoyé : {salarie_id: {jours (vide = mois complet), hs}}. */
    public static function pointage(array $saisie): array
    {
        $pt = [];
        foreach ($saisie as $id => $v) {
            $jours = isset($v['jours']) && $v['jours'] !== '' && $v['jours'] !== null && is_numeric($v['jours']) ? max(0.0, (float) $v['jours']) : null;
            $hs = is_numeric($v['hs'] ?? null) ? max(0.0, (float) $v['hs']) : 0.0;
            if ($jours !== null || $hs > 0) $pt[(int) $id] = ['jours' => $jours, 'hs' => $hs];
        }
        return $pt;
    }

    /** Ordre des salariés dans la paie et les éditions : par nom ou par matricule (sans matricule en dernier). */
    public static function trier($salaries, string $tri)
    {
        return $tri === 'matricule'
            ? $salaries->sortBy(fn ($e) => [$e->matricule === null || $e->matricule === '' ? 1 : 0, (string) $e->matricule, mb_strtolower($e->nom)])->values()
            : $salaries->sortBy(fn ($e) => mb_strtolower($e->nom))->values();
    }

    public static function totaux(array $R): array
    {
        $t = array_fill_keys(['brut', 'cnssS', 'ir', 'css', 'ras', 'net', 'cnssP', 'tfp', 'fop', 'cout'], 0);
        foreach ($R as $r) foreach ($t as $k => $_) $t[$k] += $r[$k];
        return array_map('r3', $t);
    }

    /** Écriture de paie (plan comptable tunisien) : D 640 salaires, 647 charges sociales, 661 TFP/FOPROLOS / C 425 net, 45311 CNSS, 432 retenues, 437 TFP/FOPROLOS. */
    public static function lignes(array $t): array
    {
        $tf = r3($t['tfp'] + $t['fop']);
        return array_values(array_filter([
            ['compte' => '640', 'debit' => $t['brut'], 'credit' => 0],
            ['compte' => '647', 'debit' => $t['cnssP'], 'credit' => 0],
            ['compte' => '661', 'debit' => $tf, 'credit' => 0],
            ['compte' => '425', 'debit' => 0, 'credit' => $t['net']],
            ['compte' => '45311', 'debit' => 0, 'credit' => r3($t['cnssS'] + $t['cnssP'])],
            ['compte' => '432', 'debit' => 0, 'credit' => $t['ras']],
            ['compte' => '437', 'debit' => 0, 'credit' => $tf],
        ], fn ($l) => $l['debit'] || $l['credit']));
    }
}
