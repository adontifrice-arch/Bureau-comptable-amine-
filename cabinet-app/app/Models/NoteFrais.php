<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoteFrais extends Model
{
    protected $table = 'notes_frais';
    protected $guarded = [];
    protected $casts = ['date'=>'date','rembourse'=>'boolean'];
    public function client() { return $this->belongsTo(Client::class); }
    public function document() { return $this->belongsTo(NoteFraisDocument::class, 'document_id'); }
}
