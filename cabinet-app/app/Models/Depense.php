<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Depense extends Model
{
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'date_paiement' => 'date', 'retenue' => 'boolean'];
}
