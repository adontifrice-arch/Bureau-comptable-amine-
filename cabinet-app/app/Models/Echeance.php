<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Echeance extends Model
{
    use AppartientAuCabinet, SoftDeletes;

    protected $table = 'echeances';
    protected $guarded = [];
    protected $casts = ['date_echeance'=>'date','accomplie_le'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
    public function getFaiteAttribute(): bool { return $this->accomplie_le !== null; }
    public function joursRestants(): int { return (int) now()->startOfDay()->diffInDays($this->date_echeance, false); }
}
