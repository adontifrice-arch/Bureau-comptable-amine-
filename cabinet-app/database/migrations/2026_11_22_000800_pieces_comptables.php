<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saisie par pièce comptable : numéro de pièce par journal (00000001…), référence de la pièce,
 * libellé et référence propres à chaque ligne d'écriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecritures', function (Blueprint $t) {
            $t->string('numero', 12)->nullable();
            $t->string('reference', 60)->nullable();
            $t->index(['client_id', 'journal', 'numero']);
        });
        Schema::table('ecriture_lignes', function (Blueprint $t) {
            $t->string('libelle')->nullable();
            $t->string('reference', 60)->nullable();
        });
        // Numérotation des écritures existantes : par dossier et par journal, dans l'ordre des dates.
        $n = [];
        foreach (DB::table('ecritures')->orderBy('date')->orderBy('id')->get(['id', 'client_id', 'journal']) as $e) {
            $k = $e->client_id.'|'.$e->journal;
            $n[$k] = ($n[$k] ?? 0) + 1;
            DB::table('ecritures')->where('id', $e->id)->update(['numero' => str_pad((string) $n[$k], 8, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('ecritures', function (Blueprint $t) {
            $t->dropIndex(['client_id', 'journal', 'numero']);
            $t->dropColumn(['numero', 'reference']);
        });
        Schema::table('ecriture_lignes', fn (Blueprint $t) => $t->dropColumn(['libelle', 'reference']));
    }
};
