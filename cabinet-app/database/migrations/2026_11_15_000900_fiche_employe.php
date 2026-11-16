<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paie, menu Employé → fiche employé complète (onglets État civil, Informations spécifiques, Entrée/Sortie, Règlement,
 * Pièces jointes, Détail contrats), pièces jointes (photo, CIN, contrats, diplômes) et historique des changements de situation.
 */
return new class extends Migration
{
    private const COLONNES = ['titre', 'prenom', 'matricule_pointeuse', 'adresse', 'ville', 'code_postal', 'tel_fixe', 'tel_mobile', 'cin_date', 'carte_sejour',
        'nationalite', 'lieu_naissance', 'situation_familiale', 'enfants_detail', 'parents_charge', 'regime', 'conges_acquis', 'cnss_assujetti', 'cnss_rc',
        'retenue_etranger', 'nb_bulletin', 'assurance_vie', 'cea', 'interets_bancaires', 'stc_solde_total', 'departement', 'etat_presence', 'date_sortie',
        'motif_sortie', 'date_declaration_cnss', 'agence'];

    public function up(): void
    {
        Schema::table('salaries', function (Blueprint $t) {
            // En-tête et état civil
            $t->string('titre', 10)->nullable();                       // M. | Mme | Mlle
            $t->string('prenom')->nullable();
            $t->string('matricule_pointeuse', 30)->nullable();
            $t->string('adresse')->nullable();
            $t->string('ville', 100)->nullable();
            $t->string('code_postal', 10)->nullable();
            $t->string('tel_fixe', 30)->nullable();
            $t->string('tel_mobile', 30)->nullable();
            $t->date('cin_date')->nullable();
            $t->boolean('carte_sejour')->default(false);
            $t->string('nationalite', 50)->nullable();
            $t->string('lieu_naissance', 100)->nullable();
            $t->string('situation_familiale', 15)->nullable();         // celibataire | marie | divorce | veuf
            $t->json('enfants_detail')->nullable();                    // [{a_charge, etudiant, infirme}] × 4 au plus
            $t->unsignedTinyInteger('parents_charge')->default(0);
            // Informations spécifiques
            $t->string('regime', 10)->default('mensuel');              // mensuel | horaire
            $t->decimal('conges_acquis', 6, 2)->nullable();            // jours (mensuel) ou heures (horaire) acquis par mois
            $t->boolean('cnss_assujetti')->default(true);
            $t->boolean('cnss_rc')->default(false);
            $t->boolean('retenue_etranger')->default(false);
            $t->unsignedTinyInteger('nb_bulletin')->default(1);
            $t->decimal('assurance_vie', 12, 3)->nullable();           // montants annuels déductibles
            $t->decimal('cea', 12, 3)->nullable();
            $t->decimal('interets_bancaires', 12, 3)->nullable();
            $t->boolean('stc_solde_total')->default(false);
            // Entrée / sortie, règlement
            $t->string('departement', 100)->nullable();
            $t->string('etat_presence', 10)->default('actif');         // actif | suspendu | sorti
            $t->date('date_sortie')->nullable();
            $t->string('motif_sortie', 50)->nullable();
            $t->date('date_declaration_cnss')->nullable();
            $t->string('agence', 100)->nullable();
        });

        Schema::create('salarie_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('salarie_id')->constrained('salaries')->cascadeOnDelete();
            $t->string('type', 10);                                    // photo | cin1 | cin2 | contrat | diplome
            $t->string('nom');
            $t->string('chemin');
            $t->string('mime', 100)->nullable();
            $t->timestamps();
        });

        Schema::create('salarie_historique', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('salarie_id')->constrained('salaries')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('champ', 30);
            $t->string('libelle');
            $t->string('ancien')->nullable();
            $t->string('nouveau')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salarie_historique');
        Schema::dropIfExists('salarie_documents');
        Schema::table('salaries', fn (Blueprint $t) => $t->dropColumn(self::COLONNES));
    }
};
