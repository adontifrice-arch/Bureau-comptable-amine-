<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Pièce jointe d'une fiche employé : photo, CIN (recto, verso), contrat ou diplôme. Fichier sur le disque privé. */
class SalarieDocument extends Model
{
    use AppartientAuCabinet;

    protected $table = 'salarie_documents';
    protected $guarded = [];

    public const TYPES = ['photo' => 'Photo', 'cin1' => 'CIN — 1re face', 'cin2' => 'CIN — 2e face', 'contrat' => 'Contrat', 'diplome' => 'Diplôme'];

    public function salarie() { return $this->belongsTo(Salarie::class); }
}
