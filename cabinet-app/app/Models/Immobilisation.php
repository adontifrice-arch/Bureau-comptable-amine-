<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use App\Services\Amortissement;
use Illuminate\Database\Eloquent\Model;

class Immobilisation extends Model
{
    use AppartientAuCabinet;

    protected $table = 'immobilisations';
    protected $guarded = [];
    protected $casts = ['date_mise_service' => 'date', 'date_cession' => 'date', 'valeur_origine' => 'float', 'tva' => 'float',
        'taux' => 'float', 'valeur_residuelle' => 'float'];

    public function client() { return $this->belongsTo(Client::class); }
    public function factureRecue() { return $this->belongsTo(FactureRecue::class, 'facture_recue_id'); }
    public function ecritureAcquisition() { return $this->belongsTo(Ecriture::class, 'acquisition_ecriture_id'); }
    public function dotationsComptabilisees() { return $this->hasMany(AmortissementComptabilise::class); }

    public function libelleNature(): string { return Amortissement::NATURES[$this->nature][0] ?? $this->nature; }
    /** Compte de la facture reçue importée (ex. 213 Logiciels), sinon compte de la nature. */
    public function compteImmobilisation(): string
    {
        $c = (string) $this->factureRecue?->compte;
        return str_starts_with($c, '2') ? $c : (Amortissement::NATURES[$this->nature][1] ?? '22');
    }

    /** Amortissement selon la ventilation du compte d'immobilisation (plan comptable tunisien) ; null pour un terrain. */
    public function compteAmortissement(): ?string
    {
        return $this->amortissable() ? Amortissement::compteAmortissementDe($this->compteImmobilisation()) : null;
    }

    public function amortissable(): bool { return (Amortissement::NATURES[$this->nature][2] ?? null) !== null; }

    /** L'acquisition figure déjà en comptabilité : via la facture reçue validée, ou par l'écriture d'acquisition. */
    public function acquisitionComptabilisee(): bool { return $this->facture_recue_id !== null || $this->acquisition_ecriture_id !== null; }

    /** Montant déjà comptabilisé pour un exercice (somme des dotations et compléments). */
    public function dejaComptabilise(int $exercice): float
    {
        return r3($this->dotationsComptabilisees->where('exercice', $exercice)->sum('montant'));
    }
}
