<?php

namespace App\Services;

use App\Models\Salarie;

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

    public static function calcul(Salarie $e, float $prime, array $P): array
    {
        $base = (float) $e->salaire_base;
        $brut = r3($base + $prime);
        $cs = r3($brut * $P['cnssS'] / 100);
        $bAn = $brut * 12; $cAn = $cs * 12;
        $fp = min($P['frais'] / 100 * ($bAn - $cAn), $P['fraisMax']);
        $fam = ($e->chef_famille ? $P['chef'] : 0) + $P['enf'] * min((int) $e->enfants, $P['enfMax']);
        $bi = max(0, $bAn - $cAn - $fp - $fam);
        $ir = r3(static::bareme($bi, $P['bar']) / 12);
        $css = r3($bi * $P['css'] / 100 / 12);
        $ras = r3($ir + $css);
        $net = r3($brut - $cs - $ras);
        $cp = r3($brut * $P['cnssP'] / 100);
        $tfp = r3($brut * $P['tfp'] / 100);
        $fop = r3($brut * $P['fop'] / 100);
        return ['salarie_id' => $e->id, 'nom' => $e->nom, 'poste' => $e->poste, 'cin' => $e->cin, 'cnss' => $e->cnss,
            'base' => $base, 'prime' => $prime, 'brut' => $brut, 'cnssS' => $cs, 'fp' => r3($fp), 'fam' => $fam, 'bi' => r3($bi),
            'ir' => $ir, 'css' => $css, 'ras' => $ras, 'net' => $net, 'cnssP' => $cp, 'tfp' => $tfp, 'fop' => $fop,
            'cout' => r3($brut + $cp + $tfp + $fop)];
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
