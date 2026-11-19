<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use App\Support\CabinetCourant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Paramètres d'un cabinet (coordonnées, paie, ordre du jour…), stockés par clé. */
class Setting extends Model
{
    use AppartientAuCabinet;

    protected $guarded = [];
    protected $casts = ['value' => 'array'];

    private static function cle(string $key): string
    {
        return 'setting.'.(CabinetCourant::id() ?? 'aucun').'.'.$key;
    }

    public static function get(string $key, $default = null)
    {
        if (CabinetCourant::id() === null) return $default;
        $v = Cache::rememberForever(self::cle($key), fn () => optional(static::where('key', $key)->first())->value);
        return $v ?? $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cle($key));
    }

    /** Coordonnées du cabinet (logo, adresse, matricule fiscal, banque). */
    public static function cabinet(): array
    {
        return array_merge([
            'nom' => Cabinet::find(CabinetCourant::id())?->nom ?? config('app.name'), 'adresse' => '', 'mf' => '', 'telephone' => '', 'email' => '',
            'banque' => '', 'titulaire' => '', 'rib' => '', 'iban' => '', 'bic' => '', 'logo' => null,
            'slogan' => 'Une information de qualité, une décision adéquate.',
        ], static::get('cabinet', []));
    }

    /** Paramètres fiscaux du cabinet : taux (en %) de la contribution sociale de solidarité des personnes physiques. */
    public static function fiscal(): array
    {
        return array_merge(['css' => 0.5], static::get('fiscal', []));
    }

    public static function paie(): array
    {
        return array_merge(config('cabinet.PP0'), static::get('paie', []));
    }
}
