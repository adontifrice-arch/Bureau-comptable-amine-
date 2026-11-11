<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Compte extends Model
{
    use AppartientAuCabinet;

    protected $table = 'comptes';
    protected $guarded = [];
    public $timestamps = false;
}
