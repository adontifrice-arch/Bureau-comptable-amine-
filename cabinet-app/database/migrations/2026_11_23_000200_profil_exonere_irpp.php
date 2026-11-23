<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profil de paie « Exonéré d'IRPP et de CSS » : ni retenue à la source ni contribution sociale de solidarité (contrats CIVP).
 * Les profils CIVP (ou SIVP) déjà créés dans les dossiers reçoivent l'option.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paie_profils', function (Blueprint $t) {
            $t->boolean('exonere_irpp')->default(false)->after('regul_karama');
        });
        DB::table('paie_profils')->whereIn(DB::raw('LOWER(TRIM(nom))'), ['civp', 'sivp'])->update(['exonere_irpp' => true]);
    }

    public function down(): void
    {
        Schema::table('paie_profils', fn (Blueprint $t) => $t->dropColumn('exonere_irpp'));
    }
};
