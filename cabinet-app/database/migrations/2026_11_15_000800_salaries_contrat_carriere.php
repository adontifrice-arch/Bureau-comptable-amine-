<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fiche employé : date de naissance, contrat, profil, catégorie et échelon avec leurs dates (Fichier → Notifications). */
return new class extends Migration
{
    private const DATES = ['date_naissance', 'contrat_debut', 'contrat_fin', 'profil_debut', 'profil_fin', 'categorie_debut', 'categorie_fin', 'echelon_debut', 'echelon_fin'];

    public function up(): void
    {
        Schema::table('salaries', function (Blueprint $t) {
            $t->string('type_contrat', 20)->nullable();   // CDI | CDD | SIVP | KARAMA | stage | autre
            $t->string('profil')->nullable();
            $t->string('categorie', 50)->nullable();
            $t->string('echelon', 50)->nullable();
            foreach (self::DATES as $c) $t->date($c)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salaries', fn (Blueprint $t) => $t->dropColumn(['type_contrat', 'profil', 'categorie', 'echelon', ...self::DATES]));
    }
};
