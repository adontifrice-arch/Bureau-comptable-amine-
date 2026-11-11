<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use AppartientAuCabinet;

    protected $table = 'services';
    protected $guarded = [];
}
