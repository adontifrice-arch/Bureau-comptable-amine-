<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paie, menu Rubrique : rubriques de paie du dossier (primes, indemnités, avantages en nature, autres),
 * et saisie mensuelle des rubriques par salarié dans la paie du mois ({salarie_id: {rubrique_id: valeur}}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paie_rubriques', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->unsignedInteger('code');
            $t->string('categorie', 10);                               // prime | indemnite | avantage | autre
            $t->string('nom');
            $t->json('profils')->nullable();                           // profils concernés (aucun = tous les employés)
            // Cases à cocher de la fiche rubrique
            foreach (['prorata_travail', 'prorata_jours_pris', 'prorata_heures_pris', 'conditionnee', 'non_cotisable', 'non_imposable', 'ticket_cadeau',
                'ticket_restaurant', 'a_deduire', 'heures_sup', 'jours_sup', 'heures_nuit', 'note', 'valeur_net', 'depend_note'] as $c) {
                $t->boolean($c)->default(false);
            }
            $t->foreignId('note_id')->nullable();                      // rubrique « note » dont dépend la valeur
            $t->decimal('nb_jours_ouv', 6, 2)->nullable();
            $t->decimal('nb_heures_ouv', 7, 2)->nullable();
            // Valeur de la rubrique
            $t->string('mode', 12)->default('manuelle');               // montant | manuelle | pourcentage | proportion
            $t->decimal('montant', 14, 3)->nullable();
            $t->string('base', 20)->nullable();                        // base | salaire | r:<id de rubrique>
            $t->decimal('taux', 8, 3)->nullable();
            $t->decimal('numerateur', 14, 3)->nullable();
            $t->decimal('denominateur', 14, 3)->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'code']);
        });

        Schema::table('paies', fn (Blueprint $t) => $t->json('rubriques')->nullable());
    }

    public function down(): void
    {
        Schema::table('paies', fn (Blueprint $t) => $t->dropColumn('rubriques'));
        Schema::dropIfExists('paie_rubriques');
    }
};
