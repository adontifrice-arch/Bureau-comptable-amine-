<?php

namespace App\Services;

use App\Models\Ecriture;
use App\Models\Paie;
use App\Models\ReleveLigne;
use Illuminate\Support\Collection;

/**
 * Éditions des journaux (onglet Journal & écritures) :
 * - journal auxiliaire : chaque journal avec ses écritures détaillées, totaux par journal ;
 * - journal centralisé : chaque journal, mois par mois, centralisé par compte ;
 * - journal par lot : écritures regroupées par lot de saisie (un relevé importé, une paie, les factures validées un même jour…) ;
 * - centralisation globale : totaux de chaque journal par mois, cumuls, total général (= mouvements de la balance).
 */
class Journaux
{
    public const VUES = ['ecritures' => 'Écritures', 'auxiliaire' => 'Journal auxiliaire', 'centralise' => 'Journal centralisé', 'lots' => 'Journal par lot', 'global' => 'Centralisation globale'];

    public static function vue(?string $v): string
    {
        return isset(self::VUES[$v]) ? $v : 'ecritures';
    }

    /** Période : exercice entier ou un mois (1 à 12). */
    public static function periode(int $annee, ?int $mois): array
    {
        if ($mois >= 1 && $mois <= 12) {
            $d = \Carbon\Carbon::create($annee, $mois, 1);
            return [$d->format('Y-m-d'), $d->endOfMonth()->format('Y-m-d'), mois_fr($d->format('Y-m'))];
        }
        return [$annee.'-01-01', $annee.'-12-31', 'exercice '.$annee];
    }

    /** Écritures de la période (journal facultatif), avec leurs lignes. */
    private static function ecritures(int $client, int $annee, ?int $mois, ?string $journal): Collection
    {
        [$du, $au] = self::periode($annee, $mois);
        return Ecriture::with('lignes')->where('client_id', $client)->whereDate('date', '>=', $du)->whereDate('date', '<=', $au)
            ->when($journal, fn ($q, $j) => $q->where('journal', $j))->orderBy('date')->orderBy('id')->get();
    }

    private static function totaux(iterable $ecritures): array
    {
        $d = 0.0; $c = 0.0;
        foreach ($ecritures as $e) foreach ($e->lignes as $l) { $d += $l->debit; $c += $l->credit; }
        return ['debit' => r3($d), 'credit' => r3($c)];
    }

    /** Journal auxiliaire : par journal, ses écritures détaillées. */
    public static function auxiliaire(int $client, int $annee, ?int $mois = null, ?string $journal = null): array
    {
        $noms = Ecriture::journaux($client);
        $J = [];
        foreach (self::ecritures($client, $annee, $mois, $journal)->groupBy('journal') as $code => $E) {
            $J[$code] = ['code' => $code, 'libelle' => $noms[$code] ?? $code, 'ecritures' => $E->values(), 'T' => self::totaux($E)];
        }
        uksort($J, fn ($a, $b) => self::ordre($a, $noms) <=> self::ordre($b, $noms));
        return ['journaux' => $J, 'T' => self::totaux(collect($J)->pluck('ecritures')->flatten(1))];
    }

    /** Journal centralisé : par journal et par mois, mouvements centralisés par compte. */
    public static function centralise(int $client, int $annee, ?int $mois = null, ?string $journal = null): array
    {
        $noms = Ecriture::journaux($client);
        $J = []; $T = ['debit' => 0.0, 'credit' => 0.0];
        foreach (self::ecritures($client, $annee, $mois, $journal) as $e) {
            $m = $e->date->format('Y-m');
            $J[$e->journal] ??= ['code' => $e->journal, 'libelle' => $noms[$e->journal] ?? $e->journal, 'mois' => [], 'T' => ['debit' => 0.0, 'credit' => 0.0]];
            $M = &$J[$e->journal]['mois'][$m];
            $M ??= ['mois' => $m, 'comptes' => [], 'T' => ['debit' => 0.0, 'credit' => 0.0]];
            foreach ($e->lignes as $l) {
                $M['comptes'][$l->compte] ??= ['compte' => $l->compte, 'libelle' => Compta::libelle($l->compte), 'debit' => 0.0, 'credit' => 0.0];
                $M['comptes'][$l->compte]['debit'] += $l->debit; $M['comptes'][$l->compte]['credit'] += $l->credit;
                $M['T']['debit'] += $l->debit; $M['T']['credit'] += $l->credit;
                $J[$e->journal]['T']['debit'] += $l->debit; $J[$e->journal]['T']['credit'] += $l->credit;
                $T['debit'] += $l->debit; $T['credit'] += $l->credit;
            }
            unset($M);
        }
        foreach ($J as &$j) {
            ksort($j['mois']);
            foreach ($j['mois'] as &$M) {
                ksort($M['comptes'], SORT_STRING);
                $M['comptes'] = array_values(array_map(fn ($c) => ['debit' => r3($c['debit']), 'credit' => r3($c['credit'])] + $c, $M['comptes']));
                $M['T'] = array_map('r3', $M['T']);
            }
            unset($M);
            $j['T'] = array_map('r3', $j['T']);
        }
        unset($j);
        uksort($J, fn ($a, $b) => self::ordre($a, $noms) <=> self::ordre($b, $noms));
        return ['journaux' => $J, 'T' => array_map('r3', $T)];
    }

