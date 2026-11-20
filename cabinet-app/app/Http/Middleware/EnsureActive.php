<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->status !== 'active') {
            Auth::logout();
            return redirect()->route('login')->with('err', 'Votre compte est en attente de validation par le chef comptable.');
        }
        // À l'entrée de chaque mois : échéances fiscales et sociales du mois (une fois par mois et par cabinet)
        if ($request->user()) {
            try { \App\Services\EcheancesFiscales::entreeDuMois(); } catch (\Throwable $e) { report($e); }
        }
        return $next($request);
    }
}
