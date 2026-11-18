<?php

namespace App\Services;

use App\Models\EcritureLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lettrage des comptes (tiers surtout) : une lettre (A, B… Z, AA…) rapproche des lignes d'un même compte dont
 * les débits égalent les crédits. Un lettrage devient invalide quand ses lignes ne sont plus soldées : ligne modifiée,
 * écriture supprimée, ligne transférée sur un autre compte, ou lettre restée seule.
 *
 * Transfert de compte à compte : les lignes d'un compte (sur une période, un journal) passent sur un autre compte.
 */
class Lettrage
{
    /** Lignes d'écriture du dossier (écritures supprimées comprises si $supprimees). */
    private static function lignes(int $client, bool $supprimees = false)
    {
        return EcritureLigne::query()->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->where('ecritures.client_id', $client)->when(! $supprimees, fn ($q) => $q->whereNull('ecritures.deleted_at'));
    }

    /** Lettre suivant la plus haute déjà utilisée sur le compte : A … Z, AA … AZ, BA … */
    public static function suivante(int $client, string $compte): string
    {
        $max = self::lignes($client, true)->where('ecriture_lignes.compte', $compte)->whereNotNull('ecriture_lignes.lettre')
            ->pluck('ecriture_lignes.lettre')->sortBy(fn ($l) => str_pad((string) strlen($l), 2, '0', STR_PAD_LEFT).$l)->last();
        if (! $max) return 'A';
        $l = $max;
        for ($i = strlen($l) - 1; $i >= 0; $i--) {
            if ($l[$i] !== 'Z') { $l[$i] = chr(ord($l[$i]) + 1); return $l; }
            $l[$i] = 'A';
        }
        return 'A'.$l;
    }

    /** Lettre les lignes choisies : même compte, non lettrées, débits = crédits. Renvoie la lettre. */
    public static function lettrer(int $client, array $ids): string
    {
        $L = self::lignes($client)->whereIn('ecriture_lignes.id', array_map('intval', $ids))->get(['ecriture_lignes.id', 'ecriture_lignes.compte', 'ecriture_lignes.debit', 'ecriture_lignes.credit', 'ecriture_lignes.lettre']);
        if ($L->count() < 2 || $L->count() !== count(array_unique($ids))) throw new RuntimeException('Choisissez au moins deux lignes du compte à lettrer.');
        if ($L->pluck('compte')->unique()->count() > 1) throw new RuntimeException('Les lignes à lettrer doivent être sur un même compte.');
        if ($L->whereNotNull('lettre')->count()) throw new RuntimeException('Certaines lignes sont déjà lettrées : délettrez-les d’abord.');
        $d = r3($L->sum('debit')); $c = r3($L->sum('credit'));
        if (abs($d - $c) > 0.0005) throw new RuntimeException('Lettrage impossible : débits '.tnd($d).' ≠ crédits '.tnd($c).' (écart '.tnd(abs($d - $c)).').');
        return DB::transaction(function () use ($client, $L) {
            $lettre = self::suivante($client, (string) $L->first()->compte);
            EcritureLigne::whereIn('id', $L->pluck('id'))->update(['lettre' => $lettre, 'lettre_le' => now()->toDateString()]);
            return $lettre;
        });
    }

    /** Délettre toutes les lignes d'une lettre du compte (écritures supprimées comprises). Renvoie le nombre de lignes. */
    public static function delettrer(int $client, string $compte, string $lettre): int
    {
        $ids = self::lignes($client, true)->where('ecriture_lignes.compte', $compte)->where('ecriture_lignes.lettre', $lettre)->pluck('ecriture_lignes.id');
        return EcritureLigne::whereIn('id', $ids)->update(['lettre' => null, 'lettre_le' => null]);
    }

    /**
     * Lettrages invalides du dossier : lettres dont les lignes (des écritures non supprimées) ne sont plus soldées,
     * qui comptent une ligne d'écriture supprimée, ou qui ne gardent qu'une ligne.
     */
    public static function invalides(int $client): Collection
    {
        return self::lignes($client, true)->whereNotNull('ecriture_lignes.lettre')
            ->get(['ecriture_lignes.compte', 'ecriture_lignes.lettre', 'ecriture_lignes.debit', 'ecriture_lignes.credit', 'ecritures.deleted_at', 'ecriture_lignes.lettre_le'])
            ->groupBy(fn ($l) => $l->compte.'|'.$l->lettre)
            ->map(function ($G) {
                $actives = $G->whereNull('deleted_at');
                $d = r3($actives->sum('debit')); $c = r3($actives->sum('credit'));
                $motifs = [];
                if (abs($d - $c) > 0.0005) $motifs[] = 'non soldé (écart '.tnd(abs($d - $c)).')';
                if ($G->whereNotNull('deleted_at')->count()) $motifs[] = $G->whereNotNull('deleted_at')->count().' ligne(s) d’écriture supprimée';
                if ($actives->count() === 1) $motifs[] = 'une seule ligne lettrée';
                return ['compte' => (string) $G->first()->compte, 'lettre' => $G->first()->lettre, 'lignes' => $G->count(), 'debit' => $d, 'credit' => $c,
                    'ecart' => r3($d - $c), 'lettre_le' => $G->max('lettre_le'), 'motifs' => $motifs];
            })
            ->filter(fn ($g) => $g['motifs'])->sortBy(fn ($g) => $g['compte'].'|'.str_pad($g['lettre'], 6, ' ', STR_PAD_LEFT))->values();
    }

