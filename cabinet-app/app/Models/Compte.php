<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use App\Services\Compta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Compte extends Model
{
    use AppartientAuCabinet;

    protected $table = 'comptes';
    protected $guarded = [];
    public $timestamps = false;

    /** Installe le plan comptable tunisien (config cabinet.PCN) dans un cabinet, sans toucher aux comptes déjà présents. */
    public static function installerPlan(int $cabinet): int
    {
        $existants = DB::table('comptes')->where('cabinet_id', $cabinet)->pluck('numero')->flip();
        $lignes = collect(config('cabinet.PCN'))->reject(fn ($c) => isset($existants[$c[0]]))
            ->map(fn ($c) => ['cabinet_id' => $cabinet, 'numero' => $c[0], 'libelle' => $c[1], 'personnalise' => false])->values();
        foreach ($lignes->chunk(200) as $lot) DB::table('comptes')->insert($lot->values()->all());
        Compta::oublierPlan();
        return $lignes->count();
    }

    protected static function booted(): void
    {
        static::saved(fn () => Compta::oublierPlan());
        static::deleted(fn () => Compta::oublierPlan());
    }
}
