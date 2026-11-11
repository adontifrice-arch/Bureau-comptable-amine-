<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients (spécification §3.2 / §3.3) :
 * - associés : mêmes pièces d'identité que le gérant (carte de séjour, dates du passeport) ;
 * - échéances : suppression logique, pour que la suppression d'un acompte provisionnel
 *   généré soit définitive (il n'est pas recréé lors d'une régénération).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associes', function (Blueprint $t) {
            $t->string('carte_sejour')->nullable()->after('cin_lieu');
            $t->date('passeport_date')->nullable()->after('passeport');
            $t->date('passeport_validite')->nullable()->after('passeport_date');
        });

        Schema::table('echeances', function (Blueprint $t) {
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('echeances', function (Blueprint $t) {
            $t->dropSoftDeletes();
        });

        Schema::table('associes', function (Blueprint $t) {
            $t->dropColumn(['carte_sejour', 'passeport_date', 'passeport_validite']);
        });
    }
};
