<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Relevé bancaire importé (Excel, CSV, Word ou PDF). */
class Releve extends Model
{
    use AppartientAuCabinet;

    public const METHODES = ['csv' => 'CSV', 'xlsx' => 'Excel', 'docx' => 'Word', 'pdf' => 'PDF', 'ia' => 'PDF lu par l’IA'];

    protected $table = 'releves';
    protected $guarded = [];

    public function lignes() { return $this->hasMany(ReleveLigne::class)->orderBy('date')->orderBy('id'); }
    public function importePar() { return $this->belongsTo(User::class, 'importe_par'); }
}
