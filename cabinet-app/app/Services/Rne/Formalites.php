<?php

namespace App\Services\Rne;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Smalot\PdfParser\Parser;

/**
 * Formalités du Registre National des Entreprises (RNE) et leurs check-lists, d'après le site officiel
 * (home.registre-entreprises.tn/formalites/) : code, type et genre d'entreprise, procédure, redevance, délais,
 * et, lus dans la fiche PDF officielle de chaque formalité, les documents demandés et les observations.
 *
 * Les données sont conservées dans database/data/rne_formalites.json ; « php artisan rne:formalites » les met à jour.
 */
class Formalites
{
    public const PAGE = 'https://home.registre-entreprises.tn/formalites/';

    public static function fichier(): string
    {
        return database_path('data/rne_formalites.json');
    }

    /** @return array{source: string, mis_a_jour: ?string, formalites: array<int, array>} */
    public static function donnees(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $f = self::fichier();
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return $cache = is_array($j) ? $j : ['source' => self::PAGE, 'mis_a_jour' => null, 'formalites' => []];
    }

    public static function toutes(): array
    {
        return self::donnees()['formalites'];
    }

    public static function trouver(string $code): ?array
    {
        return collect(self::toutes())->firstWhere('code', $code);
    }

    /** Opération d'après le code : C = création, I = inscription, M = mise à jour (modification, dépôt, radiation…). */
    public static function operation(string $code): string
    {
        return match (true) { str_starts_with($code, 'RNE C') => 'Création', str_starts_with($code, 'RNE I') => 'Inscription', default => 'Mise à jour' };
    }

    /** Formalités pertinentes pour un client : son genre (physique / morale) et, pour une société, celles des sociétés. */
    public static function pour(\App\Models\Client $c): array
    {
        $physique = $c->estPhysique();
        return collect(self::toutes())->filter(function ($f) use ($physique) {
            if ($physique) return $f['genre'] === 'Personne physique';
            if ($f['genre'] !== 'Personne Morale') return false;
            return ! preg_match('/association|établissement public|etablissement public|parti politique|syndicat|coopérative|mutuelle|groupement|centre|réseau/iu', $f['type']);
        })->values()->all();
    }

    // ---------- Mise à jour depuis le site officiel ----------

    /**
     * Relit la page des formalités et la fiche PDF de chacune ; écrit le fichier de données. Renvoie le nombre de formalités.
     * $progression(code) est appelée pour chaque fiche lue.
     */
    public static function actualiser(?callable $progression = null): int
    {
        $html = Http::timeout(60)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get(self::PAGE)->throw()->body();
        if (! preg_match('/var wporders = (\{.*?\});\s*$/m', $html, $m)) throw new RuntimeException('Liste des formalités introuvable sur la page du RNE.');
        $w = json_decode($m[1], true);
        if (! is_array($w['orders'] ?? null)) throw new RuntimeException('Liste des formalités illisible.');
        $base = rtrim((string) ($w['resources_url'] ?? ''), '/');
        $parser = new Parser();
        $out = [];
        foreach ($w['orders'] as $o) {
            $code = trim((string) ($o['Code procédure'] ?? ''));
            if ($code === '') continue;
            $pdf = $base.'/'.rawurlencode($code).'_Fr.pdf';
            $fiche = ['documents' => [], 'observations' => []];
            try {
                $contenu = Http::timeout(60)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($pdf)->throw()->body();
                $fiche = self::lireFiche($parser->parseContent($contenu)->getText());
            } catch (\Throwable $e) {
                report($e);
            }
            $out[] = [
                'code' => $code, 'genre' => trim((string) ($o["Genre d'Entreprise"] ?? '')), 'type' => self::propre($o['Type d entreprise'] ?? ''),
                'service' => self::propre($o['Nature du service'] ?? ''), 'procedure' => self::propre($o['Procédure'] ?? ''),
                'redevance' => self::propre($o['Redevance demandée'] ?? ''), 'delais' => self::propre($o['Délais'] ?? ''),
                'documents' => $fiche['documents'], 'observations' => $fiche['observations'], 'pdf' => $pdf,
            ];
            if ($progression) $progression($code);
        }
        if (! $out) throw new RuntimeException('Aucune formalité lue.');
        @mkdir(dirname(self::fichier()), 0775, true);
        file_put_contents(self::fichier(), json_encode(['source' => self::PAGE, 'mis_a_jour' => now()->toDateString(), 'formalites' => $out],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return count($out);
    }

    /** Documents demandés et observations d'une fiche officielle (texte du PDF). */
    public static function lireFiche(string $texte): array
    {
        $lignes = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l)), preg_split('/\R/u', $texte)), fn ($l) => $l !== ''));
        $section = null; $docs = []; $obs = [];
        foreach ($lignes as $l) {
            if (preg_match('/^Documents demand[ée]s/iu', $l)) { $section = 'docs'; continue; }
            if (preg_match('/^Redevances? [àa] acquitter/iu', $l)) { $section = null; continue; }
            if (preg_match('/^Observations/iu', $l)) { $section = 'obs'; continue; }
            if (preg_match('/^D[ée]lais/iu', $l)) { $section = null; continue; }
            if ($section === null || $l === '-' || preg_match('/^\d+\/\d+$/', $l)) continue;
            $l = ltrim($l, "-• \t");
            if ($l === '') continue;
            $liste = &${$section === 'docs' ? 'docs' : 'obs'};
            // Ligne coupée : une suite commence par une minuscule (ou suit une ligne sans ponctuation finale terminée par « les », « de »…)
            if ($liste && (preg_match('/^\p{Ll}/u', $l) || preg_match('/\b(les|de|du|des|la|le|et|ou|à|au|aux|en|d\'|l\'|y compris)$/iu', end($liste)))) {
                $liste[count($liste) - 1] .= ' '.$l;
            } else {
                $liste[] = $l;
            }
            unset($liste);
        }
        return ['documents' => array_values(array_unique($docs)), 'observations' => array_values(array_unique($obs))];
    }

    private static function propre($v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }
}
