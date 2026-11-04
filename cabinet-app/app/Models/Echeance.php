<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Echeance extends Model
{
    protected $table = 'echeances';
    protected $guarded = [];
    protected $casts = ['date_echeance'=>'date','accomplie_le'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
    public function getFaiteAttribute(): bool { return $this->accomplie_le !== null; }
    public function joursRestants(): int { return (int) now()->startOfDay()->diffInDays($this->date_echeance, false); }
}
