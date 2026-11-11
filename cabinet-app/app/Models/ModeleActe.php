<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModeleActe extends Model
{
    protected $table = 'modeles_actes';
    protected $guarded = [];
    protected $casts = ['balises' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
    public function actes() { return $this->hasMany(Acte::class, 'modele_id'); }
}
