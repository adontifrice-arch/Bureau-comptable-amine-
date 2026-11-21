<?php

namespace App\Models\Concerns;

use App\Models\Cabinet;
use App\Models\Client;
use App\Support\CabinetCourant;
use App\Support\Droits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Données propres à un cabinet : toute requête est limitée au cabinet courant (y compris la liaison
 * des modèles dans les routes, qui renvoie 404 pour la donnée d'un autre cabinet) et toute création
 * est rattachée au cabinet courant.
 *
 * Dossiers autorisés (Droits::dossiers) : pour un utilisateur limité à certains dossiers, les clients et
 * toutes les données rattachées à un client (colonne client_id) sont en plus limités à ces dossiers ;
 * les données du cabinet sans client restent visibles. Écrire dans un autre dossier est refusé (403).
 * Les numérotations propres au cabinet (factures, notes de frais) lèvent ce filtre : withoutGlobalScope('dossiers').
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

        static::addGlobalScope('dossiers', function (Builder $q) {
            $ids = Droits::dossiers();
            if ($ids === null) return;
            $m = $q->getModel();
            if ($m instanceof Client) {
                $q->whereIn($m->qualifyColumn('id'), $ids ?: [0]);
            } elseif (Droits::aColonneClient($m->getTable())) {
                $col = $m->qualifyColumn('client_id');
                $q->where(fn ($w) => $w->whereNull($col)->orWhereIn($col, $ids ?: [0]));
            }
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

        static::saving(function (Model $m) {
            if (Droits::dossiers() === null || $m instanceof Client || ! Droits::aColonneClient($m->getTable())) return;
            if (! Droits::dossierAutorise($m->client_id !== null ? (int) $m->client_id : null)) {
                throw new HttpException(403, 'Ce dossier ne fait pas partie de vos dossiers autorisés.');
            }
        });
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }
}
