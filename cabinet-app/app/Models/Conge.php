<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Congé d'un salarié (Congé → Saisie des congés). jours : jours ouvrables de la période (modifiable).
 * Congé payé : payé et décompté du solde ; exceptionnel : payé, non décompté ; sans solde : retiré des jours payés ; maladie : pour mémoire.
 */
class Conge extends Model
{
    use AppartientAuCabinet;

    public const TYPES = ['paye' => 'Congé payé', 'exceptionnel' => 'Congé exceptionnel (payé)', 'sans_solde' => 'Congé sans solde', 'maladie' => 'Congé de maladie'];

    protected $table = 'conges';
    protected $guarded = [];
    protected $casts = ['du' => 'date', 'au' => 'date', 'jours' => 'float'];

    public function salarie() { return $this->belongsTo(Salarie::class); }

    /** Jours ouvrables entre deux dates incluses : dimanches exclus, samedis aussi au régime de 5 jours. */
    public static function joursOuvres(Carbon $du, Carbon $au, bool $cinqJours = false): int
    {
        $n = 0;
        for ($d = $du->copy()->startOfDay(); $d->lte($au); $d->addDay()) {
            if ($d->isSunday() || ($cinqJours && $d->isSaturday())) continue;
            $n++;
        }
        return $n;
    }

    /**
     * Part du congé tombant dans le mois $mois (AAAA-MM) : jours saisis répartis au prorata des jours ouvrables de la période.
     */
    public function joursDansMois(string $mois, bool $cinqJours = false): float
    {
        $debut = Carbon::parse($mois.'-01');
        $fin = $debut->copy()->endOfMonth();
        if ($this->au->lt($debut) || $this->du->gt($fin)) return 0.0;
        $total = static::joursOuvres($this->du, $this->au, $cinqJours);
        if ($total === 0) return 0.0;
        $dedans = static::joursOuvres($this->du->max($debut), $this->au->min($fin), $cinqJours);
        return r3((float) $this->jours * $dedans / $total);
    }
}
