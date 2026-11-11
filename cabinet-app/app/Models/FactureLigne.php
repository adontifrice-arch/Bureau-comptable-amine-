<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class FactureLigne extends Model
{
    use AppartientAuCabinet;

    protected $table = 'facture_lignes';
    protected $guarded = [];
    public $timestamps = false;
    public function facture() { return $this->belongsTo(Facture::class); }
    public function getTotalAttribute(): float { return round($this->quantite * $this->prix_unitaire, 3); }
}
