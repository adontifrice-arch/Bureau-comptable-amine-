<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class NoteFraisDocument extends Model
{
    use AppartientAuCabinet;

    protected $table = 'notes_frais_documents';
    protected $guarded = [];
    protected $casts = ['date'=>'date','lignes'=>'array'];
    public function client() { return $this->belongsTo(Client::class); }
    public function notes() { return $this->hasMany(NoteFrais::class, 'document_id'); }
    public function total(): float { return round(collect($this->lignes)->sum(fn($l)=>(float)($l['montant'] ?? 0)), 3); }
}
