<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Avancement d'un client dans la check-list d'une formalité du RNE (documents cochés). */
class ChecklistRne extends Model
{
    use AppartientAuCabinet;

    protected $table = 'checklists_rne';
    protected $guarded = [];
    protected $casts = ['coches' => 'array'];
}
