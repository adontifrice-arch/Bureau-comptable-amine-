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

    protected function log(string $action, ?int $client = null): void
    {
        Activite::create(['user_id' => auth()->id(), 'client_id' => $client, 'action' => $action]);
    }
}
