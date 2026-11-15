<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écritures comptables supprimées conservées dans « Pièces supprimées » (restaurables) : date, auteur et motif de la suppression.
 * Droit de modifier et de supprimer les écritures accordé à un collaborateur par le chef comptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecritures', function (Blueprint $t) {
            $t->softDeletes();
            $t->foreignId('supprime_par')->nullable()->constrained('users')->nullOnDelete();
            $t->string('motif_suppression')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('droit_ecritures')->default(false);      // collaborateur autorisé à modifier et supprimer les écritures
        });
    }

    public function down(): void
    {
        Schema::table('ecritures', function (Blueprint $t) {
            $t->dropConstrainedForeignId('supprime_par');
            $t->dropColumn(['motif_suppression', 'deleted_at']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('droit_ecritures'));
    }
};
