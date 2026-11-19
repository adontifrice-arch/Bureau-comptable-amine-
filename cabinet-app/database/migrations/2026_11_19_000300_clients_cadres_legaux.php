<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadre légal de l'activité de l'entreprise (jusqu'à 5 lignes, comme sur la déclaration CNSS) : cadre légal,
 * organisme et n° de la déclaration d'investissement (API, APIA…), date de la déclaration, date d'entrée en activité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->json('cadres_legaux')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->dropColumn('cadres_legaux');
        });
    }
};
