<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Congé → Saisie des congés : registre des congés des salariés (période, nombre de jours ouvrables, type).
 * Types : paye (congé payé, décompté du solde), exceptionnel (payé, non décompté), sans_solde (non payé), maladie (information).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('salarie_id')->constrained('salaries')->cascadeOnDelete();
            $t->string('type', 15)->default('paye');
            $t->date('du');
            $t->date('au');
            $t->decimal('jours', 6, 2);
            $t->string('observation')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'du']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conges');
    }
};
