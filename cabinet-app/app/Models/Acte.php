<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Acte extends Model
{
    protected $table = 'actes';
    protected $guarded = [];
    protected $casts = ['date'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
}
