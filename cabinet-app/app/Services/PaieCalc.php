<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Paie;
use App\Models\PaieProfil;
use App\Models\PaieRubrique;
use App\Models\Salarie;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Calcul de paie selon la législation tunisienne (paramétrable). */
class PaieCalc
{
    /** Contrat Dignité : salaire mensuel dont les cotisations CNSS sont prises en charge par l'État (FNE). */
    public const DIGNITE_PLAFOND_CNSS = 600.0;
    /** Contrat Dignité : quote-part mensuelle de l'État, exonérée d'IRPP. */
    public const DIGNITE_SUBVENTION = 400.0;

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
        $P['regul_primes'] = ($k['regul_primes'] ?? '') === 'regul' ? 'regul' : 'difference';
        $P['tri'] = in_array($k['tri'] ?? null, ['nom', 'matricule'], true) ? $k['tri'] : 'nom';
        // Pointage : 26 jours ouvrables par mois au régime de 48 h (6 jours), 22 jours au régime de 40 h ou moins (5 jours).
        $h = (float) ($k['heures_semaine'] ?? 0) ?: 48.0;
        $P['heures_semaine'] = $h;
        $P['jours_mois'] = $h <= 40 ? 22 : 26;
        $P['heures_mois'] = r3($h * 52 / 12);
        $P['maj_hs'] = (float) ($k['majoration_hs'] ?? 25);
        $P['heures_jour'] = r3($h / ($h <= 40 ? 5 : 6));
        $P['jours_feries'] = 0.0;
        return $P;
    }

    /**
     * Paramètres d'un mois de paie : les jours ouvrables, jours fériés, heures par jour et heures ouvrables saisis dans
     * Saisie Paie remplacent ceux du régime horaire de l'employeur. Sans heures ouvrables saisies, elles valent jours × heures par jour
     * quand les jours ouvrables sont saisis.
     */
    public static function parametresMois(array $P, ?Paie $paie): array
    {
        if (! $paie) return $P;
        if ((float) $paie->heures_jour > 0) $P['heures_jour'] = (float) $paie->heures_jour;
        if ((float) $paie->jours_ouvrables > 0) {
            $P['jours_mois'] = (float) $paie->jours_ouvrables;
            $P['heures_mois'] = r3($P['jours_mois'] * $P['heures_jour']);
        }
        if ((float) $paie->heures_ouvrables > 0) $P['heures_mois'] = (float) $paie->heures_ouvrables;
        $P['jours_feries'] = (float) $paie->jours_feries;
        return $P;
    }

    /**
     * Paie d'un salarié pour le mois. Sans régularisation, l'IRPP et la CSS sont calculés sur le salaire du mois annualisé (× 12).
     * Avec la régularisation mensuelle ($cumul = mois déjà payés de l'année : n, brut imposable, cnssS, ir, css), le salaire imposable est
     * annualisé à partir du cumul de l'année et l'impôt du mois est l'impôt dû à date diminué des retenues déjà opérées.
     * Les retenues et charges sont arrondies au nombre de décimales choisi ; le net est le brut diminué des retenues.
     * Pointage ($jours, $hs) : le salaire de base est proratisé sur les jours travaillés (jours ouvrables du mois selon le régime horaire),
     * les heures supplémentaires sont payées au taux horaire (base ÷ heures mensuelles) majoré.
     * Régime horaire : le salaire de base de la fiche est le taux horaire et le pointage donne les heures travaillées (mois complet par défaut).
     * Employé non assujetti à la CNSS : ni cotisation salariale ni cotisation patronale.
     * Déductions de l'assiette annuelle : frais professionnels, chef de famille, enfants (détail par rang : à charge, étudiant, infirme),
     * parents à charge, assurance vie, CEA et intérêts bancaires déclarés sur la fiche.
     * Rubriques du dossier ($rubriques, $saisie du mois) : gains ajoutés au brut (hors assiette CNSS s'ils sont non cotisables, hors assiette
     * de l'IRPP s'ils sont non imposables), retenues cotisables déduites du brut, retenues non cotisables et avantages en nature déduits du net.
     * Une rubrique en « valeur net » est saisie nette : son brut est recherché pour que le net augmente exactement de ce montant.
     */
    public static function calcul(Salarie $e, float $prime, array $P, ?array $cumul = null, ?float $jours = null, float $hs = 0, ?Collection $rubriques = null, array $saisie = [], float $acompte = 0, array $pointage = [], array $prets = []): array
    {
        $horaire = $e->regime === 'horaire';
        $Hm = (float) ($P['heures_mois'] ?? 208);
        $Jm = (float) ($P['jours_mois'] ?? 26);
        $J = $horaire ? $Hm : $Jm;
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
        $f = ['base' => $base, 'jours' => $jours ?? $J, 'J' => $J, 'salaire' => $salaire, 'hs' => $hs, 'hs_montant' => $hsMontant, 'prime' => $prime, 'horaire' => $horaire,
            'acompte' => r3(max(0, $acompte)), 'taux_h' => r3($taux), 'pointage' => $pointage,
            'pret_societe' => r3((float) ($prets['societe'] ?? 0)), 'pret_cnss' => r3((float) ($prets['cnss'] ?? 0))];
        $ctx = ['base' => $base, 'salaire' => $salaire, 'jours' => $horaire ? max($jours ?? $Hm, 0) : min(max($jours ?? $J, 0), $J), 'plein' => $J,
            'horaire' => $horaire, 'taux_h' => $taux, 'taux_j' => $Jm > 0 ? $base / $Jm : 0];
        $L = $rubriques ? PaieRubrique::evaluer($rubriques, $e, $ctx, $saisie) : [];
        $res = static::assembler($e, $P, $cumul, $f, $L);
        // Second passage pour les retenues calculées sur le total brut ou le salaire imposable (déduites du net : brut et imposable inchangés).
        if ($rubriques && $rubriques->contains(fn ($r) => in_array($r->base, PaieRubrique::BASES_TOTAUX, true))) {
            $L = PaieRubrique::evaluer($rubriques, $e, $ctx + ['brut' => $res['brut'], 'imposable' => r3(($res['brut_imp'] ?? $res['brut']) - $res['cnssS'])], $saisie);
            $res = static::assembler($e, $P, $cumul, $f, $L);
        }
        foreach ($L as $i => $l) {
            if (! $l['net'] || ! $l['montant']) continue;
            $cible = $l['montant'];
            $sans = $L;
            $sans[$i]['montant'] = 0.0;
            $net0 = static::assembler($e, $P, $cumul, $f, $sans)['net'];
            $g = $cible;
            for ($k = 0; $k < 50; $k++) {
                $L[$i]['montant'] = r3($g);
                $ecart = $cible - (static::assembler($e, $P, $cumul, $f, $L)['net'] - $net0);
                if (abs($ecart) < 0.0005) break;
                $g += $ecart;
            }
            $L[$i]['valeur_net'] = $cible;
            $res = static::assembler($e, $P, $cumul, $f, $L);
        }
        return $res;
    }

    /**
     * Assiette annuelle de l'IRPP à partir du brut imposable annuel ($bAn) et de la CNSS salariale annuelle ($cAn) : frais professionnels,
     * chef de famille, enfants, parents à charge, assurance vie, CEA et intérêts bancaires déduits.
     */
    public static function imposable(Salarie $e, array $P, float $bAn, float $cAn): array
    {
        $fp = min($P['frais'] / 100 * ($bAn - $cAn), $P['fraisMax']);
        $fam = ($e->chef_famille ? $P['chef'] : 0) + static::deductionEnfants($e, $P)
            + (int) $e->parents_charge * min(($P['parentTaux'] ?? 5) / 100 * max(0, $bAn - $cAn - $fp), $P['parentMax'] ?? 150);
        $autres = (float) $e->assurance_vie + (float) $e->cea + (float) $e->interets_bancaires;
        return ['fp' => $fp, 'fam' => $fam, 'autres' => $autres, 'bi' => max(0, $bAn - $cAn - $fp - $fam - $autres)];
    }

    /**
     * Paie spéciale d'un salarié (congé, 13ème à 18ème mois) : brut = montant spécial + primes, soumis à la CNSS et aux charges patronales.
     * IRPP et CSS par différence : impôt annuel sur (salaire annuel ordinaire + paie spéciale) moins impôt annuel sur le salaire ordinaire,
     * le salaire ordinaire étant celui du mois de déclaration ($normal, résultat de la paie du mois) annualisé.
     * $libelle : intitulé de la ligne de salaire du bulletin (« 13ème mois », « Congé payé (5 j) »).
     */
    public static function speciale(Salarie $e, array $P, float $montant, float $prime, ?array $normal, string $libelle, float $acompte = 0, array $extra = []): array
    {
        $d = (int) ($P['arrondi'] ?? 3);
        $ar = fn ($v) => round((float) $v, $d);
        // Part soumise (montant + primes) et part exonérée de CNSS et d’IRPP ($extra exonere : indemnité de licenciement du STC).
        $taxable = r3($montant + $prime);
        $exonere = r3(max(0, (float) ($extra['exonere'] ?? 0)));
        $brut = r3($taxable + $exonere);
        $retenues = r3(max(0, (float) ($extra['retenues'] ?? 0)));
        // Reste dû des prêts retenu sur un solde de tout compte ($extra prets : {societe, cnss}).
        $pretS = r3(max(0, (float) ($extra['prets']['societe'] ?? 0)));
        $pretC = r3(max(0, (float) ($extra['prets']['cnss'] ?? 0)));
        // Prime non cotisable (fiche rubrique) : ni CNSS salariale ni patronale ; non imposable : ni IRPP ni CSS.
        $assujetti = ($e->cnss_assujetti ?? true) && ($extra['cotisable'] ?? true);
        $imposable = $extra['imposable'] ?? true;
        $cs = $assujetti ? $ar($taxable * $P['cnssS'] / 100) : 0.0;
        $cumul = $extra['cumul'] ?? null;
        if ($cumul !== null && $imposable) {
            // Régularisation sur les primes / autres / rappel (case RegP du salarié) : l'élément entre dans le cumul imposable de l'année
            // (paies du mois jusqu'au mois de déclaration inclus et éléments déjà régularisés) ; l'impôt dû sur le cumul annualisé
            // (× 12 / nombre de mois payés), ramené à la date, est diminué des retenues déjà opérées.
            $n = max(1, (int) $cumul['n']);
            $bAn = ($cumul['brut'] + $taxable) * 12 / $n;
            $cAn = ($cumul['cnssS'] + $cs) * 12 / $n;
            $apres = static::imposable($e, $P, $bAn, $cAn)['bi'];
            $ir = $ar(max(0, static::bareme($apres, $P['bar']) * $n / 12 - $cumul['ir']));
            $css = $ar(max(0, $apres * $P['css'] / 100 * $n / 12 - $cumul['css']));
            $methode = 'regul';
        } else {
            // Sans régularisation : par différence entre l'impôt annuel avec et sans l'élément, sur le salaire du mois annualisé.
            $b0 = $normal ? (float) ($normal['brut_imp'] ?? $normal['brut']) * 12 : 0.0;
            $c0 = $normal ? (float) $normal['cnssS'] * 12 : 0.0;
            $avant = static::imposable($e, $P, $b0, $c0)['bi'];
            $apres = $imposable ? static::imposable($e, $P, $b0 + $taxable, $c0 + $cs)['bi'] : $avant;
            $ir = $ar(max(0, static::bareme($apres, $P['bar']) - static::bareme($avant, $P['bar'])));
            $css = $ar(max(0, ($apres - $avant) * $P['css'] / 100));
            $methode = 'difference';
        }
        $ras = r3($ir + $css);
        $acompte = r3(max(0, $acompte));
        $net = r3($brut - $cs - $ras - $acompte - $retenues - $pretS - $pretC);
        $cp = $assujetti ? $ar($taxable * $P['cnssP'] / 100) : 0.0;
        $tfp = $ar($taxable * $P['tfp'] / 100);
        $fop = $ar($taxable * $P['fop'] / 100);
        return ['salarie_id' => $e->id, 'nom' => $e->nomComplet(), 'regime' => $e->regime === 'horaire' ? 'horaire' : 'mensuel', 'poste' => $e->poste,
            'cin' => $e->cin, 'cnss' => $e->cnss, 'matricule' => $e->matricule, 'special' => $libelle, 'methode_ir' => $methode,
            'base' => (float) ($extra['base'] ?? $e->salaire_base), 'jours' => null, 'jours_mois' => null, 'salaire' => r3($montant), 'hs' => 0.0, 'hs_montant' => 0.0,
            'prime' => r3($prime), 'rubriques' => [], 'gains' => 0.0, 'ded_brut' => 0.0,
            'brut' => $brut, 'base_cnss' => $taxable, 'brut_imp' => $taxable, 'exonere' => $exonere, 'stc_lignes' => $extra['lignes'] ?? null, 'cnssS' => $cs, 'fp' => 0.0, 'fam' => 0.0, 'autres_deductions' => 0.0, 'bi' => r3($apres),
            'ir' => $ir, 'css' => $css, 'ras' => $ras, 'retenues' => $retenues, 'nature' => 0.0, 'acompte' => $acompte, 'pret_societe' => $pretS, 'pret_cnss' => $pretC, 'net' => $net,
            'cnssP' => $cp, 'tfp' => $tfp, 'fop' => $fop, 'cout' => r3($brut + $cp + $tfp + $fop),
            'taux_cnssS' => (float) $P['cnssS'], 'taux_cnssP' => (float) $P['cnssP'], 'taux_h' => $extra['taux_h'] ?? null, 'taux_j' => $extra['taux_j'] ?? null,
            'pt_trav' => $extra['jours'] ?? null, 'pt_conge' => $extra['jours'] ?? null,
            ...($P['profil'] ?? ['profil' => $e->profil ?: null, 'code_exploitant' => null, 'decl_cnss' => true, 'decl_employeur' => true])];
    }

    /**
     * Résultats d'une paie spéciale ($paie de type conge, m13…m18, déclarée avec le mois $paie->mois) : figés si elle est clôturée.
     * Saisie par salarié (pointage) : jours (congé : jours payés), montant (brut spécial ; par défaut le salaire de base du mois pour
     * les 13ème à 18ème mois, jours × taux journalier pour le congé), acompte ; primes à part. Un salarié sans montant n'est pas payé.
     */
    public static function moisSpecial(Client $client, Paie $paie): array
    {
        if ($paie->statut !== 'brouillon' && $paie->resultats) return $paie->resultats;
        $P = static::parametres($client);
        // Cumul de l'année jusqu'au mois de déclaration inclus (paie du mois comprise), pour les salariés régularisés sur les primes.
        $cumul = static::cumulAvant($client, $paie->mois, $P);
        $R = static::calculerMois($client, $paie->mois, $P, $cumul, true);
        if ($cumul !== null) foreach ($R as $x) static::cumuler($cumul, $x, true);
        return static::calculerSpecial($client, $paie, $P, collect($R)->keyBy('salarie_id'), $cumul);
    }

    /** Régularisation mensuelle de l'IRPP du salarié : case de sa fiche (Reg), sinon réglage du dossier. */
    public static function regulIrpp(Salarie $e, array $P): bool
    {
        return $e->regul_irpp ?? ! empty($P['regul_irpp']);
    }

    /** Régularisation sur les primes / autres / rappel du salarié : case de sa fiche (RegP), sinon réglage du dossier. */
    public static function regulPrimes(Salarie $e, array $P): bool
    {
        return $e->regul_primes ?? (($P['regul_primes'] ?? '') === 'regul');
    }

    /** Un salarié au moins est régularisé (mensuellement ou sur les primes) : le cumul de l'année est nécessaire. */
    private static function regulActive(Client $client, array $P): bool
    {
        if (! empty($P['regul_irpp']) || ($P['regul_primes'] ?? '') === 'regul') return true;
        return Salarie::where('client_id', $client->id)->where(fn ($q) => $q->where('regul_irpp', true)->orWhere('regul_primes', true))->exists();
    }

    /** Ajoute un résultat au cumul de l'année du salarié ($mois : une paie du mois, compte un mois de plus). */
    private static function cumuler(array &$cumul, array $x, bool $mois): void
    {
        $c = $cumul[$x['salarie_id']] ?? ['n' => 0, 'brut' => 0, 'cnssS' => 0, 'ir' => 0, 'css' => 0];
        $cumul[$x['salarie_id']] = ['n' => $c['n'] + ($mois ? 1 : 0), 'brut' => $c['brut'] + ($x['brut_imp'] ?? $x['brut']), 'cnssS' => $c['cnssS'] + $x['cnssS'],
            'ir' => $c['ir'] + $x['ir'], 'css' => $c['css'] + $x['css']];
    }

    /**
     * Cumul imposable de l'année par salarié pour les mois antérieurs à $mois : paies du mois, et primes / autres / rappels calculés par
     * régularisation. Null si personne n'est régularisé (chaque mois se calcule alors seul).
     */
    public static function cumulAvant(Client $client, string $mois, array $P): ?array
    {
        if (! static::regulActive($client, $P)) return null;
        $cumul = [];
        for ($m = Carbon::parse(substr($mois, 0, 4).'-01-01'); $m->format('Y-m') < $mois; $m->addMonth()) {
            $ym = $m->format('Y-m');
            $R = static::calculerMois($client, $ym, $P, $cumul, false);
            foreach ($R as $x) static::cumuler($cumul, $x, true);
            $normaux = collect($R)->keyBy('salarie_id');
            foreach (static::speciales($client, $ym, true) as $p) {
                $RS = $p->statut !== 'brouillon' && $p->resultats ? $p->resultats : static::calculerSpecial($client, $p, $P, $normaux, $cumul);
                foreach ($RS as $x) if (($x['methode_ir'] ?? '') === 'regul') static::cumuler($cumul, $x, false);
            }
        }
        return $cumul;
    }

    /** Calcul d'une paie spéciale, de prime ou de rappel, la paie du mois de déclaration ($normaux) et le cumul de l'année étant connus. */
    private static function calculerSpecial(Client $client, Paie $paie, array $P, $normaux, ?array $cumul): array
    {
        $P = static::parametresMois($P, $paie);
        $vide = ['n' => 0, 'brut' => 0, 'cnssS' => 0, 'ir' => 0, 'css' => 0];
        $fin = Carbon::parse($paie->mois.'-01')->endOfMonth();
        $profils = PaieProfil::parNom($client);
        $libelle = $paie->nomType();
        $rub = $paie->estPrime() && $paie->rubrique_id ? PaieRubrique::where('client_id', $client->id)->find($paie->rubrique_id) : null;
        $flags = $rub ? ['cotisable' => ! $rub->non_cotisable, 'imposable' => ! $rub->non_imposable] : [];
        $out = [];
        // Solde de tout compte : un seul salarié, même sorti avant le mois de déclaration.
        if ($paie->estStc()) {
            $e = Salarie::where('client_id', $client->id)->find((int) ($paie->options['salarie_id'] ?? 0));
            if (! $e) return [];
            $Pe = static::pourProfil($P, $profils[mb_strtolower(trim((string) $e->profil))] ?? null);
            $x = static::stc($client, $e, $paie, $Pe, $cumul !== null ? ($cumul[$e->id] ?? $vide) : null);
            return $x ? [$x] : [];
        }
        foreach (static::trier(Salarie::where('client_id', $client->id)->get(), $P['tri'] ?? 'nom') as $e) {
            if (($e->date_embauche && $e->date_embauche->gt($fin)) || ! static::payeLeMois($e, $paie->mois)) continue;
            if ($rub && ! $rub->concerne($e)) continue;
            $pt = ($paie->pointage ?? [])[$e->id] ?? [];
            $Pe = static::pourProfil($P, $profils[mb_strtolower(trim((string) $e->profil))] ?? null);
            $horaire = $e->regime === 'horaire';
            $base = $horaire ? r3((float) $e->salaire_base * (float) $Pe['heures_mois']) : (float) $e->salaire_base;
            $tauxJ = $Pe['jours_mois'] > 0 ? $base / $Pe['jours_mois'] : 0;
            $jours = isset($pt['jours']) && $pt['jours'] !== null ? (float) $pt['jours'] : null;
            // Case RegP : IRPP par régularisation sur le cumul de l'année, sinon par différence.
            $reg = $cumul !== null && static::regulPrimes($e, $P) ? ['cumul' => $cumul[$e->id] ?? $vide] : [];
            if ($paie->estRappel()) {
                $rap = static::rappel($e, $paie, $pt, $base);
                $montant = isset($pt['montant']) && $pt['montant'] !== null ? (float) $pt['montant'] : $rap['montant'];
                if ($montant <= 0) continue;
                $out[] = static::speciale($e, $Pe, $montant, 0, $normaux[$e->id] ?? null, $libelle.' ('.$rap['nb'].' mois × '.n3($rap['nouveau'] - $rap['ancien']).')', (float) ($pt['acompte'] ?? 0),
                    ['base' => $base, 'taux_j' => r3($tauxJ)] + $reg) + ['rappel' => $rap];
                continue;
            }
            $defaut = match (true) {
                $paie->type === 'conge' => r3(($jours ?? 0) * $tauxJ),
                $paie->estPrime() => static::montantPrime($rub, $base),
                default => $base,
            };
            $montant = isset($pt['montant']) && $pt['montant'] !== null ? (float) $pt['montant'] : $defaut;
            $prime = (float) (($paie->primes ?? [])[$e->id] ?? 0);
            if ($montant <= 0 && $prime <= 0) continue;
            $lib = $paie->type === 'conge' && $jours ? $libelle.' payé ('.rtrim(rtrim(number_format($jours, 2, ',', ''), '0'), ',').' j)' : $libelle;
            $out[] = static::speciale($e, $Pe, $montant, $prime, $normaux[$e->id] ?? null, $lib, (float) ($pt['acompte'] ?? 0),
                ['base' => $base, 'jours' => $jours, 'taux_j' => r3($tauxJ), 'taux_h' => $Pe['heures_mois'] > 0 ? r3($base / $Pe['heures_mois']) : null] + $flags + $reg);
        }
        return $out;
    }

    /** Reste dû des prêts d'un salarié au début du mois $mois (échéances des mois antérieurs retenues) : {societe, cnss}. */
    public static function restePrets(Salarie $e, string $mois): array
    {
        $out = ['societe' => 0.0, 'cnss' => 0.0];
        foreach (\App\Models\Pret::where('salarie_id', $e->id)->get() as $p) {
            $retenu = array_sum(array_filter($p->echeancier(), fn ($v, $m) => $m < $mois, ARRAY_FILTER_USE_BOTH));
            $out[$p->type] = r3(($out[$p->type] ?? 0) + max(0, (float) $p->montant - $retenu));
        }
        return $out;
    }

    /** Échéances des prêts retenues sur la paie du mois : {salarie_id: {societe, cnss}}. */
    public static function pretsDuMois(Client $client, string $mois): array
    {
        $out = [];
        foreach (\App\Models\Pret::where('client_id', $client->id)->where('premier_mois', '<=', $mois)->get() as $p) {
            if ($p->soldeParStc($mois)) continue;   // reste dû retenu sur le solde de tout compte
            if ($m = $p->echeanceDuMois($mois)) $out[$p->salarie_id][$p->type] = r3(($out[$p->salarie_id][$p->type] ?? 0) + $m);
        }
        return $out;
    }

    /** Registre des congés du mois : {salarie_id: {paye, exceptionnel, sans_solde, maladie (jours ouvrables du mois)}}. */
    public static function congesRegistre(Client $client, string $mois, ?array $P = null): array
    {
        $cinq = (float) (($P ?? static::parametres($client))['jours_mois'] ?? 26) <= 22;
        $debut = $mois.'-01';
        $fin = Carbon::parse($debut)->endOfMonth()->format('Y-m-d').' 23:59:59';
        $out = [];
        foreach (\App\Models\Conge::where('client_id', $client->id)->where('du', '<=', $fin)->where('au', '>=', $debut)->get() as $c) {
            $out[$c->salarie_id][$c->type] = r3(($out[$c->salarie_id][$c->type] ?? 0) + $c->joursDansMois($mois, $cinq));
        }
        return $out;
    }

    /**
     * Congés de l'année jusqu'au mois $mois inclus : acquis = droit mensuel de la fiche (« acquis / mois ») × mois de présence depuis janvier
     * ou l'embauche ; pris = congés pointés dans la paie du mois et jours payés par les paies « Congé » ; reste = acquis − pris.
     */
    public static function soldeConges(Salarie $e, string $mois): array
    {
        $Y = (int) substr($mois, 0, 4);
        // Point de départ : le report saisi sur la fiche (jours dus au 1er janvier de son année) ; sans report, la première année de paie
        // du dossier, l'embauche si elle est postérieure. Les années intermédiaires sont calculées (acquis − pris) et reportées.
        $premiere = Paie::where('client_id', $e->client_id)->where('type', 'normale')->min('mois');
        $R = $e->conges_report_annee ? (int) $e->conges_report_annee : ($premiere ? (int) substr($premiere, 0, 4) : $Y);
        if ($e->date_embauche) $R = max($R, (int) $e->date_embauche->format('Y'));
        $R = min($R, $Y);
        $report = $e->conges_report_annee && (int) $e->conges_report_annee === $R ? (float) $e->conges_report : 0.0;
        $paies = Paie::where('client_id', $e->client_id)->whereIn('type', ['normale', 'conge'])->where('mois', '>=', $R.'-01')->where('mois', '<=', $mois)->get(['mois', 'type', 'pointage']);
        // Congés payés du registre (Congé → Saisie des congés) : pris en compte les mois où la colonne Congé de la paie n'est pas saisie.
        $registre = \App\Models\Conge::where('salarie_id', $e->id)->where('type', 'paye')->where('au', '>=', $R.'-01-01')->get();
        $cinq = (float) (\App\Services\PaieCalc::parametres($e->client)['jours_mois'] ?? 26) <= 22;
        $bilan = function (int $annee, string $jusqua) use ($e, $paies, $registre, $cinq) {
            $debut = $annee.'-01';
            if ($e->date_embauche && $e->date_embauche->format('Y-m') > $debut) $debut = $e->date_embauche->format('Y-m');
            if ($e->date_sortie && $e->date_sortie->format('Y-m') < $jusqua) $jusqua = $e->date_sortie->format('Y-m');
            $nbMois = $debut <= $jusqua ? (int) Carbon::parse($debut.'-01')->diffInMonths(Carbon::parse($jusqua.'-01')) + 1 : 0;
            $pris = 0.0;
            for ($m = $annee.'-01'; $m <= $jusqua && (int) substr($m, 0, 4) === $annee; $m = Carbon::parse($m.'-01')->addMonth()->format('Y-m')) {
                $paie = $paies->first(fn ($p) => $p->type === 'normale' && $p->mois === $m);
                $pointe = $paie ? (($paie->pointage ?? [])[$e->id]['conge'] ?? null) : null;
                $pris += $pointe !== null ? (float) $pointe : $registre->sum(fn ($c) => $c->joursDansMois($m, $cinq));
                // Paies spéciales « Congé » : jours payés.
                foreach ($paies->where('type', 'conge')->where('mois', $m) as $pc) $pris += (float) (($pc->pointage ?? [])[$e->id]['jours'] ?? 0);
            }
            return [r3((float) $e->conges_acquis * $nbMois), r3($pris)];
        };
        for ($a = $R; $a < $Y; $a++) {
            [$acq, $pr] = $bilan($a, $a.'-12');
            $report += $acq - $pr;
        }
        [$acquis, $pris] = $bilan($Y, $mois);
        return ['report' => r3($report), 'acquis' => $acquis, 'pris' => $pris, 'reste' => r3($report + $acquis - $pris)];
    }

    /**
     * Solde de tout compte d'un salarié (options : salarie_id ; saisie : conge_jours, conge_montant, preavis, autres, licenciement, retenues, acompte).
     * Indemnité compensatrice de congés = jours restants (solde de l'année jusqu'au mois de sortie) × taux journalier (salaire de base ÷ jours
     * ouvrables) ; préavis et autres gains soumis à la CNSS et à l'IRPP ; indemnité de licenciement exonérée ; retenues et acompte déduits du net.
     */
    public static function stc(Client $client, Salarie $e, Paie $paie, array $P, ?array $cumul): ?array
    {
        $pt = ($paie->pointage ?? [])[$e->id] ?? [];
        $num = fn ($k) => isset($pt[$k]) && $pt[$k] !== null && $pt[$k] !== '' ? (float) $pt[$k] : null;
        $horaire = $e->regime === 'horaire';
        $base = $horaire ? r3((float) $e->salaire_base * (float) $P['heures_mois']) : (float) $e->salaire_base;
        $tauxJ = $P['jours_mois'] > 0 ? $base / $P['jours_mois'] : 0;
        $sortie = $e->date_sortie?->format('Y-m') ?? $paie->mois;
        $nb = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

        // 1. Salaire du mois de sortie : jours ouvrables travaillés du 1er du mois (ou de l'embauche) à la date de sortie, rubriques du mois comprises.
        //    IRPP du mois : régularisé (case Reg) sur le cumul des mois précédents, sinon sur le salaire du mois annualisé.
        $jt = $num('jours_trav') ?? static::joursOuvrablesSortie($e, $P);
        $Ps = $P; $Ps['regul_irpp'] = static::regulIrpp($e, $P);
        $vide = ['n' => 0, 'brut' => 0, 'cnssS' => 0, 'ir' => 0, 'css' => 0];
        $sal = $jt > 0 ? static::calcul($e, 0, $Ps, $Ps['regul_irpp'] ? ($cumul ?? $vide) : null, $horaire ? $jt * (float) ($P['heures_jour'] ?? 8) : $jt, 0, PaieRubrique::duDossier($client)) : null;

        // 2. Indemnités : congés (report des années précédentes + année en cours, jusqu'à la sortie), préavis, autres gains, licenciement (exonéré).
        $solde = static::soldeConges($e, min($sortie, $paie->mois));
        $jours = $num('conge_jours') ?? max(0, $solde['reste']);
        $conge = $num('conge_montant') ?? r3($jours * $tauxJ);
        $preavis = $num('preavis') ?? 0.0;
        $autres = $num('autres') ?? 0.0;
        $licenciement = $num('licenciement') ?? 0.0;
        $retenues = $num('retenues') ?? 0.0;
        // Prêts : le reste dû (échéances non retenues avant le mois de déclaration du STC) est retenu sur le solde de tout compte.
        $restes = static::restePrets($e, $paie->mois);
        $prets = ['societe' => $num('pret_societe') ?? $restes['societe'], 'cnss' => $num('pret_cnss') ?? $restes['cnss']];
        $lignes = array_values(array_filter([
            $sal ? ['Salaire de '.mois_fr($sortie).' ('.$nb($jt).' j / '.$nb($P['jours_mois']).')', $sal['salaire']] : null,
            $sal && abs($sal['brut'] - $sal['salaire']) > 0.0005 ? ['Primes, indemnités et retenues du mois', r3($sal['brut'] - $sal['salaire'])] : null,
            $conge ? ['Indemnité compensatrice de congés ('.$nb($jours).' j × '.n3($tauxJ).')', $conge] : null,
            $preavis ? ['Indemnité de préavis', $preavis] : null,
            $autres ? ['Autres gains', $autres] : null,
            $licenciement ? ['Indemnité de licenciement (exonérée)', $licenciement] : null,
        ]));
        if (! $lignes) return null;
        // IRPP des indemnités : régularisation (case RegP) sur le cumul avec le salaire du mois, sinon par différence avec un mois complet.
        if (static::regulPrimes($e, $P)) {
            $c = $cumul ?? $vide;
            if ($sal) $c = ['n' => $c['n'] + 1, 'brut' => $c['brut'] + $sal['brut_imp'], 'cnssS' => $c['cnssS'] + $sal['cnssS'], 'ir' => $c['ir'] + $sal['ir'], 'css' => $c['css'] + $sal['css']];
            $reg = ['cumul' => $c];
        } else {
            $reg = [];
        }
        $ref = static::calcul($e, 0, array_merge($P, ['regul_irpp' => false]));
        $ind = static::speciale($e, $P, r3($conge + $preavis + $autres), 0, $ref, 'Solde de tout compte', $num('acompte') ?? 0,
            ['base' => $base, 'taux_j' => r3($tauxJ), 'exonere' => $licenciement, 'retenues' => $retenues, 'prets' => $prets, 'lignes' => $lignes] + $reg);
        // Somme du salaire du mois et des indemnités.
        $x = $ind;
        if ($sal) foreach (['brut', 'base_cnss', 'brut_imp', 'cnssS', 'ir', 'css', 'ras', 'retenues', 'nature', 'acompte', 'pret_societe', 'pret_cnss', 'net', 'cnssP', 'tfp', 'fop', 'cout'] as $k) $x[$k] = r3(($ind[$k] ?? 0) + ($sal[$k] ?? 0));
        $x['salaire'] = r3(($sal['brut'] ?? 0) + $conge + $preavis + $autres);
        return $x + ['stc' => ['jours_trav' => $jt, 'salaire_mois' => $sal['brut'] ?? 0.0, 'jours' => $jours, 'taux_j' => r3($tauxJ), 'conge' => $conge, 'preavis' => $preavis, 'autres' => $autres,
            'licenciement' => $licenciement, 'retenues' => $retenues, 'pret_societe' => $prets['societe'], 'pret_cnss' => $prets['cnss'], 'restes_prets' => $restes, 'solde' => $solde, 'date_sortie' => $e->date_sortie?->format('d/m/Y'), 'motif' => $e->motif_sortie]];
    }

    /**
     * Jours ouvrables travaillés le mois de la sortie : du 1er du mois (ou de l'embauche) à la date de sortie incluse, dimanches exclus
     * (et samedis au régime de 5 jours), sans dépasser les jours ouvrables du mois. 0 sans date de sortie.
     */
    public static function joursOuvrablesSortie(Salarie $e, array $P): float
    {
        if (! $e->date_sortie) return 0.0;
        $debut = $e->date_sortie->copy()->startOfMonth();
        if ($e->date_embauche && $e->date_embauche->gt($debut)) $debut = $e->date_embauche->copy();
        $cinq = (float) ($P['jours_mois'] ?? 26) <= 22;
        $n = 0;
        for ($d = $debut->copy(); $d->lte($e->date_sortie); $d->addDay()) {
            if ($d->isSunday() || ($cinq && $d->isSaturday())) continue;
            $n++;
        }
        return (float) min($n, (float) ($P['jours_mois'] ?? 26));
    }

    /**
     * Rappel de salaire d'un salarié : {ancien, nouveau, nb, montant}. Saisie (pointage) prioritaire ; sinon ancien salaire = dernière valeur
     * du salaire de base dans l'historique de la fiche (à défaut, le salaire actuel), nouveau = salaire de base actuel, ou ancien augmenté
     * de l'augmentation saisie en tête (% ou TND) quand la fiche n'a pas changé ; nb = mois de la période où le salarié était embauché.
     */
    public static function rappel(Salarie $e, Paie $paie, array $pt, float $base): array
    {
        $num = fn ($k) => isset($pt[$k]) && $pt[$k] !== null && $pt[$k] !== '' ? (float) $pt[$k] : null;
        $h = \App\Models\SalarieHistorique::where('salarie_id', $e->id)->where('champ', 'salaire_base')->latest('id')->first();
        $histo = $h ? (float) str_replace([' ', "\u{a0}", ','], ['', '', '.'], (string) $h->ancien) : null;
        $aug = (float) ($paie->options['augmentation'] ?? 0);
        $pct = ($paie->options['unite'] ?? '%') === '%';
        $ancien = $num('ancien') ?? $histo ?? $base;
        $nouveau = $num('nouveau') ?? ($histo !== null ? $base : ($aug ? r3($pct ? $ancien * (1 + $aug / 100) : $ancien + $aug) : $base));
        $moisPeriode = array_filter($paie->moisRappeles(), fn ($m) => ! $e->date_embauche || $e->date_embauche->format('Y-m') <= $m);
        $nb = $num('nb') ?? (float) count($moisPeriode);
        return ['ancien' => r3($ancien), 'nouveau' => r3($nouveau), 'nb' => $nb, 'montant' => r3(max(0, ($nouveau - $ancien) * $nb))];
    }

    /** Montant par défaut d'une prime d'après sa rubrique : montant fixe, pourcentage ou proportion du salaire de base ; saisie manuelle : 0. */
    public static function montantPrime(?PaieRubrique $r, float $base): float
    {
        if (! $r) return 0.0;
        return r3(match ($r->mode) {
            'montant' => (float) $r->montant,
            'pourcentage' => $base * (float) $r->taux / 100,
            'proportion' => (float) $r->denominateur ? $base * (float) $r->numerateur / (float) $r->denominateur : 0.0,
            default => 0.0,
        });
    }

    /** Paies spéciales déclarées avec le mois (clôturées, ou toutes avec $brouillons). */
    public static function speciales(Client $client, string $mois, bool $brouillons = false)
    {
        return Paie::where('client_id', $client->id)->where('mois', $mois)->where('type', '!=', 'normale')
            ->when(! $brouillons, fn ($q) => $q->where('statut', '!=', 'brouillon'))->orderBy('type')->get();
    }

    /**
     * Résultats déclarés avec le mois (CNSS, déclaration employeur) : paie ordinaire et paies spéciales clôturées rattachées à ce mois.
     * Les lignes d'un même salarié ne sont pas fusionnées.
     */
    public static function moisDeclares(Client $client, string $mois): array
    {
        $R = static::mois($client, $mois);
        foreach (static::speciales($client, $mois) as $p) array_push($R, ...static::moisSpecial($client, $p));
        return $R;
    }

    /** Brut, cotisations, impôt et net à partir des éléments fixes ($f) et des lignes de rubriques ($L). */
    private static function assembler(Salarie $e, array $P, ?array $cumul, array $f, array $L): array
    {
        $d = (int) ($P['arrondi'] ?? 3);
        $ar = fn ($v) => round((float) $v, $d);
        $somme = fn (callable $filtre) => r3(array_sum(array_map(fn ($l) => $l['montant'], array_filter($L, $filtre))));
        $gains = $somme(fn ($l) => $l['sens'] === 'gain');
        $dedBrut = $somme(fn ($l) => $l['sens'] === 'retenue' && $l['cotisable']);
        $retenues = $somme(fn ($l) => $l['sens'] === 'retenue' && ! $l['cotisable']);
        $nature = $somme(fn ($l) => $l['sens'] === 'gain' && $l['nature']);
        $brut = r3($f['salaire'] + $f['hs_montant'] + $f['prime'] + $gains - $dedBrut);
        $baseCnss = r3($brut - $somme(fn ($l) => $l['sens'] === 'gain' && ! $l['cotisable']));
        $brutImp = r3($brut - $somme(fn ($l) => $l['sens'] === 'gain' && ! $l['imposable']));
        // Contrat Dignité (KARAMA) : cotisations salariale et patronale prises en charge par l'État (FNE) jusqu'au plafond mensuel
        // (600 DT), dues aux taux du régime général sur la fraction excédentaire ; quote-part de l'État (400 DT) exonérée d'IRPP.
        $dignite = ! empty($P['dignite']);
        $baseCot = $dignite ? r3(max(0, $baseCnss - $P['dignite_plafond'])) : $baseCnss;
        if ($dignite) $brutImp = r3(max(0, $brutImp - $P['dignite_subvention']));
        $assujetti = $e->cnss_assujetti ?? true;
        $cs = $assujetti ? $ar($baseCot * $P['cnssS'] / 100) : 0.0;
        $regul = ! empty($P['regul_irpp']) && $cumul !== null;
        $n = $regul ? $cumul['n'] + 1 : 12;
        $bAn = $regul ? ($cumul['brut'] + $brutImp) * 12 / $n : $brutImp * 12;
        $cAn = $regul ? ($cumul['cnssS'] + $cs) * 12 / $n : $cs * 12;
        ['fp' => $fp, 'fam' => $fam, 'autres' => $autres, 'bi' => $bi] = static::imposable($e, $P, $bAn, $cAn);
        if ($regul) {
            $ir = $ar(max(-$cumul['ir'], static::bareme($bi, $P['bar']) * $n / 12 - $cumul['ir']));
            $css = $ar(max(-$cumul['css'], $bi * $P['css'] / 100 * $n / 12 - $cumul['css']));
        } else {
            $ir = $ar(static::bareme($bi, $P['bar']) / 12);
            $css = $ar($bi * $P['css'] / 100 / 12);
        }
        $ras = r3($ir + $css);
        // L'acompte déjà versé dans le mois est déduit du net à payer.
        // Échéances des prêts (société, CNSS) retenues sur le net.
        $net = r3($brut - $cs - $ras - $retenues - $nature - ($f['acompte'] ?? 0) - ($f['pret_societe'] ?? 0) - ($f['pret_cnss'] ?? 0));
        $cp = $assujetti ? $ar($baseCot * $P['cnssP'] / 100) : 0.0;
        $tfp = $ar($brut * $P['tfp'] / 100);
        $fop = $ar($brut * $P['fop'] / 100);
        return ['salarie_id' => $e->id, 'nom' => $e->nomComplet(), 'regime' => $f['horaire'] ? 'horaire' : 'mensuel', 'poste' => $e->poste,
            'cin' => $e->cin, 'cnss' => $e->cnss, 'matricule' => $e->matricule,
            'base' => $f['base'], 'jours' => $f['jours'], 'jours_mois' => $f['J'], 'salaire' => $f['salaire'], 'hs' => $f['hs'], 'hs_montant' => $f['hs_montant'],
            'prime' => $f['prime'], 'rubriques' => $L, 'gains' => $gains, 'ded_brut' => $dedBrut,
            'brut' => $brut, 'base_cnss' => $baseCnss, 'base_cotisee' => $assujetti ? $baseCot : 0.0, 'brut_imp' => $brutImp,
            'cnssS' => $cs, 'fp' => r3($fp), 'fam' => r3($fam), 'autres_deductions' => r3($autres), 'bi' => r3($bi),
            'ir' => $ir, 'css' => $css, 'ras' => $ras, 'retenues' => $retenues, 'nature' => $nature, 'acompte' => $f['acompte'] ?? 0.0, 'pret_societe' => $f['pret_societe'] ?? 0.0, 'pret_cnss' => $f['pret_cnss'] ?? 0.0, 'net' => $net,
            'taux_h' => $f['taux_h'] ?? null, ...($f['pointage'] ?? []),
            'cnssP' => $cp, 'tfp' => $tfp, 'fop' => $fop, 'cout' => r3($brut + $cp + $tfp + $fop),
            'taux_cnssS' => (float) $P['cnssS'], 'taux_cnssP' => (float) $P['cnssP'],
            ...($P['profil'] ?? ['profil' => $e->profil ?: null, 'code_exploitant' => null, 'decl_cnss' => true, 'decl_employeur' => true])];
    }

    /**
     * Résultats de paie d'un mois : la paie clôturée telle qu'elle a été figée, sinon le calcul à la volée
     * (pointage, primes et rubriques saisis du brouillon) pour les salariés embauchés au plus tard ce mois-là.
     * Aucun calcul pour un mois futur sans paie, sauf $futur (écran de calcul, clôture).
     * Avec la régularisation de l'IRPP (case Reg ou RegP d'un salarié), les mois précédents de l'année et leurs éléments régularisés
     * (primes, autres, rappels) sont parcourus pour établir le cumul de chaque salarié.
     */
    public static function mois(Client $client, string $mois, bool $futur = false): array
    {
        $P = static::parametres($client);
        return static::calculerMois($client, $mois, $P, static::cumulAvant($client, $mois, $P), $futur);
    }

    private static function calculerMois(Client $client, string $mois, array $P, ?array $cumul, bool $futur): array
    {
        $paie = Paie::where('client_id', $client->id)->where('mois', $mois)->normale()->first();
        if ($paie && $paie->statut !== 'brouillon' && $paie->resultats) return $paie->resultats;
        if (! $paie && ! $futur && $mois > now()->format('Y-m')) return [];
        $P = static::parametresMois($P, $paie);
        $fin = Carbon::parse($mois.'-01')->endOfMonth();
        $vide = ['n' => 0, 'brut' => 0, 'cnssS' => 0, 'ir' => 0, 'css' => 0];
        // Une rubrique payée ce mois-ci par une paie de prime (menu Prime) n'est pas payée une seconde fois dans la paie du mois.
        $parPrime = Paie::where('client_id', $client->id)->where('mois', $mois)->where('type', 'prime')->whereNotNull('rubrique_id')->pluck('rubrique_id')->all();
        $rubriques = PaieRubrique::duDossier($client)->reject(fn ($r) => in_array($r->id, $parPrime));
        $profils = PaieProfil::parNom($client);
        // Un salarié dont le solde de tout compte est déclaré ce mois-ci y reçoit son dernier salaire : il sort de la paie du mois.
        $parStc = Paie::where('client_id', $client->id)->where('mois', $mois)->where('type', 'stc')->get(['options'])->map(fn ($p) => (int) ($p->options['salarie_id'] ?? 0))->all();
        // Registre des congés (Congé → Saisie des congés) : jours du mois par salarié et par type.
        $registre = static::congesRegistre($client, $mois, $P);
        // Registre des acomptes (Acompte → Saisie des acomptes) : total à retenir ce mois-ci par salarié.
        $acomptes = \App\Models\Acompte::where('client_id', $client->id)->where('mois', $mois)->get()->groupBy('salarie_id')->map(fn ($g) => r3($g->sum('montant')))->all();
        // Prêts (menu Prêt) : échéances du mois par salarié et par type (société, CNSS).
        $prets = static::pretsDuMois($client, $mois);
        return static::trier(Salarie::where('client_id', $client->id)->get(), $P['tri'] ?? 'nom')
            ->filter(fn ($e) => ! in_array($e->id, $parStc, true))
            ->filter(fn ($e) => (! $e->date_embauche || $e->date_embauche->lte($fin)) && static::payeLeMois($e, $mois))
            ->map(function ($e) use ($paie, $P, $cumul, $vide, $rubriques, $profils, $registre, $acomptes, $prets) {
                $pt = ($paie?->pointage ?? [])[$e->id] ?? [];
                $Pe = static::pourProfil($P, $profils[mb_strtolower(trim((string) $e->profil))] ?? null);
                $reg = $registre[$e->id] ?? [];
                [$Pe, $jours] = static::joursPayes($e, $Pe, $pt, $reg);
                $Pe['regul_irpp'] = static::regulIrpp($e, $P);   // case Reg du salarié
                return static::calcul($e, (float) (($paie?->primes ?? [])[$e->id] ?? 0), $Pe, $cumul === null ? null : ($cumul[$e->id] ?? $vide),
                    $jours, (float) ($pt['hs'] ?? 0), $rubriques, (array) (($paie?->rubriques ?? [])[$e->id] ?? []), (float) ($pt['acompte'] ?? ($acomptes[$e->id] ?? 0)),
                    ['pt_jo' => $pt['jo'] ?? null, 'pt_trav' => $pt['jours'] ?? null, 'pt_conge' => (float) ($pt['conge'] ?? (($reg['paye'] ?? 0) + ($reg['exceptionnel'] ?? 0))), 'pt_sans_solde' => (float) ($reg['sans_solde'] ?? 0), 'pt_ferie' => $pt['ferie'] ?? null], $prets[$e->id] ?? []);
            })->values()->all();
    }

    /**
     * Jours (ou heures en régime horaire) payés d'après la saisie de la paie : J/H ouvrables propres à l'employé (sinon ceux du mois),
     * J/H travaillés, plus les jours de congé payé et les jours fériés (ceux du mois par défaut), convertis en heures en régime horaire.
     * Sans J/H travaillés saisis, le mois est payé en entier. Renvoie [paramètres de l'employé, jours payés ou null].
     */
    public static function joursPayes(Salarie $e, array $P, array $pt, array $registre = []): array
    {
        $horaire = $e->regime === 'horaire';
        $h = (float) ($P['heures_jour'] ?? 8);
        if (isset($pt['jo']) && (float) $pt['jo'] > 0) {
            if ($horaire) $P['heures_mois'] = (float) $pt['jo']; else $P['jours_mois'] = (float) $pt['jo'];
        }
        if (! isset($pt['jours']) || $pt['jours'] === '' || $pt['jours'] === null) {
            // Sans jours travaillés saisis : mois complet, moins les jours de congé sans solde du registre.
            $ss = (float) ($registre['sans_solde'] ?? 0);
            if ($ss <= 0) return [$P, null];
            $J = $horaire ? (float) $P['heures_mois'] : (float) $P['jours_mois'];
            return [$P, r3(max(0, $J - ($horaire ? $ss * $h : $ss)))];
        }
        // Congés payés : saisis dans la colonne Congé, sinon ceux du registre (congé payé et congé exceptionnel).
        $conge = isset($pt['conge']) ? (float) $pt['conge'] : (float) ($registre['paye'] ?? 0) + (float) ($registre['exceptionnel'] ?? 0);
        $absents = $conge + (float) ($pt['ferie'] ?? $P['jours_feries'] ?? 0);
        $jours = (float) $pt['jours'] + ($horaire ? $absents * (float) ($P['heures_jour'] ?? 8) : $absents);
        return [$P, r3($jours)];
    }

    /**
     * Paramètres de paie d'un employé selon son profil : taux de CNSS patronale du profil (vide = taux du dossier),
     * pas de cotisation salariale CNSS hors déclaration CNSS (CIVP, gérant), pas de TFP ni de FOPROLOS si le profil n'y est pas soumis.
     */
    public static function pourProfil(array $P, ?PaieProfil $profil): array
    {
        if (! $profil) return $P;
        if ($profil->contrat_dignite) {
            // Contrat Dignité : taux du régime général sur la fraction du salaire excédant le plafond pris en charge par l'État ;
            // ni TFP ni FOPROLOS.
            $P['dignite'] = true;
            $P['dignite_plafond'] = (float) ($P['dignite_plafond'] ?? self::DIGNITE_PLAFOND_CNSS);
            $P['dignite_subvention'] = (float) ($P['dignite_subvention'] ?? self::DIGNITE_SUBVENTION);
            $P['tfp'] = 0.0; $P['fop'] = 0.0;
        } elseif ($profil->cnss_patronale !== null) $P['cnssP'] = (float) $profil->cnss_patronale;
        if (! $profil->decl_cnss) $P['cnssS'] = 0.0;
        if (! $profil->soumis_tfp_fop) { $P['tfp'] = 0.0; $P['fop'] = 0.0; }
        $P['profil'] = $profil->meta();
        return $P;
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

    /**
     * Pointage saisi ou importé, nettoyé : {salarie_id: {jours (J/H travaillés, vide = mois complet), hs, et s'ils sont saisis :
     * jo (J/H ouvrables propres à l'employé), conge (jours de congé payé), ferie (jours fériés payés, vide = ceux du mois), acompte}}.
     */
    public static function pointage(array $saisie): array
    {
        $num = fn ($v) => $v !== '' && $v !== null && is_numeric($v) ? max(0.0, (float) $v) : null;
        $pt = [];
        foreach ($saisie as $id => $v) {
            $l = ['jours' => $num($v['jours'] ?? null), 'hs' => $num($v['hs'] ?? null) ?? 0.0];
            foreach (['jo', 'conge', 'ferie', 'acompte'] as $k) if (($x = $num($v[$k] ?? null)) !== null && ($x > 0 || $k === 'ferie')) $l[$k] = $x;
            if ($l['jours'] !== null || $l['hs'] > 0 || count($l) > 2) $pt[(int) $id] = $l;
        }
        return $pt;
    }

    /** Rubriques saisies du mois, nettoyées : {salarie_id: {rubrique_id: valeur}} (valeurs numériques seulement). */
    public static function saisieRubriques(array $saisie): array
    {
        $out = [];
        foreach ($saisie as $sid => $vals) {
            foreach ((array) $vals as $rid => $v) {
                if ($v === null || $v === '' || ! is_numeric($v)) continue;
                $out[(int) $sid][(int) $rid] = (float) $v;
            }
        }
        return $out;
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
        $t = array_fill_keys(['brut', 'cnssS', 'ir', 'css', 'ras', 'retenues', 'nature', 'acompte', 'pret_societe', 'pret_cnss', 'net', 'cnssP', 'tfp', 'fop', 'cout'], 0);
        foreach ($R as $r) foreach ($t as $k => $_) $t[$k] += $r[$k] ?? 0;
        return array_map('r3', $t);
    }

    /**
     * Lignes du bulletin de paie : [libellé, montant, style (1 = total, 2 = information)]. Éléments fixes, rubriques du dossier
     * (gains, retenues), cotisations, impôt, acompte et net. Les notes ne s'impriment pas.
     */
    public static function lignesBulletin(array $x, array $P): array
    {
        $rub = collect($x['rubriques'] ?? [])->where('sens', '!=', 'note');
        $lib = fn ($l) => $l['nom'].($l['quantite'] !== null ? ' ('.rtrim(rtrim(number_format((float) $l['quantite'], 2, ',', ''), '0'), ',').')' : '').(! empty($l['nature']) ? ' — en nature' : '');
        $nb = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
        if (! empty($x['special'])) return [
            ...(! empty($x['stc_lignes']) ? $x['stc_lignes'] : [[$x['special'], $x['salaire']]]),
            ...(($x['prime'] ?? 0) ? [['Primes et indemnités imposables', $x['prime']]] : []),
            ['SALAIRE BRUT', $x['brut'], 1],
            ['Retenue CNSS ('.($x['taux_cnssS'] ?? $P['cnssS']).' %)', -$x['cnssS']],
            [($x['methode_ir'] ?? '') === 'regul' ? 'Retenue à la source IRPP (régularisation sur le cumul de l’année)' : 'Retenue à la source IRPP (par différence sur l’année)', -$x['ir']],
            ['Contribution sociale de solidarité', -$x['css']],
            ...(($x['retenues'] ?? 0) ? [['Retenues (avance…)', -$x['retenues']]] : []),
            ...(($x['pret_societe'] ?? 0) ? [['Remboursement du reste dû du prêt société', -$x['pret_societe']]] : []),
            ...(($x['pret_cnss'] ?? 0) ? [['Remboursement du reste dû du prêt CNSS', -$x['pret_cnss']]] : []),
            ...(($x['acompte'] ?? 0) ? [['Acompte', -$x['acompte']]] : []),
            ['NET À PAYER', $x['net'], 1],
        ];
        return [
            ['Salaire de base'.(isset($x['jours'], $x['jours_mois']) && $x['jours'] != $x['jours_mois'] ? ' ('.$nb($x['jours']).((($x['regime'] ?? 'mensuel') === 'horaire') ? ' h / ' : ' j / ').$nb($x['jours_mois']).')' : ''), $x['salaire'] ?? $x['base']],
            ['Heures supplémentaires'.(! empty($x['hs']) ? ' ('.$x['hs'].' h)' : ''), $x['hs_montant'] ?? 0],
            ['Primes et indemnités imposables', $x['prime']],
            ...$rub->where('sens', 'gain')->map(fn ($l) => [$lib($l), $l['montant']])->values()->all(),
            ...$rub->where('sens', 'retenue')->where('cotisable', true)->map(fn ($l) => [$lib($l), -$l['montant']])->values()->all(),
            ['SALAIRE BRUT', $x['brut'], 1],
            ['Retenue CNSS ('.($x['taux_cnssS'] ?? $P['cnssS']).' %)'.(($b = $x['base_cotisee'] ?? $x['base_cnss'] ?? null) !== null && $b != $x['brut'] ? ' sur '.n3($b) : ''), -$x['cnssS']],
            ['Salaire imposable annuel (après abattements)', $x['bi'], 2],
            ['Retenue à la source IRPP', -$x['ir']],
            ['Contribution sociale de solidarité', -$x['css']],
            ...$rub->where('sens', 'retenue')->where('cotisable', false)->map(fn ($l) => [$lib($l), -$l['montant']])->values()->all(),
            ...(($x['nature'] ?? 0) ? [['Avantages en nature (non versés en espèces)', -$x['nature']]] : []),
            ...(($x['acompte'] ?? 0) ? [['Acompte sur salaire', -$x['acompte']]] : []),
            ...(($x['pret_societe'] ?? 0) ? [['Remboursement prêt société', -$x['pret_societe']]] : []),
            ...(($x['pret_cnss'] ?? 0) ? [['Remboursement prêt CNSS', -$x['pret_cnss']]] : []),
            ['NET À PAYER', $x['net'], 1],
        ];
    }

    /**
     * Écriture de paie (plan comptable tunisien) : D 640 salaires, 647 charges sociales, 661 TFP/FOPROLOS / C 425 net, 45311 CNSS, 432 retenues,
     * 437 TFP/FOPROLOS, 427 retenues diverses sur salaires (rubriques à déduire non cotisables), 79 transfert de charges (avantages en nature).
     */
    public static function lignes(array $t): array
    {
        $tf = r3($t['tfp'] + $t['fop']);
        return array_values(array_filter([
            ['compte' => '640', 'debit' => $t['brut'], 'credit' => 0],
            ['compte' => '647', 'debit' => $t['cnssP'], 'credit' => 0],
            ['compte' => '661', 'debit' => $tf, 'credit' => 0],
            // 425 : net dû au personnel, acomptes compris (ils ont été portés au débit du 425 lors de leur versement).
            ['compte' => '425', 'debit' => 0, 'credit' => r3($t['net'] + ($t['acompte'] ?? 0))],
            // Remboursement des prêts société : solde le 421 débité au versement du prêt.
            ['compte' => '421', 'debit' => 0, 'credit' => r3($t['pret_societe'] ?? 0)],
            ['compte' => '45311', 'debit' => 0, 'credit' => r3($t['cnssS'] + $t['cnssP'])],
            ['compte' => '432', 'debit' => 0, 'credit' => $t['ras']],
            ['compte' => '437', 'debit' => 0, 'credit' => $tf],
            ['compte' => '427', 'debit' => 0, 'credit' => r3(($t['retenues'] ?? 0) + ($t['pret_cnss'] ?? 0))],
            ['compte' => '79', 'debit' => 0, 'credit' => r3($t['nature'] ?? 0)],
        ], fn ($l) => $l['debit'] || $l['credit']));
    }
}
