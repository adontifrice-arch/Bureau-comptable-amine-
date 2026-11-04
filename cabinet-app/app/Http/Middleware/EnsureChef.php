<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureChef
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->estChef()) {
            return back()->with('err', 'Action réservée au chef comptable.');
        }
        return $next($request);
    }
}
