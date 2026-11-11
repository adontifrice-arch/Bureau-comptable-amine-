<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Paie extends Model
{
    use AppartientAuCabinet;

    protected $table = 'paies';
    protected $guarded = [];
    protected $casts = ['primes'=>'array','resultats'=>'array','totaux'=>'array'];
    public function client() { return $this->belongsTo(Client::class); }
}
