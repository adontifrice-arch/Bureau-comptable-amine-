<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ecriture extends Model
{
    protected $table = 'ecritures';
    protected $guarded = [];
    protected $casts = ['date'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
    public function lignes() { return $this->hasMany(EcritureLigne::class); }
    public function validePar() { return $this->belongsTo(User::class, 'valide_par'); }
}