    /**
     * État des comptes non lettrés à une date : pour chaque compte de tiers (sections de Balances::AUXILIAIRES, ou les comptes
     * commençant par $compte), les lignes non lettrées à cette date (une ligne lettrée après la date compte comme non lettrée),
     * avec les totaux et le solde non lettré par compte, par section et pour l'ensemble.
     */
    public static function nonLettres(int $client, string $date, ?string $section = null, ?string $compte = null): array
    {
        $racines = array_map('strval', array_keys(Balances::AUXILIAIRES));
        if ($section !== null && in_array($section, $racines, true)) $racines = [$section];
        $L = self::lignes($client)->whereDate('ecritures.date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ecriture_lignes.lettre')->orWhereDate('ecriture_lignes.lettre_le', '>', $date))
            ->where(fn ($q) => collect($racines)->each(fn ($rc) => $q->orWhere('ecriture_lignes.compte', 'like', $rc.'%')))
            ->when($compte, fn ($q, $c) => $q->where('ecriture_lignes.compte', 'like', $c.'%'))
            ->orderBy('ecriture_lignes.compte')->orderBy('ecritures.date')->orderBy('ecritures.id')
            ->get(['ecriture_lignes.compte', 'ecriture_lignes.debit', 'ecriture_lignes.credit', 'ecritures.id as ecriture_id', 'ecritures.date', 'ecritures.journal', 'ecritures.libelle']);

        $sections = []; $T = ['debit' => 0.0, 'credit' => 0.0];
        foreach ($racines as $rc) {
            $comptes = $L->filter(fn ($l) => str_starts_with((string) $l->compte, $rc))->groupBy(fn ($l) => (string) $l->compte)
                ->map(fn ($G, $c) => ['compte' => (string) $c, 'libelle' => Compta::libelle((string) $c), 'debit' => r3($G->sum('debit')), 'credit' => r3($G->sum('credit')),
                    'solde' => r3($G->sum('debit') - $G->sum('credit')),
                    'lignes' => $G->map(fn ($l) => ['ecriture_id' => $l->ecriture_id, 'date' => substr((string) $l->date, 0, 10), 'journal' => $l->journal,
                        'libelle' => $l->libelle, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit])->values()->all()])
                ->values()->all();
            if (! $comptes) continue;
            $S = ['debit' => r3(array_sum(array_column($comptes, 'debit'))), 'credit' => r3(array_sum(array_column($comptes, 'credit')))];
            $S['solde'] = r3($S['debit'] - $S['credit']);
            $sections[$rc] = ['titre' => $rc.' — '.Balances::AUXILIAIRES[$rc], 'comptes' => $comptes, 'T' => $S];
            $T['debit'] += $S['debit']; $T['credit'] += $S['credit'];
        }
        $T = array_map('r3', $T) + ['solde' => r3($T['debit'] - $T['credit'])];
        return ['sections' => $sections, 'T' => $T];
    }

    /**
     * Transfert de compte à compte : les lignes du compte source (période, journal facultatif) passent sur le compte destination.
     * Elles perdent leur lettre (le lettrage qu'elles laissent sur le compte source, s'il reste incomplet, apparaît en lettrage invalide).
     * @return array{lignes: int, debit: float, credit: float, destination: string}
     */
    public static function transferer(int $client, string $source, string $destination, string $du, string $au, ?string $journal = null): array
    {
        $destination = Saisie::normaliser($destination, Saisie::longueur($client));
        if ($source === $destination) throw new RuntimeException('Le compte de destination doit être différent du compte source.');
        $L = self::lignes($client)->where('ecriture_lignes.compte', $source)->whereDate('ecritures.date', '>=', $du)->whereDate('ecritures.date', '<=', $au)
            ->when($journal, fn ($q, $j) => $q->where('ecritures.journal', $j))
            ->get(['ecriture_lignes.id', 'ecriture_lignes.debit', 'ecriture_lignes.credit']);
        if ($L->isEmpty()) throw new RuntimeException('Aucune ligne sur le compte '.$source.' pour cette période'.($journal ? ' et ce journal' : '').'.');
        EcritureLigne::whereIn('id', $L->pluck('id'))->update(['compte' => $destination, 'lettre' => null, 'lettre_le' => null]);
        return ['lignes' => $L->count(), 'debit' => r3($L->sum('debit')), 'credit' => r3($L->sum('credit')), 'destination' => $destination];
    }
}
