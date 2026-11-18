<?php

namespace App\Services;

/**
 * Variantes de la balance générale (onglet Balance générale) :
 * - générale : tous les comptes mouvementés (Compta::balance) ;
 * - auxiliaire : comptes de tiers fournisseurs (40), clients (41) et personnel (42), par section avec sous-totaux ;
 * - centralisée : comptes regroupés sur leurs premiers chiffres (401001, 401002 → 401) ;
 * - comparative : soldes de l'exercice et de l'exercice précédent, avec la variation.
 */
class Balances
{
    public const VUES = ['generale' => 'Générale', 'auxiliaire' => 'Auxiliaire', 'centralisee' => 'Centralisée', 'comparative' => 'Comparative'];

    /** Sections de la balance auxiliaire : racine => intitulé. */
    public const AUXILIAIRES = ['40' => 'Fournisseurs et comptes rattachés', '41' => 'Clients et comptes rattachés', '42' => 'Personnel et comptes rattachés'];

    /** Niveaux de centralisation proposés (nombre de chiffres du compte). */
    public const NIVEAUX = [2 => '2 chiffres (40, 41…)', 3 => '3 chiffres (401, 411…)', 4 => '4 chiffres (4011…)'];

    public static function vue(?string $v): string
    {
        return isset(self::VUES[$v]) ? $v : 'generale';
    }

    public static function niveau($n): int
    {
        return isset(self::NIVEAUX[(int) $n]) ? (int) $n : 3;
    }

    /** @return array{0: string, 1: string} titre et nom de fichier de la vue */
    public static function titre(string $vue, int $annee, int $niveau = 3): array
    {
        return match ($vue) {
            'auxiliaire' => ['BALANCE AUXILIAIRE '.$annee, 'balance-auxiliaire'],
            'centralisee' => ['BALANCE CENTRALISÉE '.$annee.' — '.$niveau.' CHIFFRES', 'balance-centralisee-'.$niveau],
            'comparative' => ['BALANCE COMPARATIVE '.$annee.' / '.($annee - 1), 'balance-comparative'],
            default => ['BALANCE '.$annee, 'balance'],
        };
    }

    /** Données de la vue pour l'écran, l'impression et l'export. */
    public static function donnees(int $client, int $annee, string $vue, int $niveau = 3): array
    {
        return match ($vue) {
            'auxiliaire' => ['sections' => $s = self::auxiliaire($client, $annee), 'T' => Compta::totaux(collect($s)->pluck('lignes')->flatten(1)->all())],
            'centralisee' => ['B' => $B = self::centralisee($client, $annee, $niveau), 'T' => Compta::totaux($B)],
            'comparative' => self::comparative($client, $annee),
            default => ['B' => $B = Compta::balance($client, $annee), 'T' => Compta::totaux($B)],
        };
    }

    /** Comptes de tiers (40, 41) par section, chacune avec ses totaux. */
    public static function auxiliaire(int $client, int $annee): array
    {
        $B = Compta::balance($client, $annee);
        $out = [];
        foreach (self::AUXILIAIRES as $racine => $titre) {
            $L = array_values(array_filter($B, fn ($b) => str_starts_with($b['compte'], $racine)));
            if ($L) $out[$racine] = ['titre' => $racine.' — '.$titre, 'lignes' => $L, 'T' => Compta::totaux($L)];
        }
        return $out;
    }

    /**
     * Comptes regroupés sur leurs $niveau premiers chiffres : reports et mouvements additionnés,
     * solde du groupe net (débiteur ou créditeur).
     */
    public static function centralisee(int $client, int $annee, int $niveau): array
    {
        $G = [];
        foreach (Compta::balance($client, $annee) as $b) {
            $k = substr($b['compte'], 0, $niveau);
            $G[$k] ??= ['compte' => $k, 'libelle' => Compta::libelle($k), 'rd' => 0, 'rc' => 0, 'md' => 0, 'mc' => 0, 'sd' => 0, 'sc' => 0, 'n' => 0];
            foreach (['rd', 'rc', 'md', 'mc'] as $c) $G[$k][$c] += $b[$c];
            $G[$k]['solde'] = ($G[$k]['solde'] ?? 0) + $b['sd'] - $b['sc'];
            $G[$k]['n']++;
        }
        ksort($G, SORT_STRING);
        return array_values(array_map(function ($g) {
            $s = r3($g['solde']);
            unset($g['solde']);
            foreach (['rd', 'rc', 'md', 'mc'] as $c) $g[$c] = r3($g[$c]);
            return ['sd' => max($s, 0), 'sc' => max(-$s, 0)] + $g;
        }, $G));
    }

    /** Soldes N et N-1 de chaque compte, variation (N − N-1, débiteur positif) et variation en %. */
    public static function comparative(int $client, int $annee): array
    {
        $N = collect(Compta::balance($client, $annee))->keyBy('compte');
        $P = collect(Compta::balance($client, $annee - 1))->keyBy('compte');
        $comptes = $N->keys()->merge($P->keys())->map(fn ($c) => (string) $c)->unique()->sort(SORT_STRING)->values();
        $C = []; $T = ['nd' => 0, 'nc' => 0, 'pd' => 0, 'pc' => 0];
        foreach ($comptes as $c) {
            $n = $N[$c] ?? null; $p = $P[$c] ?? null;
            $sn = $n ? $n['sd'] - $n['sc'] : 0; $sp = $p ? $p['sd'] - $p['sc'] : 0;
            if (abs($sn) < 0.0005 && abs($sp) < 0.0005) continue;
            $var = r3($sn - $sp);
            $C[] = ['compte' => $c, 'libelle' => ($n ?? $p)['libelle'], 'nd' => max(r3($sn), 0), 'nc' => max(-r3($sn), 0), 'pd' => max(r3($sp), 0), 'pc' => max(-r3($sp), 0),
                'var' => $var, 'pct' => abs($sp) > 0.0005 ? round($var / abs($sp) * 100, 1) : null];
            $T['nd'] += max($sn, 0); $T['nc'] += max(-$sn, 0); $T['pd'] += max($sp, 0); $T['pc'] += max(-$sp, 0);
        }
        return ['C' => $C, 'T' => array_map('r3', $T)];
    }
}
