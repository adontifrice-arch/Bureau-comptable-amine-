<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoteInterne extends Model
{
    protected $table = 'notes_internes';
    protected $guarded = [];
    protected $casts = ['date'=>'date','rappel_le'=>'date','fait'=>'boolean'];
}
