<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Droit de saisir et de valider les écritures (OD, factures reçues et de vente, paies, relevés bancaires,
 * dotations et acquisitions d'immobilisations) : accordé par défaut aux collaborateurs, retirable par le chef comptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('droit_validation')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('droit_validation');
        });
    }
};
