<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Declaration;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Fichiers trimestriels déposés sur support magnétique auprès de la DGI (opérations en suspension des taxes sur le chiffre d'affaires) :
 * enregistrements EF (entête) / DF (détails) / TF (fin) à zones de longueur fixe, séparés par CRLF, codage ASCII.
 * Alphanumérique cadré à gauche (espaces), numérique cadré à droite (zéros), montants en millimes, dates JJMMAAAA, taux 999V99.
 */
abstract class FichierDgi
{
    private const CLES = 'ABCDEFGHJKLMNPQRSTVWXYZ';

    /** Préfixe du nom de fichier (BCD, FAC). */
    abstract public static function prefixe(): string;

    /** Contrôles d'une ligne de la liste. */
    abstract public static function controles(array $l, array $entete): array;

    /** Contenu du fichier à déposer. */
    abstract public static function fichier(Client $c, Declaration $d): string;

    /** Trimestre « 2026-T4 » → [année, n° de trimestre]. */
    public static function trimestre(string $periode): array
    {
        return [(int) substr($periode, 0, 4), (int) substr($periode, -1)];
    }

    public static function nomFichier(string $periode): string
    {
        [$an, $t] = self::trimestre($periode);
        return static::prefixe().'_T'.$t.'_'.substr((string) $an, -2);
    }

    /** Matricule fiscal « 1234567/A/B/M/000 », « 1234567ABM000 »… → parties, ou null si la structure est invalide. */
    public static function matricule(?string $mf): ?array
    {
        $s = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $mf));
        if (! preg_match('/^(\d{7})([A-HJ-NP-TV-Z])([ABDP]?)([CPMN])(\d{3})$/', $s, $m)) return null;
        return ['num' => $m[1], 'cle' => $m[2], 'tva' => $m[3], 'cat' => $m[4], 'etab' => $m[5], 'brut' => $s];
    }

    /** Clé de contrôle du matricule : somme des chiffres pondérés 7..1, modulo 23, dans l'alphabet sans I, O, U. */
    public static function cleValide(string $num, string $cle): bool
    {
        $s = 0;
        for ($i = 0; $i < 7; $i++) $s += (int) $num[$i] * (7 - $i);
        return self::CLES[$s % 23] === $cle;
    }

    /** Message d'erreur sur un matricule fiscal de tiers (13 caractères), ou null. */
    public static function erreurMatricule(?string $mf): ?string
    {
        $p = self::matricule($mf);
        if (! $p) return 'Structure du matricule fiscal invalide (ex. 1234567ABM000).';
        return self::cleValide($p['num'], $p['cle']) ? null : 'Clé du matricule fiscal incorrecte.';
    }

    /** Identifiant du déclarant sur 12 caractères : matricule (7) + clé + catégorie + établissement secondaire « 000 ». */
    public static function identifiantDeclarant(Client $c): string
    {
        $p = self::matricule($c->matricule_fiscal);
        return $p ? $p['num'].$p['cle'].$p['cat'].'000' : str_pad(substr(preg_replace('/\D/', '', (string) $c->matricule_fiscal), 0, 7), 7, '0', STR_PAD_LEFT).'   000';
    }

    /** Début commun des enregistrements : identifiant du déclarant, exercice, trimestre (20 caractères avec le type). */
    protected static function cle(Client $c, Declaration $d): string
    {
        [$an, $t] = self::trimestre($d->periode);
        return self::identifiantDeclarant($c).$an.'T'.$t;
    }

    /** Entête EF commune (220 caractères) : raison sociale, activité, adresse du déclarant. */
    protected static function entete(Client $c, Declaration $d): string
    {
        $h = $d->champs ?? [];
        return 'EF'.self::cle($c, $d).self::x($c->nom, 40).self::x($h['activite'] ?? '', 40).self::x($h['adresse'] ?? '', 120);
    }

    protected static function millimes($v): int
    {
        return (int) round((float) $v * 1000);
    }

    /** Taux en 999V99 : 19 % → 01900. */
    protected static function taux($v): string
    {
        return self::n((int) round((float) $v * 100), 5);
    }

    /** Texte ASCII sans les délimiteurs « < » et « /> ». */
    protected static function ascii(?string $s): string
    {
        return trim(str_replace(['<', '/>', "\r", "\n"], ['', '', ' ', ' '], Str::ascii((string) $s)));
    }

    protected static function x(?string $s, int $len): string
    {
        return str_pad(substr(self::ascii($s), 0, $len), $len);
    }

    protected static function n($v, int $len): string
    {
        return str_pad(substr((string) $v, -$len), $len, '0', STR_PAD_LEFT);
    }

    protected static function date(?string $d): string
    {
        return $d ? Carbon::parse($d)->format('dmY') : '00000000';
    }
}
