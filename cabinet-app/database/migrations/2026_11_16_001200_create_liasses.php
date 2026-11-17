<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liasse fiscale (cahier des charges CIMF, cas général) : par dossier et exercice, l'entête du dépôt
 * (acte, nature, activité, unité…) et les montants saisis qui remplacent ceux issus de la balance (F6001 à F6005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liasses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('annee');
            $t->json('entete')->nullable();
            $t->json('saisies')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'annee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liasses');
    }
};
