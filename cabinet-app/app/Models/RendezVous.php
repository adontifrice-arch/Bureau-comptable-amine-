<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class RendezVous extends Model
{
    use AppartientAuCabinet;

    protected $table = 'rendez_vous';
    protected $guarded = [];
    protected $casts = ['date'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
}
