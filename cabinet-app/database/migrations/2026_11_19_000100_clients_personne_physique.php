<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche client personne physique : activité exercée, catégories de revenus (impôt sur le revenu) et régime d'imposition.
 * L'identité de la personne physique (CIN, passeport, naissance…) reprend les colonnes gerant_* de la fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('activite')->nullable();
            $t->json('categories_revenus')->nullable();
            $t->string('regime_imposition')->nullable();   // reel | forfaitaire
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->dropColumn(['activite', 'categories_revenus', 'regime_imposition']);
        });
    }
};
