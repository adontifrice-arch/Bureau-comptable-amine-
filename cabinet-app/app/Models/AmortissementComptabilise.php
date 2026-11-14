<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Dotation (ou complément) d'une immobilisation passée au journal AMO pour un exercice. */
class AmortissementComptabilise extends Model
{
    use AppartientAuCabinet;

    protected $table = 'amortissements_comptabilises';
    protected $guarded = [];
    protected $casts = ['montant' => 'float'];

    public function immobilisation() { return $this->belongsTo(Immobilisation::class); }
    public function ecriture() { return $this->belongsTo(Ecriture::class); }
}
