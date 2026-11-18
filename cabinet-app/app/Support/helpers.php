<?php

use Carbon\Carbon;

if (! function_exists('tnd')) {
    /** Montant en dinars tunisiens (3 décimales). */
    function tnd($v, bool $suffix = true, int $dec = 3): string
    {
        $s = number_format((float) $v, $dec, ',', ' ');
        return $suffix ? $s.' TND' : $s;
    }
}
if (! function_exists('n3')) {
    function n3($v): string { return (float) $v == 0.0 ? '' : number_format((float) $v, 3, ',', ' '); }
}
if (! function_exists('montant_lettres')) {
    /** Montant en toutes lettres, en majuscules : « VINGT-SIX MILLE … DINARS CENT QUARANTE-TROIS MILLIMES ». */
    function montant_lettres($v): string
    {
        $millimes = (int) round(abs((float) $v) * 1000);
        [$dt, $mm] = [intdiv($millimes, 1000), $millimes % 1000];
        if (! class_exists(\NumberFormatter::class)) return number_format($millimes / 1000, 3, ',', ' ').' DINARS';
        $f = new \NumberFormatter('fr', \NumberFormatter::SPELLOUT);
        $s = $f->format($dt).' dinar'.($dt > 1 ? 's' : '');
        if ($mm) $s .= ' '.$f->format($mm).' millime'.($mm > 1 ? 's' : '');
        return mb_strtoupper($s);
    }
}
if (! function_exists('r3')) {
    function r3($v): float { return round((float) $v, 3); }
}
if (! function_exists('dfr')) {
    function dfr($d, string $fmt = 'd/m/Y'): string
    {
        if (! $d) return '—';
        return ($d instanceof Carbon ? $d : Carbon::parse($d))->format($fmt);
    }
}
if (! function_exists('dlong')) {
    function dlong($d): string
    {
        if (! $d) return '………';
        return ($d instanceof Carbon ? $d : Carbon::parse($d))->locale('fr')->translatedFormat('j F Y');
    }
}
if (! function_exists('mois_fr')) {
    function mois_fr(string $ym): string
    {
        return Carbon::parse($ym.'-01')->locale('fr')->translatedFormat('F Y');
    }
}
if (! function_exists('existe_cab')) {
    /** Règle de validation « exists » limitée au cabinet courant : un identifiant d'un autre cabinet est refusé. */
    function existe_cab(string $table, string $colonne = 'id'): string
    {
        return 'exists:'.$table.','.$colonne.',cabinet_id,'.(\App\Support\CabinetCourant::id() ?? 0);
    }
}
if (! function_exists('unique_cab')) {
    /** Règle de validation « unique » dans le cabinet courant. */
    function unique_cab(string $table, string $colonne): string
    {
        return 'unique:'.$table.','.$colonne.',NULL,id,cabinet_id,'.(\App\Support\CabinetCourant::id() ?? 0);
    }
}
