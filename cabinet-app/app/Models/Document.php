<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use AppartientAuCabinet;

    protected $table = 'documents';
    protected $guarded = [];
    protected $casts = ['date'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
    public function user() { return $this->belongsTo(User::class); }
}
