<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acomptes provisionnels : seul l'acompte exigible dans le mois est affiché. Les acomptes des mois suivants, créés à l'avance
 * et non cochés, sont retirés ; ils sont recréés à l'entrée de leur mois de paiement. Les acomptes cochés ou supprimés sont conservés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('echeances')->where('auto', 'ap')->whereNull('accomplie_le')->whereNull('deleted_at')
            ->where('date_echeance', '>', now()->endOfMonth()->format('Y-m-d'))->delete();
    }

    public function down(): void {}
};
