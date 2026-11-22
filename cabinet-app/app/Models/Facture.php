<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Facture extends Model
{
    use AppartientAuCabinet;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'echeance' => 'date', 'retenue_source' => 'boolean', 'teif_signe_le' => 'datetime', 'teif_depose_le' => 'datetime'];

    /** États de la facture électronique (El Fatoora). */
    public const STATUTS_TEIF = ['signee' => ['Signée électroniquement', 'amber'], 'deposee' => ['Déposée sur El Fatoora', 'amber'], 'validee' => ['Validée par TTN', 'green'], 'rejetee' => ['Rejetée par El Fatoora', 'red']];

    public const STATUTS_FACTURE = [0 => ['À envoyer', 'gray'], 1 => ['Envoyée', 'amber'], 2 => ['Payée', 'green']];
    public const STATUTS_DEVIS = [0 => ['Brouillon', 'gray'], 1 => ['Envoyé', 'amber'], 2 => ['Accepté', 'green'], 3 => ['Refusé', 'red']];

    public function client() { return $this->belongsTo(Client::class); }
    public function lignes() { return $this->hasMany(FactureLigne::class)->orderBy('ordre'); }
    public function devis() { return $this->belongsTo(Facture::class, 'devis_id'); }

    public function estDevis(): bool { return $this->type === 'devis'; }

    /** Déposée ou validée sur El Fatoora : plus de modification ni de suppression. */
    public function teifVerrouillee(): bool { return in_array($this->teif_statut, ['deposee', 'validee'], true); }

    public function totaux(): array
    {
        $ht = round($this->lignes->sum(fn ($l) => $l->quantite * $l->prix_unitaire), 3);
        $tva = round($ht * $this->tva_taux / 100, 3);
        $tim = $this->estDevis() ? 0 : (float) $this->timbre;
        return ['ht' => $ht, 'tva' => $tva, 'timbre' => $tim, 'ttc' => round($ht + $tva + $tim, 3)];
    }

    public function statutLibelle(): array
    {
        $s = $this->estDevis() ? self::STATUTS_DEVIS : self::STATUTS_FACTURE;
        return $s[$this->statut] ?? $s[0];
    }

    public function enRetard(): bool
    {
        return ! $this->estDevis() && $this->statut < 2 && $this->echeance && $this->echeance->lt(now()->startOfDay());
    }

    public static function prochainNumero(string $type = 'facture'): string
    {
        $pre = $type === 'devis' ? 'DEV' : 'FAC';
        $y = now()->year;
        $max = static::withoutGlobalScope('dossiers')->where('type', $type)->where('numero', 'like', "$pre-$y-%")->pluck('numero')
            ->map(fn ($n) => (int) explode('-', $n)[2])->max() ?? 0;
        return sprintf('%s-%d-%03d', $pre, $y, $max + 1);
    }
}
