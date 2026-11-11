<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Exigence extends Model
{
    use AppartientAuCabinet;

    protected $table = 'exigences';
    protected $guarded = [];
    protected $casts = ['date_limite'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
}
