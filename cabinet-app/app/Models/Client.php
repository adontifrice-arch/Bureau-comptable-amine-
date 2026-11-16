<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use AppartientAuCabinet, HasFactory;

    protected $table = 'clients';
    protected $guarded = [];

    protected $casts = ['paie_config'=>'array','gerant_cin_date'=>'date','gerant_passeport_date'=>'date','gerant_passeport_validite'=>'date','gerant_naissance_date'=>'date','gerant_nomination_date'=>'date','gerant_associe'=>'boolean'];

    public function associes() { return $this->hasMany(Associe::class); }
    public function echeances() { return $this->hasMany(Echeance::class); }
    public function exigences() { return $this->hasMany(Exigence::class); }
    public function factures() { return $this->hasMany(Facture::class); }
    public function documents() { return $this->hasMany(Document::class); }
    public function salaries() { return $this->hasMany(Salarie::class); }
    public function ecritures() { return $this->hasMany(Ecriture::class); }

    public function estPhysique(): bool
    {
        if ($this->type_personne) return $this->type_personne === 'physique';
        return (bool) preg_match('/lib[ée]ral|bnc|bic|individu|physique|artisan|commer[cç]ant/iu', (string) $this->forme);
    }

    public function initiales(): string
    {
        $w = preg_split('/\s+/u', trim($this->nom));
        return mb_strtoupper(mb_substr($w[0] ?? '', 0, 1).mb_substr($w[1] ?? '', 0, 1));
    }
}
