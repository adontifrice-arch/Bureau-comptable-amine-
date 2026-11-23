<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramétrage des factures d'achat et de vente par modèle (« paramètres facture achat / vente ») :
 * journal, comptes TTC, FODEC, avance et timbre avec leur sens, et lignes « objet · compte assiette · taux · compte TVA
 * · compte assiette majorée · taux majoré · compte TVA majorée · débiteur ».
 * Les factures saisies selon un modèle en gardent une copie (colonne parametres), pour que l'écriture ne change pas
 * si le modèle est modifié ensuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modeles_factures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('type', 6);                             // achat | vente
            $t->string('numero', 8);                           // 00000001, par dossier et type
            $t->string('libelle');
            $t->string('journal', 10);
            $t->string('compte_ttc', 20)->nullable();
            $t->boolean('ttc_debit')->default(false);
            $t->string('compte_fodec', 20)->nullable();
            $t->boolean('fodec_debit')->default(false);
            $t->string('compte_avance', 20)->nullable();
            $t->boolean('avance_debit')->default(false);
            $t->string('compte_timbre', 20)->nullable();
            $t->boolean('timbre_debit')->default(false);
            $t->decimal('montant_timbre', 10, 3)->default(0);
            $t->json('lignes')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'type']);
        });

        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('modele_facture_id')->nullable()->constrained('modeles_factures')->nullOnDelete();
                $t->string('compte_tiers', 20)->nullable();
                $t->decimal('fodec', 14, 3)->default(0);
                $t->decimal('avance', 14, 3)->default(0);
                $t->decimal('ttc', 14, 3)->nullable();
                $t->json('parametres')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('modele_facture_id');
                $t->dropColumn(['compte_tiers', 'fodec', 'avance', 'ttc', 'parametres']);
            });
        }
        Schema::dropIfExists('modeles_factures');
    }
};
