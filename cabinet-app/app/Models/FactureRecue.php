<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use App\Models\Concerns\EstVentilee;
use App\Models\Concerns\SaisieSelonModele;
use Illuminate\Database\Eloquent\Model;

class FactureRecue extends Model
{
    use AppartientAuCabinet, EstVentilee, SaisieSelonModele;

    protected $table = 'factures_recues';
    protected $guarded = [];
    protected $casts = ['date'=>'date','tva_deductible'=>'boolean','anomalies'=>'array','lignes'=>'array','tva_suspension'=>'boolean','tva_suspendue'=>'float','suspension_date'=>'date','parametres'=>'array','fodec'=>'float','avance'=>'float','ttc'=>'float'];
    public function client() { return $this->belongsTo(Client::class); }
    public function validePar() { return $this->belongsTo(User::class, 'valide_par'); }
    public function ttc(): float { return $this->ttc !== null ? (float) $this->ttc : round($this->ht + $this->tva + $this->timbre, 3); }
    public function retenue(): float { return $this->retenue_taux > 0 ? round(($this->ht + $this->tva) * $this->retenue_taux / 100, 3) : 0; }

    /**
     * Lignes de l'écriture d'achat : D charge(s) [+ D 4366 TVA] [+ D 6654 timbre] / C fournisseur (401 par défaut, paramétrable) [+ C 432 retenue].
     * Facture ventilée : un débit par compte ; TVA non récupérable : incluse dans la charge de chaque ligne.
     */
    public function lignesEcriture(): array
    {
        if ($this->selonModele()) return $this->lignesSelonModele();
        $tva = (float) $this->tva; $tim = (float) $this->timbre;
        $ttc = round((float) $this->ht + $tva + $tim, 3); $rs = $this->retenue();
        $deductible = $this->tva_deductible && $tva > 0;
        $charges = [];
        foreach ($this->lignesSaisie() as $x) $charges[$x['compte']] = round(($charges[$x['compte']] ?? 0) + $x['ht'] + ($deductible ? 0 : $x['tva']), 3);
        $l = [];
        foreach ($charges as $compte => $montant) $l[] = ['compte'=>(string) $compte,'debit'=>$montant,'credit'=>0];
        if ($deductible) $l[] = ['compte'=>'4366','debit'=>round($tva,3),'credit'=>0];
        if ($tim > 0) $l[] = ['compte'=>'6654','debit'=>$tim,'credit'=>0];
        $l[] = ['compte'=>\App\Services\Saisie::achat((int) $this->client_id)['compte_tiers'] ?: '401','debit'=>0,'credit'=>round($ttc-$rs,3)];
        if ($rs > 0) $l[] = ['compte'=>'432','debit'=>0,'credit'=>$rs];
        return $l;
    }
}
