<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActive
{
    public function handle(Request $request, Closure $next)
    {
        // Seuls les utilisateurs actifs du cabinet entrent (jamais un compte de l'espace client)
        if ($request->user() && (! $request->user() instanceof \App\Models\User || $request->user()->status !== 'active')) {
            $message = $request->user() instanceof \App\Models\User ? self::messageInactif($request->user()) : 'Connectez-vous avec un compte du cabinet.';
            Auth::logout();
            return redirect()->route('login')->with('err', $message);
        }
        // À l'entrée de chaque mois : échéances fiscales et sociales du mois (une fois par mois et par cabinet)
        if ($request->user()) {
            try { \App\Services\EcheancesFiscales::entreeDuMois(); } catch (\Throwable $e) { report($e); }
            // Sauvegarde automatique en retard (planificateur Windows absent ou arrêté) : faite après la réponse
            $cabinet = $request->user()->cabinet_id;
            app()->terminating(function () use ($cabinet) {
                try { \App\Services\Sauvegardes::executerSiEchue($cabinet); } catch (\Throwable $e) { report($e); }
            });
        }
        return $next($request);
    }

    public static function messageInactif(\App\Models\User $u): string
    {
        if ($u->status === 'disabled') {
            return 'Votre compte est bloqué'.($u->motif_blocage ? ' : '.$u->motif_blocage : '').'. Adressez-vous au chef comptable.';
        }
        return 'Votre compte est en attente de validation par le chef comptable.';
    }
}
