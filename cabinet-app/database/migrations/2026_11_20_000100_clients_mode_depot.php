<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Personne morale : dépôt des déclarations par télé-déclaration et télé-paiement (échéance le 20) ou à la recette des finances (le 28). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('mode_depot')->nullable();   // teledeclaration | recette
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->dropColumn('mode_depot');
        });
    }
};
