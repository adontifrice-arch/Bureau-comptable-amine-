<?php

namespace App\Http\Middleware;

use App\Models\JournalOperation;
use App\Support\Droits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Droits de l'utilisateur connecté (après « actif ») :
 * - hors du réseau du cabinet sans droit d'accès à distance : déconnexion ;
 * - page d'un module non autorisé : refus.
 */
class VerifierDroits
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u) return $next($request);

        if (! Droits::reseauAutorise($u, $request->ip())) {
            JournalOperation::evenement($u, 'auth.refus_reseau', 'Accès refusé hors du réseau du cabinet', $request->ip());
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            return redirect()->route('login')->with('err', 'Votre compte n’est autorisé que depuis le réseau du cabinet.');
        }

        $module = Droits::moduleDeRoute($request->route()?->getName());
        if (! $u->peutModule($module)) {
            if ($request->expectsJson()) abort(403, 'Module non autorisé.');
            return redirect()->route('dashboard')->with('err', 'Le module « '.Droits::libelleModule($module).' » ne fait pas partie de vos accès. Adressez-vous au chef comptable.');
        }
        return $next($request);
    }
}
