<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report des congés des années précédentes (fiche employé) : conges_report = jours restant dus au 1er janvier de conges_report_annee.
 * Les années suivantes sont calculées par la paie (acquis par mois − congés pris).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salaries', function (Blueprint $t) {
            $t->decimal('conges_report', 8, 2)->nullable();
            $t->unsignedSmallInteger('conges_report_annee')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salaries', fn (Blueprint $t) => $t->dropColumn(['conges_report', 'conges_report_annee']));
    }
};
