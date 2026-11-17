<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saisie de la paie : jours ouvrables, jours fériés, heures par jour et heures ouvrables du mois
 * (vides = valeurs du régime horaire de l'employeur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paies', function (Blueprint $t) {
            $t->decimal('jours_ouvrables', 6, 2)->nullable();
            $t->decimal('jours_feries', 6, 2)->nullable();
            $t->decimal('heures_jour', 6, 2)->nullable();
            $t->decimal('heures_ouvrables', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paies', fn (Blueprint $t) => $t->dropColumn(['jours_ouvrables', 'jours_feries', 'heures_jour', 'heures_ouvrables']));
    }
};
