<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassageFiscal extends Model
{
    protected $table = 'passages_fiscaux';
    protected $guarded = [];
    protected $casts = ['donnees'=>'array'];
}
