<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConstatAudit extends Model
{
    protected $table = 'constats_audit';
    protected $guarded = [];
    public function mission() { return $this->belongsTo(MissionAudit::class, 'mission_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
