<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class FactureRecue extends Model
{
    use AppartientAuCabinet;

    protected $table = 'factures_recues';
    protected $guarded = [];
    protected $casts = ['date'=>'date','tva_deductible'=>'boolean','anomalies'=>'array'];
    public function client() { return $this->belongsTo(Client::class); }
    public function validePar() { return $this->belongsTo(User::class, 'valide_par'); }
    public function ttc(): float { return round($this->ht + $this->tva + $this->timbre, 3); }
    public function retenue(): float { return $this->retenue_taux > 0 ? round(($this->ht + $this->tva) * $this->retenue_taux / 100, 3) : 0; }

    /** Lignes de l'écriture d'achat : D charge [+ D 4366] [+ D 6654 timbre] / C 401 [+ C 432 retenue]. */
    public function lignesEcriture(): array
    {
        $ht = (float) $this->ht; $tva = (float) $this->tva; $tim = (float) $this->timbre;
        $ttc = round($ht + $tva + $tim, 3); $rs = $this->retenue();
        $l = [];
        if ($this->tva_deductible && $tva > 0) { $l[] = ['compte'=>$this->compte,'debit'=>$ht,'credit'=>0]; $l[] = ['compte'=>'4366','debit'=>$tva,'credit'=>0]; }
        else $l[] = ['compte'=>$this->compte,'debit'=>round($ht+$tva,3),'credit'=>0];
        if ($tim > 0) $l[] = ['compte'=>'6654','debit'=>$tim,'credit'=>0];
        $l[] = ['compte'=>'401','debit'=>0,'credit'=>round($ttc-$rs,3)];
        if ($rs > 0) $l[] = ['compte'=>'432','debit'=>0,'credit'=>$rs];
        return $l;
    }
}
