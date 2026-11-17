<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Paie extends Model
{
    use AppartientAuCabinet;

    /** Types de paie : la paie ordinaire du mois et les paies spéciales de Paie → Saisie Autre. */
    public const TYPES = ['normale' => 'Paie du mois', 'conge' => 'Congé', 'm13' => '13ème mois', 'm14' => '14ème mois', 'm15' => '15ème mois', 'm16' => '16ème mois', 'm17' => '17ème mois', 'm18' => '18ème mois', 'prime' => 'Prime', 'rappel' => 'Rappel', 'stc' => 'Solde de tout compte'];
    /** Paies séparées, identifiées par leur libellé (une prime, un rappel, un solde de tout compte) et ouvertes depuis leur menu. */
    public const SEPAREES = ['prime', 'rappel', 'stc'];

    public function estStc(): bool
    {
        return $this->type === 'stc';
    }
    /** Paies spéciales de Saisie Autre (le type « prime » relève du menu Prime). */
    public const SPECIALES = ['conge', 'm13', 'm14', 'm15', 'm16', 'm17', 'm18'];

    protected $table = 'paies';
    protected $guarded = [];
    protected $attributes = ['type' => 'normale', 'libelle' => ''];

    public function rubrique() { return $this->belongsTo(PaieRubrique::class, 'rubrique_id'); }

    public function estPrime(): bool
    {
        return $this->type === 'prime';
    }

    /** Nom court : « 13ème mois », « Prime de bilan ». */
    public function nomType(): string
    {
        return match ($this->type) {
            'prime' => $this->libelle ?: 'Prime',
            'rappel' => $this->libelle ?: 'Rappel de salaire',
            'stc' => $this->libelle ?: 'Solde de tout compte',
            default => self::TYPES[$this->type] ?? $this->type,
        };
    }
    protected $casts = ['pointage'=>'array','primes'=>'array','rubriques'=>'array','resultats'=>'array','totaux'=>'array','options'=>'array'];

    public function estRappel(): bool
    {
        return $this->type === 'rappel';
    }

    /** Mois de la période rappelée (rappel de salaire), du premier au dernier. */
    public function moisRappeles(): array
    {
        $du = $this->options['du'] ?? null; $au = $this->options['au'] ?? $du;
        if (! $du || ! preg_match('/^\d{4}-\d{2}$/', $du) || ! preg_match('/^\d{4}-\d{2}$/', (string) $au) || $au < $du) return [];
        $out = [];
        for ($m = \Carbon\Carbon::parse($du.'-01'); $m->format('Y-m') <= $au && count($out) < 36; $m->addMonth()) $out[] = $m->format('Y-m');
        return $out;
    }
    public function client() { return $this->belongsTo(Client::class); }

    /** Paie ordinaire du mois (hors paies spéciales). */
    public function scopeNormale(Builder $q): Builder
    {
        return $q->where('type', 'normale');
    }

    public function estSpeciale(): bool
    {
        return ($this->type ?? 'normale') !== 'normale';
    }

    /** « Paie de novembre 2026 » ou « 13ème mois — déclaré avec novembre 2026 ». */
    public function libelle(): string
    {
        return $this->estSpeciale() ? $this->nomType().' — '.($this->estPrime() ? 'déclarée' : 'déclaré').' avec '.mois_fr($this->mois) : 'Paie de '.mois_fr($this->mois);
    }
}
