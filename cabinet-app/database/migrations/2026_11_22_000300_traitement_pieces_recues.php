<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traitement des documents reçus des clients : type de pièce, module destinataire (comptabilité,
 * immobilisations, paie), résultat de la lecture (OCR par l'IA ou import), liens vers les propositions créées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->string('type_piece', 40)->nullable();
            $t->string('destination', 20)->nullable();        // compta | immobilisations | paie | ged
            $t->string('periode', 7)->nullable();              // AAAA-MM indiqué par le client (pointage, relevé, caisse)
            $t->string('traitement', 20)->nullable();          // en_attente | propose | importe | a_saisir | erreur
            $t->text('traitement_message')->nullable();
            $t->timestamp('traitement_at')->nullable();
            $t->json('resultat')->nullable();                  // identifiants des propositions créées, lignes lues…
        });

        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            });
        }

        // Écritures lues sur une pièce (traites, chèques, état de caisse, pièce diverse), à valider avant d'être passées
        Schema::create('ecritures_proposees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->date('date');
            $t->string('journal', 10);
            $t->string('libelle');
            $t->json('lignes');                                // [{compte, libelle, debit, credit}]
            $t->json('anomalies')->nullable();
            $t->string('statut', 10)->default('prop');         // prop | val | rej
            $t->string('motif_rejet')->nullable();
            $t->foreignId('ecriture_id')->nullable()->constrained('ecritures')->nullOnDelete();
            $t->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecritures_proposees');
        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['document_id']);
                $t->dropColumn('document_id');
            });
        }
        Schema::table('documents', function (Blueprint $t) {
            $t->dropColumn(['type_piece', 'destination', 'periode', 'traitement', 'traitement_message', 'traitement_at', 'resultat']);
        });
    }
};
