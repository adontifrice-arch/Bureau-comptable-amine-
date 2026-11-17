<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Régularisation de l'IRPP par salarié (fiche employé, colonnes Reg et RegP de Saisie Paie) :
 * regul_irpp = régularisation mensuelle de l'IRPP ; regul_primes = régularisation sur les primes / autres / rappel.
 * Vide : réglage du dossier (Fichier → Renseignements employeur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salaries', function (Blueprint $t) {
            $t->boolean('regul_irpp')->nullable();
            $t->boolean('regul_primes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salaries', fn (Blueprint $t) => $t->dropColumn(['regul_irpp', 'regul_primes']));
    }
};
