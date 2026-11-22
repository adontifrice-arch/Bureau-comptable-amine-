<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Facture électronique El Fatoora : état de la signature, du dépôt et référence TTN. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $t) {
            $t->string('teif_statut', 20)->nullable();        // signee | deposee | validee | rejetee
            $t->string('teif_fichier')->nullable();           // XML signé (puis validé par TTN), disque local
            $t->timestamp('teif_signe_le')->nullable();
            $t->timestamp('teif_depose_le')->nullable();
            $t->string('teif_reference', 60)->nullable();     // référence unique TTN (I-88)
            $t->text('teif_qr')->nullable();                  // cachet électronique TTN : QR code PNG en base 64
            $t->text('teif_message')->nullable();             // dernier message de la plateforme
        });
    }

    public function down(): void
    {
        Schema::table('factures', fn (Blueprint $t) => $t->dropColumn(['teif_statut', 'teif_fichier', 'teif_signe_le', 'teif_depose_le', 'teif_reference', 'teif_qr', 'teif_message']));
    }
};
