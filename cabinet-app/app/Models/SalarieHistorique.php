<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Changement de situation d'un employé (contrat, qualification, salaire, catégorie, échelon, profil, présence…). */
class SalarieHistorique extends Model
{
    use AppartientAuCabinet;

    protected $table = 'salarie_historique';
    protected $guarded = [];

    public function salarie() { return $this->belongsTo(Salarie::class); }
    public function user() { return $this->belongsTo(User::class); }
}
