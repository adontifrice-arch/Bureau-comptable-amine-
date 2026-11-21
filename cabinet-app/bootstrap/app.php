<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['chef' => \App\Http\Middleware\EnsureChef::class, 'actif' => \App\Http\Middleware\EnsureActive::class,
            'espace-client' => \App\Http\Middleware\EspaceClient::class,
            'valideur' => \App\Http\Middleware\EnsureValideur::class,
            'droits' => \App\Http\Middleware\VerifierDroits::class, 'tracer' => \App\Http\Middleware\TracerOperations::class]);
        $middleware->redirectGuestsTo('/connexion');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
