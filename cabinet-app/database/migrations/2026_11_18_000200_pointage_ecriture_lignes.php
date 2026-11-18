<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rapprochement bancaire : date à laquelle une ligne du compte de banque a été pointée avec le relevé. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $t) {
            $t->date('pointe_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $t) {
            $t->dropColumn('pointe_le');
        });
    }
};
