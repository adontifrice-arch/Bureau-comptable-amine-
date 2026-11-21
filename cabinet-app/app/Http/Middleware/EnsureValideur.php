<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Saisie et validation des écritures : chef comptable, ou collaborateur qui en a le droit (accordé par défaut). */
class EnsureValideur
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->peutValider()) {
            return back()->with('err', 'Vous n’avez pas le droit de saisir et valider les écritures. Adressez-vous au chef comptable.');
        }
        return $next($request);
    }
}
