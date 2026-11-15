<?php

namespace App\Services;

use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\Immobilisation;
use App\Models\Paie;
use App\Models\ReleveLigne;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Modification, suppression et restauration des écritures comptables.
 * - Droit : chef comptable, ou collaborateur autorisé par lui (users.droit_ecritures).
 * - Supprimer place l'écriture dans « Pièces supprimées » et remet sa pièce d'origine en attente (facture à valider, paie transmise,
 *   ligne de relevé à comptabiliser, acquisition à comptabiliser) ; restaurer fait l'inverse, sauf si la pièce a été comptabilisée entre-temps.
 */
class Ecritures
{
    public static function autorise(?User $u): bool
    {
        return $u !== null && ($u->estChef() || (bool) $u->droit_ecritures);
    }

    /** [type, id] de la pièce d'origine (« fr:12 » → ['fr', '12']). */
    private static function source(Ecriture $e): array
    {
        return array_pad(explode(':', (string) $e->source, 2), 2, null);
    }

    public static function supprimer(Ecriture $e, User $u, ?string $motif = null): void
    {
        DB::transaction(function () use ($e, $u, $motif) {
            [$type, $id] = self::source($e);
            match ($type) {
                'fr' => FactureRecue::whereKey($id)->update(['statut' => 'prop', 'valide_par' => null]),
                'fv' => FactureVente::whereKey($id)->update(['statut' => 'prop', 'valide_par' => null]),
                'paie' => Paie::whereKey($id)->update(['statut' => 'transmise']),
                'rb' => ReleveLigne::whereKey($id)->update(['statut' => 'att', 'ecriture_id' => null]),
                'immo' => Immobilisation::whereKey($id)->update(['acquisition_ecriture_id' => null]),
                default => null,                        // amo : les dotations liées ne comptent plus tant que l'écriture est supprimée
            };
            $e->forceFill(['supprime_par' => $u->id, 'motif_suppression' => $motif ? mb_substr(trim($motif), 0, 255) : null])->save();
            $e->delete();
        });
    }

    public static function restaurer(Ecriture $e): void
    {
        if (! $e->trashed()) return;
        [$type, $id] = self::source($e);
        if ($e->source && Ecriture::where('client_id', $e->client_id)->where('source', $e->source)->exists()) {
            throw new RuntimeException('La pièce d’origine a été comptabilisée à nouveau depuis la suppression : restaurer cette écriture la compterait deux fois.');
        }
        DB::transaction(function () use ($e, $type, $id) {
            $e->restore();
            $e->forceFill(['supprime_par' => null, 'motif_suppression' => null])->save();
            match ($type) {
                'fr' => FactureRecue::whereKey($id)->update(['statut' => 'val', 'valide_par' => $e->valide_par]),
                'fv' => FactureVente::whereKey($id)->update(['statut' => 'val', 'valide_par' => $e->valide_par]),
                'paie' => Paie::whereKey($id)->update(['statut' => 'val']),
                'rb' => ReleveLigne::whereKey($id)->update(['statut' => 'cpt', 'ecriture_id' => $e->id]),
                'immo' => Immobilisation::whereKey($id)->update(['acquisition_ecriture_id' => $e->id]),
                default => null,
            };
        });
    }

    /** Modification : date, journal, libellé et lignes (au moins deux, équilibrées). */
    public static function modifier(Ecriture $e, array $d): void
    {
        $lignes = collect($d['lignes'])->filter(fn ($l) => trim((string) ($l['compte'] ?? '')) !== '' && ((float) ($l['debit'] ?? 0) || (float) ($l['credit'] ?? 0)))
            ->map(fn ($l) => ['compte' => Saisie::normaliser($l['compte'], Saisie::longueur($e->client_id)), 'debit' => r3($l['debit'] ?? 0), 'credit' => r3($l['credit'] ?? 0)])->values();
        if ($lignes->count() < 2) throw new RuntimeException('Saisissez au moins deux lignes.');
        if (abs($lignes->sum('debit') - $lignes->sum('credit')) > 0.0005) {
            throw new RuntimeException('Écriture déséquilibrée : débit '.tnd($lignes->sum('debit')).' ≠ crédit '.tnd($lignes->sum('credit')).'.');
        }
        DB::transaction(function () use ($e, $d, $lignes) {
            $e->update(['date' => $d['date'], 'journal' => $d['journal'], 'libelle' => $d['libelle']]);
            $e->lignes()->delete();
            foreach ($lignes as $l) $e->lignes()->create($l);
        });
    }
}
