<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class PassageFiscal extends Model
{
    use AppartientAuCabinet;

    protected $table = 'passages_fiscaux';
    protected $guarded = [];
    protected $casts = ['donnees'=>'array'];
}
