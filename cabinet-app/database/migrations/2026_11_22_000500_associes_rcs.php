<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Numéro RCS (registre du commerce et des sociétés) d'un associé qui est une société étrangère. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associes', fn (Blueprint $t) => $t->string('rcs', 50)->nullable()->after('identifiant_unique'));
    }

    public function down(): void
    {
        Schema::table('associes', fn (Blueprint $t) => $t->dropColumn('rcs'));
    }
};
