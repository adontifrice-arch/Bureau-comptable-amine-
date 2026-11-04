<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Declaration extends Model
{
    protected $table = 'declarations';
    protected $guarded = [];
    protected $casts = ['champs'=>'array','annexes'=>'array','validee_le'=>'date','deposee_le'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
    public function verrouillee(): bool { return $this->statut !== 'brouillon'; }
}
