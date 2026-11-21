<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Historique des sauvegardes du cabinet (fichiers ZIP, voir App\Services\Sauvegardes). */
class Sauvegarde extends Model
{
    use AppartientAuCabinet;

    protected $table = 'sauvegardes';
    protected $guarded = [];
    protected $casts = ['elements' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
}
