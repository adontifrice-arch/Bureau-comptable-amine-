<?php

namespace App\Providers;

use App\Models\Setting;
use App\Support\CabinetCourant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Carbon::setLocale('fr');
        setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'fra');
        // Coordonnées du cabinet courant pour toutes les vues, lues une fois par cabinet
        // (un processus qui sert plusieurs requêtes ne doit pas réutiliser le cabinet d'un autre utilisateur).
        View::composer('*', function ($view) {
            static $cache = [];
            $id = CabinetCourant::id() ?? 0;
            if (! isset($cache[$id])) {
                try { $cache[$id] = $id && Schema::hasTable('settings') ? Setting::cabinet() : ['nom' => config('app.name')]; }
                catch (\Throwable $e) { $cache[$id] = ['nom' => config('app.name')]; }
            }
            $view->with('cab', $cache[$id]);
        });
    }
}
