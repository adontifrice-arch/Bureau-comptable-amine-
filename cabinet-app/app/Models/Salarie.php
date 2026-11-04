<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Salarie extends Model
{
    protected $table = 'salaries';
    protected $guarded = [];
    protected $casts = ['chef_famille'=>'boolean','date_embauche'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
}
