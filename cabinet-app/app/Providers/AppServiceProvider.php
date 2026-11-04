<?php

namespace App\Providers;

use App\Models\Setting;
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
        View::composer('*', function ($view) {
            static $cab;
            if ($cab === null) {
                try { $cab = Schema::hasTable('settings') ? Setting::cabinet() : ['nom' => config('app.name')]; }
                catch (\Throwable $e) { $cab = ['nom' => config('app.name')]; }
            }
            $view->with('cab', $cab);
        });
    }
}
