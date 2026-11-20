<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contrat Dignité : les cotisations sont prises en charge par l'État jusqu'à 600 DT par mois et dues aux taux du régime général
 * au-delà ; le taux patronal réduit (0,5 %) du profil standard est retiré.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('paie_profils')->where('contrat_dignite', true)->where('cnss_patronale', 0.5)->update(['cnss_patronale' => null]);
    }

    public function down(): void
    {
        DB::table('paie_profils')->where('contrat_dignite', true)->whereNull('cnss_patronale')->update(['cnss_patronale' => 0.5]);
    }
};
