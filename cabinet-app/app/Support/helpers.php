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
