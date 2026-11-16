<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paie, Fichier → Renseignements employeur : fiche complète de l'employeur et paramètres de paie propres au dossier
 * (FOPROLOS, TFP, taux CNSS, accident du travail, arrondi, régularisation de l'IRPP, SMIG, tri, format de la fiche de paie, logo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->json('paie_config')->nullable());
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('paie_config'));
    }
};
