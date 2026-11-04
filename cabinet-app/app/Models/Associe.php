<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Associe extends Model
{
    protected $table = 'associes';
    protected $guarded = [];
    protected $casts = ['cin_date'=>'date','naissance_date'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
}
