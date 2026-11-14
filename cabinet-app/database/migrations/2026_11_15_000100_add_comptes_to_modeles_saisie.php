<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Modèles de saisie : plusieurs comptes proposés, chacun avec son taux de TVA (le premier est appliqué par défaut). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modeles_saisie', function (Blueprint $t) {
            $t->json('comptes')->nullable()->after('compte');
        });
        // Modèles existants : leur compte unique devient la première ligne
        foreach (DB::table('modeles_saisie')->whereNotNull('compte')->where('compte', '!=', '')->get(['id', 'compte', 'tva_taux']) as $m) {
            DB::table('modeles_saisie')->where('id', $m->id)->update(['comptes' => json_encode([['compte' => (string) $m->compte, 'tva_taux' => (float) $m->tva_taux]])]);
        }
    }

    public function down(): void
    {
        Schema::table('modeles_saisie', fn (Blueprint $t) => $t->dropColumn('comptes'));
    }
};
