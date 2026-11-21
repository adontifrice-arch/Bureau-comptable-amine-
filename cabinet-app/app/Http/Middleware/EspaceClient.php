<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Support\CabinetCourant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Espace client : exige un compte client connecté et actif, puis traite la requête dans le cabinet
 * du compte. Les contrôleurs de l'espace restent responsables du filtre sur le client du compte.
 */
class EspaceClient
{
    public function handle(Request $request, Closure $next)
    {
        $u = Auth::guard('client')->user();
        if (! $u) {
            return redirect()->guest(route('espace.login'));
        }
        $client = CabinetCourant::pour($u->cabinet_id, fn () => Client::find($u->client_id));
        if (! $u->actif || ! $u->estActive() || ! $client) {
            Auth::guard('client')->logout();
            return redirect()->route('espace.login')->with('err', 'Votre accès à l’espace client a été désactivé. Contactez le cabinet.');
        }
        return CabinetCourant::pour($u->cabinet_id, function () use ($request, $next, $client) {
            $request->attributes->set('client', $client);
            return $next($request);
        });
    }
}
