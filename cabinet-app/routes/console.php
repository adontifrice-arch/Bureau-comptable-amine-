<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Échéances fiscales et sociales du mois en cours, pour chaque cabinet (à planifier le 1er de chaque mois)
Artisan::command('echeances:generer {--mois= : mois au format AAAA-MM (par défaut le mois en cours)}', function () {
    $m = \Carbon\Carbon::createFromFormat('Y-m', $this->option('mois') ?: now()->format('Y-m'))->startOfMonth();
    foreach (\App\Models\Cabinet::pluck('id') as $cab) {
        $n = \App\Support\CabinetCourant::pour($cab, fn () => \App\Services\EcheancesFiscales::genererCabinet($m->year, $m->month));
        $this->info("Cabinet $cab : $n échéance(s) ajoutée(s) pour ".$m->format('m/Y').'.');
    }
})->purpose('Génère les échéances fiscales et sociales du mois');

\Illuminate\Support\Facades\Schedule::command('echeances:generer')->monthlyOn(1, '06:00');

// Sauvegardes automatiques programmées dans Paramètres → Sauvegardes (vérifiées chaque minute, chaque cabinet)
Artisan::command('sauvegardes:auto', function () {
    foreach (\App\Models\Cabinet::pluck('id') as $cab) {
        $s = \App\Services\Sauvegardes::executerSiEchue($cab);
        if ($s) $this->info("Cabinet $cab : sauvegarde {$s->fichier} ({$s->statut}).");
    }
})->purpose('Fait les sauvegardes automatiques arrivées à échéance');

\Illuminate\Support\Facades\Schedule::command('sauvegardes:auto')->everyMinute()->withoutOverlapping();
