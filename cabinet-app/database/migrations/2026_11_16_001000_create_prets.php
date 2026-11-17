<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Prêt : prêts au personnel (prêt société) et prêts CNSS retenus par l'employeur. Remboursés par échéances mensuelles
 * retenues sur le net de la paie, à partir du mois premier_mois (AAAA-MM), la dernière échéance soldant le prêt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('salarie_id')->constrained('salaries')->cascadeOnDelete();
            $t->string('type', 10)->default('societe');
            $t->date('date');
            $t->decimal('montant', 12, 3);
            $t->decimal('echeance', 12, 3);
            $t->unsignedSmallInteger('nb_echeances');
            $t->string('premier_mois', 7);
            $t->string('reference', 60)->nullable();
            $t->string('observation')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prets');
    }
};
