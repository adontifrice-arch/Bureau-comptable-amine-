<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prêt société : mode de versement (virement, chèque, espèces), qui détermine le compte crédité à la comptabilisation (banque ou caisse). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prets', fn (Blueprint $t) => $t->string('mode', 10)->default('virement'));
    }

    public function down(): void
    {
        Schema::table('prets', fn (Blueprint $t) => $t->dropColumn('mode'));
    }
};
