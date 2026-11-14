<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Immobilisations et amortissements (spécification §3.8, §5.5). */
return new class extends Migration
{
    /** Comptes ajoutés au plan comptable indicatif (spécification §3.8 et §10). */
    public const COMPTES = [
        ['220', 'Terrains'], ['226', 'Matériel informatique'], ['227', 'Agencements et installations'],
        ['2281', 'Amortissements des immobilisations incorporelles'], ['2282', 'Amortissements des constructions'],
        ['2283', 'Amortissements du matériel et outillage'], ['2284', 'Amortissements du matériel de transport'],
        ['2285', 'Amortissements du mobilier et matériel de bureau'], ['2286', 'Amortissements du matériel informatique'],
        ['2287', 'Amortissements des agencements et installations'],
    ];

    public function up(): void
    {
        Schema::create('immobilisations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('nature', 20);                         // incorporelle, terrain, construction, materiel, transport, mobilier, informatique, agencement
            $t->string('designation');
            $t->date('date_mise_service');
            $t->decimal('valeur_origine', 15, 3);             // HT
            $t->decimal('tva', 15, 3)->default(0);            // TVA déductible
            $t->string('fournisseur')->nullable();
            $t->string('reference')->nullable();
            $t->string('methode', 10)->default('lineaire');   // lineaire | degressif
            $t->decimal('taux', 6, 2);                        // taux linéaire annuel en %
            $t->string('prorata', 5)->default('mois');        // mois | jour | aucun
            $t->decimal('valeur_residuelle', 15, 3)->default(0);
            $t->date('date_cession')->nullable();
            $t->foreignId('facture_recue_id')->nullable()->constrained('factures_recues')->nullOnDelete();
            $t->foreignId('acquisition_ecriture_id')->nullable()->constrained('ecritures')->nullOnDelete();
            $t->timestamps();
            $t->index(['client_id', 'nature']);
        });

        // Dotations comptabilisées, par immobilisation et par exercice (compléments possibles : plusieurs lignes).
        Schema::create('amortissements_comptabilises', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('immobilisation_id')->constrained('immobilisations')->cascadeOnDelete();
            $t->unsignedSmallInteger('exercice');
            $t->decimal('montant', 15, 3);
            $t->foreignId('ecriture_id')->constrained('ecritures')->cascadeOnDelete();   // annuler l'écriture annule la dotation
            $t->timestamps();
            $t->index(['immobilisation_id', 'exercice']);
        });

        // Comptes manquants dans le plan comptable des cabinets existants.
        foreach (DB::table('cabinets')->pluck('id') as $cab) {
            foreach (self::COMPTES as [$n, $l]) {
                if (! DB::table('comptes')->where('cabinet_id', $cab)->where('numero', $n)->exists()) {
                    DB::table('comptes')->insert(['cabinet_id' => $cab, 'numero' => $n, 'libelle' => $l, 'personnalise' => false]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('amortissements_comptabilises');
        Schema::dropIfExists('immobilisations');
        DB::table('comptes')->where('personnalise', false)->whereIn('numero', array_column(self::COMPTES, 0))->delete();
    }
};
