<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Prime : paies de prime (type « prime »), une par rubrique de prime et par mois de déclaration.
 * libelle : nom de la prime (vide pour les autres paies) ; rubrique_id : rubrique de prime d'origine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paies', function (Blueprint $t) {
            $t->string('libelle', 120)->default('');
            $t->unsignedBigInteger('rubrique_id')->nullable();
        });
        Schema::table('paies', function (Blueprint $t) {
            $t->dropUnique(['client_id', 'mois', 'type']);
            $t->unique(['client_id', 'mois', 'type', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::table('paies', function (Blueprint $t) {
            $t->dropUnique(['client_id', 'mois', 'type', 'libelle']);
        });
        Schema::table('paies', function (Blueprint $t) {
            $t->unique(['client_id', 'mois', 'type']);
            $t->dropColumn(['libelle', 'rubrique_id']);
        });
    }
};
