<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Activite extends Model
{
    use AppartientAuCabinet;

    protected $table = 'journal_activite';
    protected $guarded = [];
    public function user() { return $this->belongsTo(User::class); }
}
