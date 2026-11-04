<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['value' => 'array'];

    public static function get(string $key, $default = null)
    {
        $v = Cache::rememberForever("setting.$key", fn () => optional(static::find($key))->value);
        return $v ?? $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.$key");
    }

    /** Coordonnées du cabinet (logo, adresse, matricule fiscal, banque). */
    public static function cabinet(): array
    {
        return array_merge([
            'nom' => config('app.name'), 'adresse' => '', 'mf' => '', 'telephone' => '', 'email' => '',
            'banque' => '', 'titulaire' => '', 'rib' => '', 'iban' => '', 'bic' => '', 'logo' => null,
            'slogan' => 'Une information de qualité, une décision adéquate.',
        ], static::get('cabinet', []));
    }

    public static function paie(): array
    {
        return array_merge(config('cabinet.PP0'), static::get('paie', []));
    }
}
