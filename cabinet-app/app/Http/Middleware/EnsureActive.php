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
        return $next($request);
    }
}
