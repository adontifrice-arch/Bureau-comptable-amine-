<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Prêt au personnel (société) ou prêt CNSS : remboursé par nb_echeances mensualités de « echeance » retenues sur le net à partir de
 * premier_mois ; la dernière mensualité est ajustée pour solder exactement le montant.
 */
class Pret extends Model
{
    use AppartientAuCabinet;

    public const TYPES = ['societe' => 'Prêt Société', 'cnss' => 'Prêt CNSS'];

    protected $table = 'prets';
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'montant' => 'float', 'echeance' => 'float', 'nb_echeances' => 'integer'];

    public function salarie() { return $this->belongsTo(Salarie::class); }

    /** Échéancier : [AAAA-MM => montant retenu]. */
    public function echeancier(): array
    {
        $out = [];
        $reste = (float) $this->montant;
        $m = Carbon::parse($this->premier_mois.'-01');
        for ($i = 1; $i <= $this->nb_echeances && $reste > 0.0005; $i++, $m->addMonth()) {
            $e = $i === $this->nb_echeances ? $reste : min((float) $this->echeance, $reste);
            $out[$m->format('Y-m')] = r3($e);
            $reste = r3($reste - $e);
        }
        return $out;
    }

    /** Montant retenu sur la paie du mois. */
    public function echeanceDuMois(string $mois): float
    {
        return $this->echeancier()[$mois] ?? 0.0;
    }

    /**
     * Remboursé jusqu'au mois inclus, et reste dû. Un solde de tout compte du salarié déclaré au plus tard ce mois-là solde le prêt
     * (le reste dû y est retenu), sauf si sa retenue de prêt a été mise à 0.
     */
    public function situation(string $mois): array
    {
        if ($stc = $this->soldeParStc($mois)) return ['rembourse' => r3((float) $this->montant), 'reste' => 0.0, 'stc' => $stc];
        $rembourse = r3(array_sum(array_filter($this->echeancier(), fn ($v, $m) => $m <= $mois, ARRAY_FILTER_USE_BOTH)));
        return ['rembourse' => $rembourse, 'reste' => r3((float) $this->montant - $rembourse)];
    }

    /** Mois du solde de tout compte qui retient le reste dû de ce prêt (au plus tard $mois), ou null. */
    public function soldeParStc(string $mois): ?string
    {
        $stc = Paie::where('client_id', $this->client_id)->where('type', 'stc')->where('mois', '<=', $mois)->orderBy('mois')->get()
            ->first(fn ($p) => (int) ($p->options['salarie_id'] ?? 0) === (int) $this->salarie_id
                && ! (($v = ($p->pointage ?? [])[$this->salarie_id]['pret_'.$this->type] ?? null) !== null && (float) $v == 0.0));
        return $stc?->mois;
    }

    public function dernierMois(): ?string
    {
        $e = $this->echeancier();
        return $e ? array_key_last($e) : null;
    }
}