    /**
     * Journal par lot : un lot rassemble les écritures passées ensemble — un relevé bancaire importé, une paie,
     * les dotations d'un exercice, la reprise de la balance, les factures d'achat (ou de vente) validées un même jour,
     * les saisies manuelles d'un même jour par une même personne.
     */
    public static function lots(int $client, int $annee, ?int $mois = null, ?string $journal = null): array
    {
        $E = self::ecritures($client, $annee, $mois, $journal)->load('validePar');
        // Relevés bancaires et paies d'origine, chargés en une fois
        $rb = $E->filter(fn ($e) => str_starts_with((string) $e->source, 'rb:'))->map(fn ($e) => (int) substr($e->source, 3));
        $releves = ReleveLigne::with('releve')->whereIn('id', $rb->all())->get()->keyBy('id');
        $pa = $E->filter(fn ($e) => str_starts_with((string) $e->source, 'paie:'))->map(fn ($e) => (int) substr($e->source, 5));
        $paies = Paie::whereIn('id', $pa->all())->get()->keyBy('id');

        $L = [];
        foreach ($E as $e) {
            [$type, $id] = array_pad(explode(':', (string) $e->source, 2), 2, null);
            $jour = $e->created_at?->format('d/m/Y') ?? '';
            [$cle, $titre] = match ($type) {
                'rb' => ($r = $releves[(int) $id]?->releve) ? ['rb:'.$r->id, 'Relevé bancaire « '.$r->nom_fichier.' » importé le '.$r->created_at?->format('d/m/Y')] : ['rb:?', 'Relevés bancaires'],
                'paie' => ['paie:'.$id, ($p = $paies[(int) $id] ?? null) ? $p->libelle() : 'Paie'],
                'amo' => ['amo:'.$id, 'Dotations aux amortissements '.$id],
                'bal' => ['bal:'.$id, 'Reprise de la balance '.$id],
                'fr' => ['fr:'.$jour, 'Factures d’achat validées le '.$jour],
                'fv' => ['fv:'.$jour, 'Factures de vente validées le '.$jour],
                'immo' => ['immo:'.$jour, 'Acquisitions d’immobilisations du '.$jour],
                'pret' => ['pret:'.$jour, 'Prêts au personnel du '.$jour],
                default => ['man:'.$jour.':'.$e->valide_par, 'Saisies manuelles du '.$jour.($e->validePar ? ' — '.$e->validePar->name : '')],
            };
            $L[$cle] ??= ['cle' => $cle, 'titre' => $titre, 'saisi' => $e->created_at, 'ecritures' => collect()];
            $L[$cle]['ecritures']->push($e);
            if ($e->created_at && $e->created_at < $L[$cle]['saisi']) $L[$cle]['saisi'] = $e->created_at;
        }
        uasort($L, fn ($a, $b) => [$a['saisi'], $a['cle']] <=> [$b['saisi'], $b['cle']]);
        $n = 0;
        $L = array_values(array_map(function ($l) use (&$n) {
            $l['numero'] = ++$n;
            $l['T'] = self::totaux($l['ecritures']);
            $l['journaux'] = $l['ecritures']->pluck('journal')->unique()->values()->all();
            return $l;
        }, $L));
        return ['lots' => $L, 'T' => self::totaux($E)];
    }

    /** Centralisation globale : par mois, total débit / crédit de chaque journal, total du mois et cumul. */
    public static function global(int $client, int $annee, ?int $mois = null, ?string $journal = null): array
    {
        [$du, $au] = self::periode($annee, $mois);
        $rows = \Illuminate\Support\Facades\DB::table('ecriture_lignes')->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->whereNull('ecritures.deleted_at')->where('ecritures.client_id', $client)->whereDate('ecritures.date', '>=', $du)->whereDate('ecritures.date', '<=', $au)
            ->when($journal, fn ($q, $j) => $q->where('ecritures.journal', $j))
            ->get(['ecritures.date', 'ecritures.journal', 'ecriture_lignes.debit', 'ecriture_lignes.credit']);
        $noms = Ecriture::journaux($client);
        $M = []; $parJournal = [];
        foreach ($rows as $r) {
            $m = substr((string) $r->date, 0, 7);
            $M[$m][$r->journal]['debit'] = ($M[$m][$r->journal]['debit'] ?? 0) + $r->debit;
            $M[$m][$r->journal]['credit'] = ($M[$m][$r->journal]['credit'] ?? 0) + $r->credit;
            $parJournal[$r->journal]['debit'] = ($parJournal[$r->journal]['debit'] ?? 0) + $r->debit;
            $parJournal[$r->journal]['credit'] = ($parJournal[$r->journal]['credit'] ?? 0) + $r->credit;
        }
        ksort($M);
        $cumul = ['debit' => 0.0, 'credit' => 0.0]; $out = [];
        foreach ($M as $m => $J) {
            uksort($J, fn ($a, $b) => self::ordre($a, $noms) <=> self::ordre($b, $noms));
            $t = ['debit' => array_sum(array_column($J, 'debit')), 'credit' => array_sum(array_column($J, 'credit'))];
            $cumul['debit'] += $t['debit']; $cumul['credit'] += $t['credit'];
            $out[] = ['mois' => $m, 'journaux' => array_map(fn ($x) => array_map('r3', $x), $J), 'T' => array_map('r3', $t), 'cumul' => array_map('r3', $cumul)];
        }
        uksort($parJournal, fn ($a, $b) => self::ordre($a, $noms) <=> self::ordre($b, $noms));
        return ['mois' => $out, 'journaux' => array_map(fn ($x) => array_map('r3', $x), $parJournal), 'noms' => $noms, 'T' => array_map('r3', $cumul)];
    }

    /** Ordre d'affichage des journaux : celui de la liste du dossier, puis les codes inconnus. */
    private static function ordre(string $code, array $noms): array
    {
        $i = array_search($code, array_map('strval', array_keys($noms)), true);
        return [$i === false ? 999 : $i, $code];
    }
}
