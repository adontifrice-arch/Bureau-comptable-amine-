<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Acte extends Model
{
    use AppartientAuCabinet;

    protected $table = 'actes';
    protected $guarded = [];
    protected $casts = ['date'=>'date', 'donnees'=>'array'];
    public function client() { return $this->belongsTo(Client::class); }
    public function modele() { return $this->belongsTo(ModeleActe::class, 'modele_id'); }
}
