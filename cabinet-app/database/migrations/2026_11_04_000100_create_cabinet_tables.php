<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paramètres du cabinet (clé / valeur JSON)
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        // ---------- Clients ----------
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('nom');
            $t->string('forme')->nullable();
            $t->string('type_personne')->nullable();          // morale | physique
            $t->string('denomination_commerciale')->nullable();
            $t->string('residence')->nullable();              // residente | non_residente
            $t->string('regime_export')->nullable();          // totale | partielle
            $t->string('mission')->nullable();
            $t->decimal('honoraires_mensuels', 14, 3)->default(0);
            $t->string('matricule_fiscal')->nullable();
            $t->string('compte_comptable')->nullable();
            $t->string('matricule_cnss')->nullable();
            $t->string('matricule_independant')->nullable();
            $t->decimal('capital', 16, 3)->default(0);
            $t->unsignedInteger('nb_parts')->nullable();
            $t->decimal('valeur_nominale', 14, 3)->nullable();
            $t->string('adresse')->nullable();
            $t->string('email')->nullable();
            $t->string('telephone')->nullable();
            // Gérant
            $t->string('gerant_nom')->nullable();
            $t->string('gerant_cin')->nullable();
            $t->date('gerant_cin_date')->nullable();
            $t->string('gerant_cin_lieu')->nullable();
            $t->string('gerant_carte_sejour')->nullable();
            $t->string('gerant_passeport')->nullable();
            $t->date('gerant_passeport_date')->nullable();
            $t->date('gerant_passeport_validite')->nullable();
            $t->string('gerant_nationalite')->nullable();
            $t->string('gerant_adresse')->nullable();
            $t->date('gerant_naissance_date')->nullable();
            $t->string('gerant_naissance_lieu')->nullable();
            $t->date('gerant_nomination_date')->nullable();
            $t->string('gerant_nomination_duree')->nullable();
            $t->boolean('gerant_associe')->nullable();
            $t->unsignedInteger('gerant_parts')->nullable();
            $t->unsignedInteger('gerant_parts_de')->nullable();
            $t->unsignedInteger('gerant_parts_a')->nullable();
            $t->string('gerant_apport')->nullable();          // numeraire | nature
            $t->timestamps();
        });

        Schema::create('associes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('nom');
            $t->string('cin')->nullable();
            $t->date('cin_date')->nullable();
            $t->string('cin_lieu')->nullable();
            $t->string('passeport')->nullable();
            $t->string('nationalite')->nullable();
            $t->string('adresse')->nullable();
            $t->date('naissance_date')->nullable();
            $t->string('naissance_lieu')->nullable();
            $t->unsignedInteger('parts')->default(0);
            $t->unsignedInteger('parts_de')->nullable();
            $t->unsignedInteger('parts_a')->nullable();
            $t->string('apport')->default('numeraire');
            $t->timestamps();
        });

        // ---------- Échéances & exigences ----------
        Schema::create('echeances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('categorie')->nullable();
            $t->string('intitule');
            $t->date('date_echeance');
            $t->date('accomplie_le')->nullable();
            $t->string('serie')->nullable();     // identifiant de série générée
            $t->string('auto')->nullable();      // ap = acomptes provisionnels
            $t->timestamps();
            $t->index(['client_id', 'date_echeance']);
        });

        Schema::create('exigences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('intitule');
            $t->string('organisme')->nullable();
            $t->string('periodicite')->nullable();
            $t->date('date_limite')->nullable();
            $t->text('remarque')->nullable();
            $t->timestamps();
        });

        // ---------- Facturation ----------
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->string('nom');
            $t->decimal('prix', 14, 3)->default(0);
            $t->timestamps();
        });

        Schema::create('factures', function (Blueprint $t) {
            $t->id();
            $t->string('type')->default('facture');          // facture | devis
            $t->string('numero')->index();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->date('echeance')->nullable();
            $t->decimal('tva_taux', 5, 2)->default(19);
            $t->decimal('timbre', 10, 3)->default(0);
            $t->unsignedTinyInteger('statut')->default(0);
            // facture : 0 à envoyer, 1 envoyée, 2 payée — devis : 0 brouillon, 1 envoyé, 2 accepté, 3 refusé
            $t->boolean('retenue_source')->default(false);
            $t->foreignId('devis_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('facture_lignes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facture_id')->constrained()->cascadeOnDelete();
            $t->string('designation');
            $t->decimal('quantite', 12, 3)->default(1);
            $t->decimal('prix_unitaire', 14, 3)->default(0);
            $t->unsignedInteger('ordre')->default(0);
        });

        // ---------- Notes de frais ----------
        Schema::create('notes_frais_documents', function (Blueprint $t) {
            $t->id();
            $t->string('numero');
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->text('observations')->nullable();
            $t->json('lignes');
            $t->timestamps();
        });

        Schema::create('notes_frais', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('description');
            $t->string('categorie')->nullable();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('montant', 14, 3);
            $t->boolean('rembourse')->default(false);
            $t->string('justificatif')->nullable();
            $t->foreignId('document_id')->nullable()->constrained('notes_frais_documents')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        // ---------- Dépenses du cabinet ----------
        Schema::create('depenses', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->date('date_paiement')->nullable();
            $t->string('fournisseur');
            $t->string('reference')->nullable();
            $t->string('type_identifiant')->default('MatriculeFiscal');
            $t->string('identifiant')->nullable();
            $t->string('adresse')->nullable();
            $t->decimal('ht', 14, 3);
            $t->decimal('tva_taux', 5, 2)->default(19);
            $t->decimal('tva', 14, 3)->default(0);
            $t->decimal('timbre', 10, 3)->default(0);
            $t->boolean('retenue')->default(false);
            $t->decimal('retenue_taux', 5, 2)->default(0);
            $t->decimal('retenue_montant', 14, 3)->nullable();
            $t->timestamps();
        });

        // ---------- Interne ----------
        Schema::create('rendez_vous', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('objet');
            $t->date('date');
            $t->string('heure')->nullable();
            $t->string('lieu')->nullable();
            $t->string('participants')->nullable();
            $t->string('statut')->default('Prévu');
            $t->text('compte_rendu')->nullable();
            $t->text('actions')->nullable();
            $t->timestamps();
        });

        Schema::create('notes_internes', function (Blueprint $t) {
            $t->id();
            $t->string('type')->default('note');     // note | autre
            $t->string('titre');
            $t->string('categorie')->nullable();
            $t->text('contenu')->nullable();
            $t->date('date')->nullable();
            $t->date('rappel_le')->nullable();
            $t->boolean('fait')->default(false);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        // ---------- Comptabilité ----------
        Schema::create('comptes', function (Blueprint $t) {
            $t->id();
            $t->string('numero')->unique();
            $t->string('libelle');
            $t->boolean('personnalise')->default(false);
        });

        Schema::create('factures_recues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('fournisseur');
            $t->string('matricule_fiscal')->nullable();
            $t->string('reference')->nullable();
            $t->string('description')->nullable();
            $t->decimal('ht', 14, 3);
            $t->decimal('tva_taux', 5, 2)->default(19);
            $t->decimal('tva', 14, 3)->default(0);
            $t->decimal('timbre', 10, 3)->default(0);
            $t->string('compte')->nullable();
            $t->boolean('tva_deductible')->default(true);
            $t->decimal('retenue_taux', 5, 2)->default(0);
            $t->string('statut')->default('prop');     // prop | val | rej
            $t->string('motif_rejet')->nullable();
            $t->string('fichier')->nullable();
            $t->json('anomalies')->nullable();
            $t->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('ecritures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('journal', 10)->default('OD');   // ACH, VTE, PAI, BQ, OD
            $t->string('libelle');
            $t->string('source')->nullable()->index();  // fr:12, paie:5
            $t->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['client_id', 'date']);
        });

        Schema::create('ecriture_lignes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ecriture_id')->constrained()->cascadeOnDelete();
            $t->string('compte');
            $t->decimal('debit', 16, 3)->default(0);
            $t->decimal('credit', 16, 3)->default(0);
        });

        // ---------- Paie ----------
        Schema::create('salaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('nom');
            $t->string('cin')->nullable();
            $t->string('cnss')->nullable();
            $t->string('poste')->nullable();
            $t->decimal('salaire_base', 14, 3);
            $t->boolean('chef_famille')->default(false);
            $t->unsignedTinyInteger('enfants')->default(0);
            $t->date('date_embauche')->nullable();
            $t->timestamps();
        });

        Schema::create('paies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('mois', 7);                     // 2026-10
            $t->json('primes')->nullable();            // {salarie_id: montant}
            $t->json('resultats')->nullable();         // détail par salarié
            $t->json('totaux')->nullable();
            $t->string('statut')->default('brouillon'); // brouillon | transmise | val
            $t->timestamps();
            $t->unique(['client_id', 'mois']);
        });

        // ---------- Liasse, déclarations ----------
        Schema::create('passages_fiscaux', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('annee');
            $t->json('donnees');
            $t->timestamps();
            $t->unique(['client_id', 'annee']);
        });

        Schema::create('declarations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10);                    // men | ann | emp
            $t->string('periode', 7);                  // 2026-10 ou 2026
            $t->string('statut')->default('brouillon'); // brouillon | validee | deposee
            $t->json('champs')->nullable();
            $t->json('annexes')->nullable();
            $t->date('validee_le')->nullable();
            $t->date('deposee_le')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'type', 'periode']);
        });

        // ---------- Audit ----------
        Schema::create('missions_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('annee');
            $t->string('type');
            $t->date('date_designation')->nullable();
            $t->string('duree')->nullable();
            $t->string('associe')->nullable();
            $t->string('chef_mission')->nullable();
            $t->string('equipe')->nullable();
            $t->decimal('honoraires', 14, 3)->nullable();
            $t->string('statut')->default('Planifiée');
            $t->text('objet')->nullable();
            $t->json('qci')->nullable();
            $t->json('travaux')->nullable();
            $t->json('revue')->nullable();
            $t->json('rapport')->nullable();
            $t->timestamps();
        });

        Schema::create('constats_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('mission_id')->nullable()->constrained('missions_audit')->nullOnDelete();
            $t->unsignedSmallInteger('annee');
            $t->string('titre');
            $t->string('gravite')->default('Moyenne');
            $t->text('detail')->nullable();
            $t->string('statut')->default('Ouvert');
            $t->string('source')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        // ---------- GED ----------
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('categorie');
            $t->string('nom');
            $t->string('chemin');
            $t->unsignedBigInteger('taille')->default(0);
            $t->string('mime')->nullable();
            $t->date('date')->nullable();
            $t->text('description')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        // ---------- Rédaction juridique ----------
        Schema::create('actes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('titre');
            $t->date('date')->nullable();
            $t->longText('contenu');
            $t->timestamps();
        });

        Schema::create('journal_activite', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('action');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['journal_activite', 'actes', 'documents', 'constats_audit', 'missions_audit', 'declarations', 'passages_fiscaux',
            'paies', 'salaries', 'ecriture_lignes', 'ecritures', 'factures_recues', 'comptes', 'notes_internes', 'rendez_vous',
            'depenses', 'notes_frais', 'notes_frais_documents', 'facture_lignes', 'factures', 'services', 'exigences',
            'echeances', 'associes', 'clients', 'settings'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
