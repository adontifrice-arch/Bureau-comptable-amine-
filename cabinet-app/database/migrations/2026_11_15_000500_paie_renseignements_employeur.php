<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paie, menu Fichier → Renseignements employeur : activité, bureau CNSS et compte bancaire débité pour l'ordre de virement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('paie_activite')->nullable();
            $t->string('cnss_bureau')->nullable();
            $t->string('paie_banque')->nullable();
            $t->string('paie_rib', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['paie_activite', 'cnss_bureau', 'paie_banque', 'paie_rib']));
    }
};
