<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catégories de revenus : « revenus des valeurs mobilières » et « revenus des capitaux mobiliers » ne forment plus
 * qu'une seule case (capitaux_mobiliers).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')->where('categories_revenus', 'like', '%valeurs_mobilieres%')->orderBy('id')->each(function ($c) {
            $cats = array_map(fn ($k) => $k === 'valeurs_mobilieres' ? 'capitaux_mobiliers' : $k, json_decode($c->categories_revenus, true) ?: []);
            DB::table('clients')->where('id', $c->id)->update(['categories_revenus' => json_encode(array_values(array_unique($cats)))]);
        });
    }

    public function down(): void {}
};
