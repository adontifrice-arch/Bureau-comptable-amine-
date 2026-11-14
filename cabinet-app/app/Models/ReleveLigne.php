<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Ligne d'un relevé bancaire : débit = sortie de la banque, crédit = entrée (point de vue de la banque). */
class ReleveLigne extends Model
{
    use AppartientAuCabinet;

    public const STATUTS = ['att' => 'À comptabiliser', 'cpt' => 'Comptabilisée', 'ign' => 'Ignorée'];

    protected $table = 'releve_lignes';
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'debit' => 'float', 'credit' => 'float', 'sens_incertain' => 'boolean'];

    public function releve() { return $this->belongsTo(Releve::class); }
    public function ecriture() { return $this->belongsTo(Ecriture::class); }

    /** Montant signé : positif pour un encaissement, négatif pour un décaissement. */
    public function montant(): float { return round($this->credit - $this->debit, 3); }

    /** Encaissement : D banque / C contrepartie ; décaissement : D contrepartie / C banque. */
    public function lignesEcriture(string $banque): array
    {
        $m = abs($this->montant());
        return $this->montant() >= 0
            ? [['compte' => $banque, 'debit' => $m, 'credit' => 0], ['compte' => $this->compte, 'debit' => 0, 'credit' => $m]]
            : [['compte' => $this->compte, 'debit' => $m, 'credit' => 0], ['compte' => $banque, 'debit' => 0, 'credit' => $m]];
    }
}
