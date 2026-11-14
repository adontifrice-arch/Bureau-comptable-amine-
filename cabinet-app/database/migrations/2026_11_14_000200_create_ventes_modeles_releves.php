<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saisie rapide en comptabilité :
 * - factures de vente du dossier client (journal VTE), validées par le chef comptable comme les factures reçues ;
 * - modèles de saisie des factures d'achat et de vente ;
 * - relevés bancaires importés (Excel, CSV, Word, PDF) et leurs lignes, comptabilisées au journal BQ.
 * Les paramètres de saisie du dossier (comptes et taux par défaut, règles d'affectation) sont dans settings (clé saisie.{client}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_ventes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('tiers');                              // client acheteur
            $t->string('matricule_fiscal')->nullable();
            $t->string('reference')->nullable();
            $t->string('description')->nullable();
            $t->decimal('ht', 14, 3);
            $t->decimal('tva_taux', 5, 2)->default(19);
            $t->decimal('tva', 14, 3)->default(0);
            $t->decimal('timbre', 10, 3)->default(0);
            $t->string('compte')->nullable();                 // compte de produit (70x)
            $t->string('statut')->default('prop');            // prop | val | rej
            $t->string('motif_rejet')->nullable();
            $t->string('fichier')->nullable();
            $t->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['client_id', 'date']);
        });

        Schema::create('modeles_saisie', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('type', 6);                            // achat | vente
            $t->string('nom');
            $t->string('tiers')->nullable();                  // fournisseur ou client
            $t->string('matricule_fiscal')->nullable();
            $t->string('description')->nullable();
            $t->string('compte')->nullable();
            $t->decimal('tva_taux', 5, 2)->default(19);
            $t->decimal('timbre', 10, 3)->default(0);
            $t->decimal('retenue_taux', 5, 2)->default(0);
            $t->boolean('tva_deductible')->default(true);
            $t->timestamps();
            $t->index(['client_id', 'type']);
        });

        Schema::create('releves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('nom_fichier');
            $t->string('fichier')->nullable();
            $t->string('compte_banque', 20)->default('532');
            $t->string('methode', 10);                        // csv | xlsx | docx | pdf | ia
            $t->foreignId('importe_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('releve_lignes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('releve_id')->constrained('releves')->cascadeOnDelete();
            $t->date('date');
            $t->string('libelle');
            $t->decimal('debit', 14, 3)->default(0);          // sortie de la banque (débit du relevé)
            $t->decimal('credit', 14, 3)->default(0);         // entrée en banque (crédit du relevé)
            $t->string('compte')->nullable();                 // contrepartie
            $t->boolean('sens_incertain')->default(false);    // PDF sans solde : sens à vérifier
            $t->string('statut', 4)->default('att');          // att | cpt | ign
            $t->foreignId('ecriture_id')->nullable()->constrained('ecritures')->nullOnDelete();
            $t->timestamps();
            $t->index(['client_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releve_lignes');
        Schema::dropIfExists('releves');
        Schema::dropIfExists('modeles_saisie');
        Schema::dropIfExists('factures_ventes');
    }
};
