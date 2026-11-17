<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Rappel : rappels de salaire (type « rappel »). options : réglages propres à une paie séparée,
 * pour un rappel {du, au (mois AAAA-MM de la période rappelée), augmentation, unite (% ou TND)}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paies', fn (Blueprint $t) => $t->json('options')->nullable());
    }

    public function down(): void
    {
        Schema::table('paies', fn (Blueprint $t) => $t->dropColumn('options'));
    }
};
