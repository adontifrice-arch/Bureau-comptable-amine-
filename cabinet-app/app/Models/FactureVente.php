<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use App\Models\Concerns\EstVentilee;
use App\Services\Saisie;
use Illuminate\Database\Eloquent\Model;

/** Facture de vente d'un dossier client, comptabilisée au journal VTE après validation du chef comptable. */
class FactureVente extends Model
{
    use AppartientAuCabinet, EstVentilee;

    protected $table = 'factures_ventes';
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'ht' => 'float', 'tva' => 'float', 'timbre' => 'float', 'tva_taux' => 'float', 'lignes' => 'array'];

    public function client() { return $this->belongsTo(Client::class); }
    public function validePar() { return $this->belongsTo(User::class, 'valide_par'); }
    public function ttc(): float { return round($this->ht + $this->tva + $this->timbre, 3); }

    /** D 411 (TTC) / C 70x (HT de chaque compte), C 4367 TVA collectée, C 437 droit de timbre. */
    public function lignesEcriture(): array
    {
        $p = Saisie::vente($this->client_id);
        $produits = array_map(fn ($c, $ht) => ['compte' => (string) $c, 'debit' => 0, 'credit' => $ht], array_keys($this->htParCompte()), $this->htParCompte());
        return array_values(array_filter([
            ['compte' => $p['compte_tiers'] ?: '411', 'debit' => $this->ttc(), 'credit' => 0],
            ...$produits,
            ['compte' => '4367', 'debit' => 0, 'credit' => round($this->tva, 3)],
            ['compte' => $p['compte_timbre'] ?: '437', 'debit' => 0, 'credit' => round($this->timbre, 3)],
        ], fn ($l) => $l['debit'] > 0 || $l['credit'] > 0));
    }
}
