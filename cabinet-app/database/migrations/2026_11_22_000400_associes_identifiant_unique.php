<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Identifiant unique (RNE / matricule fiscal) d'un associé qui est une société. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associes', fn (Blueprint $t) => $t->string('identifiant_unique', 30)->nullable()->after('nom'));
    }

    public function down(): void
    {
        Schema::table('associes', fn (Blueprint $t) => $t->dropColumn('identifiant_unique'));
    }
};
