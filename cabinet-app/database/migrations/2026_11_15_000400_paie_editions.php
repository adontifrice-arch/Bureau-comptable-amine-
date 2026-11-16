<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paie : informations du salarié nécessaires aux éditions (ordre de virement, bordereau, déclarations). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salaries', function (Blueprint $t) {
            $t->string('matricule', 20)->nullable();
            $t->string('mode_paiement', 10)->default('virement');   // virement | cheque | especes
            $t->string('banque')->nullable();
            $t->string('rib', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salaries', fn (Blueprint $t) => $t->dropColumn(['matricule', 'mode_paiement', 'banque', 'rib']));
    }
};
