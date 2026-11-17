<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paies spéciales (Paie → Saisie Autre) : congé, 13ème à 18ème mois. Une paie est identifiée par son type et par le mois
 * avec lequel elle est déclarée (colonne mois) ; la paie ordinaire a le type « normale ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paies', function (Blueprint $t) {
            $t->string('type', 10)->default('normale');
        });
        Schema::table('paies', function (Blueprint $t) {
            $t->dropUnique(['client_id', 'mois']);
            $t->unique(['client_id', 'mois', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('paies', function (Blueprint $t) {
            $t->dropUnique(['client_id', 'mois', 'type']);
        });
        Schema::table('paies', function (Blueprint $t) {
            $t->unique(['client_id', 'mois']);
            $t->dropColumn('type');
        });
    }
};
