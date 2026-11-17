<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Acompte sur salaire (Acompte → Saisie des acomptes) : versé à une date, retenu sur la paie du mois « mois ». */
class Acompte extends Model
{
    use AppartientAuCabinet;

    protected $table = 'acomptes';
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'montant' => 'float'];

    public function salarie() { return $this->belongsTo(Salarie::class); }
}
