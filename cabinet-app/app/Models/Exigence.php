<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exigence extends Model
{
    protected $table = 'exigences';
    protected $guarded = [];
    protected $casts = ['date_limite'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
}
