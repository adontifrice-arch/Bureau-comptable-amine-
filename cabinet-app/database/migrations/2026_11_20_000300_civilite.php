<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Civilité (Monsieur / Madame) du gérant ou de la personne physique, et de chaque associé : accords dans les actes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->string('gerant_civilite', 5)->nullable()->after('gerant_nom'));
        Schema::table('associes', fn (Blueprint $t) => $t->string('civilite', 5)->nullable()->after('nom'));
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('gerant_civilite'));
        Schema::table('associes', fn (Blueprint $t) => $t->dropColumn('civilite'));
    }
};
