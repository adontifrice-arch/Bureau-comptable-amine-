<?php

namespace App\Models\Concerns;

use App\Models\Cabinet;
use App\Support\CabinetCourant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Données propres à un cabinet : toute requête est limitée au cabinet courant (y compris la liaison
 * des modèles dans les routes, qui renvoie 404 pour la donnée d'un autre cabinet) et toute création
 * est rattachée au cabinet courant.
 */
trait AppartientAuCabinet
{
    public static function bootAppartientAuCabinet(): void
    {
        static::addGlobalScope('cabinet', function (Builder $q) {
            $id = CabinetCourant::id();
            if ($id !== null) $q->where($q->getModel()->qualifyColumn('cabinet_id'), $id);
            elseif (! CabinetCourant::sansFiltreAutorise()) $q->whereRaw('1 = 0');
        });

        static::creating(function (Model $m) {
            $m->cabinet_id ??= CabinetCourant::id();
            if ($m->cabinet_id === null && ! CabinetCourant::sansFiltreAutorise()) {
                throw new RuntimeException('Création de '.class_basename($m).' sans cabinet courant.');
            }
            if (CabinetCourant::id() !== null && (int) $m->cabinet_id !== CabinetCourant::id()) {
                throw new RuntimeException('Création de '.class_basename($m).' pour un autre cabinet.');
            }
        });
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }
}
