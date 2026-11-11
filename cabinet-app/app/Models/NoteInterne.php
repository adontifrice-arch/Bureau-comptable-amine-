<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class NoteInterne extends Model
{
    use AppartientAuCabinet;

    protected $table = 'notes_internes';
    protected $guarded = [];
    protected $casts = ['date'=>'date','rappel_le'=>'date','fait'=>'boolean'];
}
