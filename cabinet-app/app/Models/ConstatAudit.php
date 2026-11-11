<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class ConstatAudit extends Model
{
    use AppartientAuCabinet;

    protected $table = 'constats_audit';
    protected $guarded = [];
    public function mission() { return $this->belongsTo(MissionAudit::class, 'mission_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
