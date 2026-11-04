<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcritureLigne extends Model
{
    protected $table = 'ecriture_lignes';
    protected $guarded = [];
    public $timestamps = false;
    public function ecriture() { return $this->belongsTo(Ecriture::class); }
}
