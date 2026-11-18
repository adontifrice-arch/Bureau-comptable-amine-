<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lettrage des lignes d'écriture : une lettre (A, B… AA) rapproche les débits et crédits soldés d'un même compte. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $t) {
            $t->string('lettre', 6)->nullable()->index();
            $t->date('lettre_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $t) {
            $t->dropIndex(['lettre']);
            $t->dropColumn(['lettre', 'lettre_le']);
        });
    }
};
