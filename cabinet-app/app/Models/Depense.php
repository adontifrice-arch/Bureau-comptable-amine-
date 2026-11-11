<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Depense extends Model
{
    use AppartientAuCabinet;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'date_paiement' => 'date', 'retenue' => 'boolean'];
}
