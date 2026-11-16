<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pointage mensuel de la paie : jours travaillés et heures supplémentaires par salarié ({salarie_id: {jours, hs}}). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paies', fn (Blueprint $t) => $t->json('pointage')->nullable());
    }

    public function down(): void
    {
        Schema::table('paies', fn (Blueprint $t) => $t->dropColumn('pointage'));
    }
};
