<?php

namespace App\Http\Middleware;

use App\Models\JournalOperation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Suivi des opérations : toute requête qui modifie des données (POST, PUT, PATCH, DELETE) et tout
 * téléchargement ou export est inscrit au journal, avec l'utilisateur, le dossier et l'adresse IP.
 * Les simples consultations de pages ne sont pas enregistrées.
 */
class TracerOperations
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $u = $request->user();
        if (! $u) return $response;

        $export = $response instanceof BinaryFileResponse || $response instanceof StreamedResponse
            || str_contains((string) $response->headers->get('Content-Disposition'), 'attachment');
        if (! $request->isMethodSafe() || $export) {
            try {
                JournalOperation::tracer($request, $u, $response->getStatusCode());
            } catch (\Throwable $e) {
                report($e);                                      // le suivi ne doit jamais empêcher l'opération
            }
        }
        return $response;
    }
}
