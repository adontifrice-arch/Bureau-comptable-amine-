<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Acompte → Saisie des acomptes : avances sur salaire versées aux salariés, retenues sur la paie du mois indiqué (mois, AAAA-MM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acomptes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('salarie_id')->constrained('salaries')->cascadeOnDelete();
            $t->date('date');
            $t->decimal('montant', 12, 3);
            $t->string('mode', 10)->default('especes');
            $t->string('mois', 7);
            $t->string('observation')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'mois']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acomptes');
    }
};
