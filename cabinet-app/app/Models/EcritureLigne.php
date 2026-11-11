<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class EcritureLigne extends Model
{
    use AppartientAuCabinet;

    protected $table = 'ecriture_lignes';
    protected $guarded = [];
    public $timestamps = false;
    public function ecriture() { return $this->belongsTo(Ecriture::class); }
}
