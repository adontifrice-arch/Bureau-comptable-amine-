<?php

namespace App\Services;

use App\Services\Releves\LecteurReleve;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Recherche multicritère des listes de la comptabilité (factures d'achat et de vente, lignes de banque, écritures) :
 * période (du … au …), montant (de … à …), partie du libellé, compte. Les critères renseignés se cumulent.
 */
class Recherche
{
    /** Paramètres d'URL de la recherche. */
    public const PARAMETRES = ['du', 'au', 'min', 'max', 'texte', 'cpt'];

    public function __construct(
        public ?string $du = null,
        public ?string $au = null,
        public ?float $min = null,
        public ?float $max = null,
        public ?string $texte = null,
        public ?string $compte = null,
    ) {}

    public static function depuis(Request $r): self
    {
        $date = function ($v) { try { return $v ? Carbon::parse($v)->format('Y-m-d') : null; } catch (\Throwable) { return null; } };
        $montant = fn ($v) => trim((string) $v) === '' ? null : (($m = LecteurReleve::montant($v)) === null ? null : abs($m));
        $s = new self($date($r->input('du')), $date($r->input('au')), $montant($r->input('min')), $montant($r->input('max')),
            trim((string) $r->input('texte')) ?: null, preg_replace('/\D/', '', (string) $r->input('cpt')) ?: null);
        if ($s->du && $s->au && $s->du > $s->au) [$s->du, $s->au] = [$s->au, $s->du];
        if ($s->min !== null && $s->max !== null && $s->min > $s->max) [$s->min, $s->max] = [$s->max, $s->min];
        return $s;
    }

    public function active(): bool
    {
        return $this->nombre() > 0;
    }

    /** Nombre de critères renseignés (une période ou une fourchette compte pour un). */
    public function nombre(): int
    {
        return (int) ($this->du || $this->au) + (int) ($this->min !== null || $this->max !== null) + (int) (bool) $this->texte + (int) (bool) $this->compte;
    }

    /** Paramètres à conserver dans les liens de la page (pagination, filtres). */
    public function parametres(): array
    {
        return array_filter(['du' => $this->du, 'au' => $this->au, 'min' => $this->min, 'max' => $this->max, 'texte' => $this->texte, 'cpt' => $this->compte],
            fn ($v) => $v !== null && $v !== '');
    }

    /** Critères lisibles, pour le rappel affiché au-dessus des résultats. */
    public function resume(): array
    {
        $d = fn ($v) => Carbon::parse($v)->format('d/m/Y');
        $m = fn ($v) => number_format($v, 3, ',', ' ');
        $r = [];
        if ($this->du && $this->au) $r[] = 'du '.$d($this->du).' au '.$d($this->au);
        elseif ($this->du) $r[] = 'à partir du '.$d($this->du);
        elseif ($this->au) $r[] = 'jusqu’au '.$d($this->au);
        if ($this->min !== null && $this->max !== null) $r[] = $this->min == $this->max ? 'montant '.$m($this->min) : 'montant de '.$m($this->min).' à '.$m($this->max);
        elseif ($this->min !== null) $r[] = 'montant ≥ '.$m($this->min);
        elseif ($this->max !== null) $r[] = 'montant ≤ '.$m($this->max);
        if ($this->texte) $r[] = 'libellé contenant « '.$this->texte.' »';
        if ($this->compte) $r[] = 'compte '.$this->compte.'…';
        return $r;
    }

    // ---------- Application aux requêtes ----------

    public function dates(Builder $q, string $colonne): Builder
    {
        return $q->when($this->du, fn ($q) => $q->whereDate($colonne, '>=', $this->du))
            ->when($this->au, fn ($q) => $q->whereDate($colonne, '<=', $this->au));
    }

    /** Au moins une des expressions (ex. HT, TTC, débit, crédit) dans la fourchette ; les montants nuls ne comptent pas. */
    public function montants(Builder $q, array $expressions): Builder
    {
        if ($this->min === null && $this->max === null) return $q;
        $min = $this->min !== null ? $this->min - 0.0005 : null;
        $max = $this->max !== null ? $this->max + 0.0005 : null;
        return $q->where(function ($q) use ($expressions, $min, $max) {
            foreach ($expressions as $e) {
                // Bornes écrites en nombres (et non liées) : PDO lie les nombres comme du texte, que SQLite ne compare pas à une colonne numérique
                $q->orWhere(function ($q) use ($e, $min, $max) {
                    $q->whereRaw("($e) > 0");
                    if ($min !== null) $q->whereRaw("($e) >= ".sprintf('%.4F', $min));
                    if ($max !== null) $q->whereRaw("($e) <= ".sprintf('%.4F', $max));
                });
            }
        });
    }

    /** Chaque mot recherché doit figurer dans l'une des colonnes (insensible à la casse). */
    public function texte(Builder $q, array $colonnes): Builder
    {
        if (! $this->texte) return $q;
        foreach (preg_split('/\s+/u', mb_strtolower($this->texte)) as $mot) {
            // « ! » comme caractère d'échappement : même comportement sous SQLite et MySQL
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $mot).'%';
            $q->where(function ($q) use ($colonnes, $like) {
                foreach ($colonnes as $c) $q->orWhereRaw("LOWER(COALESCE($c, '')) LIKE ? ESCAPE '!'", [$like]);
            });
        }
        return $q;
    }

    public function compte(Builder $q, string $colonne): Builder
    {
        return $q->when($this->compte, fn ($q) => $q->where($colonne, 'like', $this->compte.'%'));
    }
}
