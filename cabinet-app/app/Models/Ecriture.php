<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Écriture comptable. Supprimée, elle reste dans « Pièces supprimées » (restaurable) et n'entre plus dans les états. */
class Ecriture extends Model
{
    use AppartientAuCabinet, SoftDeletes;

    public const JOURNAUX = ['OD' => 'Opérations diverses', 'VTE' => 'Ventes', 'ACH' => 'Achats', 'BQ' => 'Banque', 'CAI' => 'Caisse', 'PAI' => 'Paie', 'AMO' => 'Amortissements', 'AN' => 'À-nouveaux'];

    protected $table = 'ecritures';
    protected $guarded = [];
    protected $casts = ['date'=>'date'];
    public function client() { return $this->belongsTo(Client::class); }
    public function lignes() { return $this->hasMany(EcritureLigne::class); }
    public function validePar() { return $this->belongsTo(User::class, 'valide_par'); }
    public function supprimePar() { return $this->belongsTo(User::class, 'supprime_par'); }

    public function total(): float { return round((float) $this->lignes->sum('debit'), 3); }

    /** Pièce à l'origine de l'écriture (facture, paie, ligne de relevé…), pour l'affichage. */
    public function origine(): ?string
    {
        if (! $this->source) return null;
        [$type, $id] = array_pad(explode(':', $this->source, 2), 2, null);
        return match ($type) {
            'fr' => 'Facture d’achat'.(($f = FactureRecue::find($id)) ? ' '.$f->fournisseur.($f->reference ? ' n° '.$f->reference : '') : ''),
            'fv' => 'Facture de vente'.(($f = FactureVente::find($id)) ? ' '.$f->tiers.($f->reference ? ' n° '.$f->reference : '') : ''),
            'paie' => ($p = Paie::find($id)) ? $p->libelle() : 'Paie',
            'rb' => 'Relevé bancaire'.(($l = ReleveLigne::find($id)) ? ' — '.$l->libelle : ''),
            'immo' => 'Acquisition d’immobilisation'.(($i = Immobilisation::find($id)) ? ' — '.$i->designation : ''),
            'amo' => 'Dotations aux amortissements '.$id,
            'pret' => 'Versement d’un prêt au personnel'.(($p = Pret::with('salarie')->find($id)) ? ' — '.$p->salarie?->nomComplet() : ''),
            default => $this->source,
        };
    }
}
