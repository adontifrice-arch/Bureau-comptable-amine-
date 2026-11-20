<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Associe extends Model
{
    use AppartientAuCabinet;

    protected $table = 'associes';
    protected $guarded = [];
    protected $casts = ['cin_date'=>'date','naissance_date'=>'date','passeport_date'=>'date','passeport_validite'=>'date'];

    /** Champs saisis dans le formulaire de la fiche client. */
    public const CHAMPS = ['civilite', 'nom', 'cin', 'cin_date', 'cin_lieu', 'carte_sejour', 'passeport', 'passeport_date', 'passeport_validite',
        'nationalite', 'adresse', 'naissance_date', 'naissance_lieu', 'parts', 'parts_de', 'parts_a', 'apport'];
    public function client() { return $this->belongsTo(Client::class); }
}
