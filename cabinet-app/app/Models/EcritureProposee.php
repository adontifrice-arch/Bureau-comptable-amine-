<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Écriture lue sur une pièce reçue (traites, chèques, état de caisse, pièce diverse) : passée au journal à sa validation. */
class EcritureProposee extends Model
{
    use AppartientAuCabinet;

    protected $table = 'ecritures_proposees';
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'lignes' => 'array', 'anomalies' => 'array'];

    public function client() { return $this->belongsTo(Client::class); }
    public function document() { return $this->belongsTo(Document::class); }
    public function validePar() { return $this->belongsTo(User::class, 'valide_par'); }

    public function totalDebit(): float { return r3(collect($this->lignes)->sum(fn ($l) => (float) ($l['debit'] ?? 0))); }
    public function totalCredit(): float { return r3(collect($this->lignes)->sum(fn ($l) => (float) ($l['credit'] ?? 0))); }
    public function equilibree(): bool { return count($this->lignes) >= 2 && abs($this->totalDebit() - $this->totalCredit()) < 0.0005 && $this->totalDebit() > 0; }
}
