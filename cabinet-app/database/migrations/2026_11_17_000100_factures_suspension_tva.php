<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Factures d'achat et de vente en suspension de TVA : la TVA n'est pas facturée, son montant est conservé pour les déclarations. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('tva_suspension')->default(false);
                $t->decimal('tva_suspendue', 14, 3)->default(0);
                $t->string('suspension_ref', 60)->nullable();      // n° attestation / bon de commande visé
                $t->date('suspension_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['tva_suspension', 'tva_suspendue', 'suspension_ref', 'suspension_date']));
        }
    }
};
