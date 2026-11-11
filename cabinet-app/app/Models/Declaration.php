<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Declaration extends Model
{
    use AppartientAuCabinet;

    protected $table = 'declarations';
    protected $guarded = [];
    protected $casts = ['champs'=>'array','annexes'=>'array','validee_le'=>'date','deposee_le'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
    public function verrouillee(): bool { return $this->statut !== 'brouillon'; }
}
