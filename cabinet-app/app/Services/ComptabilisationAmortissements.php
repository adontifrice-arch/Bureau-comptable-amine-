<?php

namespace App\Services;

use App\Models\AmortissementComptabilise;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\Immobilisation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Écritures des immobilisations (spécification §3.8, §5.2) :
 * - dotations de l'exercice : D 6811 / C 28x selon le compte du bien, journal AMO, au 31/12, idempotent (seuls les compléments sont passés) ;
 * - acquisition d'un bien saisi à la main : D 2x [+ D 4366] / C 401, journal ACH ;
 * - import des factures reçues validées imputées en classe 2.
 */
class ComptabilisationAmortissements
{
    public const SOURCE = 'amo:';

    /** Biens du dossier avec la dotation de l'exercice, le montant déjà comptabilisé et l'écart. */
    public static function etat(Client $client, int $exercice): Collection
    {
        return Immobilisation::with('dotationsComptabilisees')->where('client_id', $client->id)
            ->whereYear('date_mise_service', '<=', $exercice)->orderBy('nature')->orderBy('date_mise_service')->get()
            ->map(function (Immobilisation $i) use ($exercice) {
                $s = Amortissement::situation($i, $exercice);
                $deja = $i->dejaComptabilise($exercice);
                return ['immo' => $i, 'dotation' => $s['dotation'], 'deja' => $deja, 'ecart' => r3($s['dotation'] - $deja)] + $s;
            });
    }

    /**
     * Passe au journal AMO les dotations (ou compléments) non encore comptabilisées.
     * Retourne null s'il n'y a rien à comptabiliser. Un écart négatif (dotation revue à la baisse) n'est jamais extourné
     * automatiquement : il est signalé dans l'état pour régularisation par le chef comptable.
     */
    public static function comptabiliser(Client $client, int $exercice, ?int $user): ?Ecriture
    {
        $lignes = self::etat($client, $exercice)->filter(fn ($l) => $l['ecart'] > 0.0005 && $l['immo']->amortissable());
        if ($lignes->isEmpty()) return null;

        return DB::transaction(function () use ($client, $exercice, $user, $lignes) {
            $complement = $lignes->contains(fn ($l) => $l['deja'] > 0);
            $total = r3($lignes->sum('ecart'));
            $ecriture = [['compte' => '6811', 'debit' => $total, 'credit' => 0]];
            foreach ($lignes->groupBy(fn ($l) => $l['immo']->compteAmortissement()) as $compte => $g) {
                $ecriture[] = ['compte' => $compte, 'debit' => 0, 'credit' => r3($g->sum('ecart'))];
            }
            $e = Compta::passer($client->id, "$exercice-12-31", 'AMO', ($complement ? 'Complément de dotations' : 'Dotations').' aux amortissements '.$exercice,
                $ecriture, self::SOURCE.$exercice, $user);
            foreach ($lignes as $l) {
                AmortissementComptabilise::create(['immobilisation_id' => $l['immo']->id, 'exercice' => $exercice, 'montant' => $l['ecart'], 'ecriture_id' => $e->id]);
            }
            return $e;
        });
    }

    /** Écritures AMO d'un exercice (une par comptabilisation, compléments compris). */
    public static function ecritures(Client $client, int $exercice): Collection
    {
        return Ecriture::with('lignes', 'validePar')->where('client_id', $client->id)->where('source', self::SOURCE.$exercice)->orderBy('id')->get();
    }

    /** Annule toutes les dotations comptabilisées d'un exercice (les écritures et le suivi par bien). */
    public static function annuler(Client $client, int $exercice): int
    {
        return DB::transaction(function () use ($client, $exercice) {
            $ecritures = self::ecritures($client, $exercice);
            foreach ($ecritures as $e) $e->delete();          // le suivi par bien est supprimé en cascade
            return $ecritures->count();
        });
    }

    /** Écriture d'acquisition d'un bien saisi à la main : D 2x (HT) [+ D 4366 TVA] / C 401 (TTC). */
    public static function acquisition(Immobilisation $i, ?int $user): Ecriture
    {
        if ($i->acquisitionComptabilisee()) throw new RuntimeException('L’acquisition de ce bien est déjà comptabilisée.');
        return DB::transaction(function () use ($i, $user) {
            $ht = r3($i->valeur_origine);
            $tva = r3($i->tva);
            $e = Compta::passer($i->client_id, $i->date_mise_service->format('Y-m-d'), 'ACH', 'Acquisition – '.$i->designation.($i->fournisseur ? ' ('.$i->fournisseur.')' : ''), [
                ['compte' => $i->compteImmobilisation(), 'debit' => $ht, 'credit' => 0],
                ['compte' => '4366', 'debit' => $tva, 'credit' => 0],
                ['compte' => '401', 'debit' => 0, 'credit' => r3($ht + $tva)],
            ], 'immo:'.$i->id, $user);
            $i->update(['acquisition_ecriture_id' => $e->id]);
            return $e;
        });
    }

    /** Factures reçues validées imputées en classe 2 (hors amortissements 28x et provisions 29x) et pas encore au registre. */
    public static function facturesAImporter(Client $client): Collection
    {
        $deja = Immobilisation::where('client_id', $client->id)->whereNotNull('facture_recue_id')->pluck('facture_recue_id');
        // Compte de la facture, ou de l'une de ses lignes (facture ventilée), en classe 2
        return FactureRecue::where('client_id', $client->id)->where('statut', 'val')->whereNotIn('id', $deja)
            ->where(fn ($q) => $q->where('compte', 'like', '2%')->orWhere('lignes', 'like', '%"compte":"2%'))
            ->orderBy('date')->get()->filter(fn (FactureRecue $f) => $f->lignesImmobilisations())->values();
    }

    /** Crée les biens correspondant aux factures reçues choisies ; l'acquisition est déjà comptabilisée par la facture. */
    public static function importer(Client $client, array $ids): int
    {
        $n = 0;
        foreach (self::facturesAImporter($client)->whereIn('id', $ids) as $f) {
            // Seules les lignes imputées en classe 2 forment le bien (une facture ventilée peut aussi porter des charges)
            $immo = $f->lignesImmobilisations();
            $ht = r3(array_sum(array_column($immo, 'ht'))); $tva = r3(array_sum(array_column($immo, 'tva')));
            $nature = Amortissement::natureDuCompte($immo[0]['compte'], $f->description.' '.$f->fournisseur);
            $deductible = $f->tva_deductible && $tva > 0;
            Immobilisation::create([
                'client_id' => $client->id, 'nature' => $nature, 'designation' => $f->description ?: 'Facture '.$f->reference.' – '.$f->fournisseur,
                'date_mise_service' => $f->date, 'valeur_origine' => $deductible ? $ht : r3($ht + $tva),   // TVA non déductible : incluse dans le coût
                'tva' => $deductible ? $tva : 0, 'fournisseur' => $f->fournisseur, 'reference' => $f->reference, 'methode' => 'lineaire',
                'taux' => Amortissement::NATURES[$nature][3], 'prorata' => 'jour', 'facture_recue_id' => $f->id,
            ]);
            $n++;
        }
        return $n;
    }
}
