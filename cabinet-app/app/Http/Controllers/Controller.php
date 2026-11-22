<?php

namespace App\Http\Controllers;

use App\Models\Activite;
use App\Models\Client;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Dossier client courant (mémorisé en session) pour les modules compta, paie, liasse, déclarations, audit. */
    protected function dossier(Request $r): ?Client
    {
        if ($r->filled('client')) session(['dossier' => (int) $r->input('client')]);
        $c = Client::find(session('dossier'));
        if (! $c) { $c = Client::orderBy('nom')->first(); if ($c) session(['dossier' => $c->id]); }
        return $c;
    }

    /**
     * Groupes de documents ajoutés en une fois (espace client, GED) : lots[i][$champ] + lots[i][fichiers][]
     * (plusieurs catégories), ou un seul groupe $champ + fichiers[] (ancien formulaire). Les fichiers vides sont ignorés.
     */
    protected static function lotsDocuments(Request $r, string $champ, array $autres = []): array
    {
        $lire = fn (array $src, $fichiers) => [$champ => $src[$champ] ?? null] + array_map(fn ($k) => $src[$k] ?? null, array_combine($autres, $autres))
            + ['fichiers' => array_values(array_filter((array) $fichiers))];
        if (! $r->has('lots') && ! $r->hasFile('lots')) return [$lire($r->all(), $r->file('fichiers', []))];
        $fichiers = (array) $r->file('lots', []);
        $lots = [];
        foreach ((array) $r->input('lots', []) + array_fill_keys(array_keys($fichiers), []) as $i => $l) {
            $lots[] = $lire((array) $l, $fichiers[$i]['fichiers'] ?? []);
        }
        return $lots;
    }

    protected function log(string $action, ?int $client = null): void
    {
        Activite::create(['user_id' => auth()->id(), 'client_id' => $client, 'action' => $action]);
    }
}
