<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionAudit extends Model
{
    protected $table = 'missions_audit';
    protected $guarded = [];
    protected $casts = ['date_designation'=>'date','qci'=>'array','travaux'=>'array','revue'=>'array','rapport'=>'array'];
    public function client() { return $this->belongsTo(Client::class); }
    public function constats() { return $this->hasMany(ConstatAudit::class, 'mission_id'); }
}
