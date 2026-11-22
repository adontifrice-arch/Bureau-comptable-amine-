<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module RNE : dépôt des états financiers d'un exercice (pièces PDF, approbation, dépôt)
 * et suivi des check-lists des formalités du RNE par client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depots_rne', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('exercice');
            $t->json('pieces')->nullable();                     // pièce => id du document en GED
            $t->boolean('approbation')->default(false);         // états financiers approuvés (AGO tenue)
            $t->date('date_approbation')->nullable();
            $t->string('statut', 12)->default('preparation');   // preparation | depose
            $t->date('date_depot')->nullable();
            $t->string('reference', 60)->nullable();
            $t->text('observations')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'exercice']);
        });

        Schema::create('checklists_rne', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);                              // code de la formalité (RNE M 004.09…)
            $t->json('coches')->nullable();                      // indices des documents fournis
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklists_rne');
        Schema::dropIfExists('depots_rne');
    }
};
