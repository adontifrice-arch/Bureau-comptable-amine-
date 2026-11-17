<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Liasse fiscale d'un exercice : entête du dépôt et montants saisis (remplaçant ceux de la balance). */
class Liasse extends Model
{
    use AppartientAuCabinet;

    protected $guarded = [];
    protected $casts = ['entete' => 'array', 'saisies' => 'array'];
}
