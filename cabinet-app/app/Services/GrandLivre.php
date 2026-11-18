<?php

namespace App\Services;

use App\Models\EcritureLigne;

/**
 * Grand livre auxiliaire : pour chaque compte de tiers (fournisseurs 40, clients 41, personnel 42), le solde au 1er janvier,
 * les mouvements de l'exercice avec le solde progressif, puis les totaux du compte, de la section et de l'ensemble.
 */
class GrandLivre
{
    /**
     * @param ?string $section racine de Balances::AUXILIAIRES (40, 41, 42), ou null pour toutes
     * @param ?string $compte  compte ou début de compte (ex. 411 ou 411002), pour restreindre aux tiers concernés
     * @return array{sections: array, T: array{report: float, debit: float, credit: float, solde: float}}
     */
    public static function auxiliaire(int $client, int $annee, ?string $section = null, ?string $compte = null): array
    {
        $racines = array_keys(Balances::AUXILIAIRES);
        if ($section !== null && in_array($section, array_map('strval', $racines), true)) $racines = [$section];
        $debut = $annee.'-01-01';

        $lignes = EcritureLigne::query()->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->whereNull('ecritures.deleted_at')->where('ecritures.client_id', $client)->whereDate('ecritures.date', '<=', $annee.'-12-31')
            ->where(fn ($q) => collect($racines)->each(fn ($rc) => $q->orWhere('ecriture_lignes.compte', 'like', $rc.'%')))
            ->when($compte, fn ($q, $c) => $q->where('ecriture_lignes.compte', 'like', $c.'%'))
            ->orderBy('ecriture_lignes.compte')->orderBy('ecritures.date')->orderBy('ecritures.id')
            ->get(['ecritures.id as ecriture_id', 'ecritures.date', 'ecritures.journal', 'ecritures.libelle', 'ecriture_lignes.compte', 'ecriture_lignes.debit', 'ecriture_lignes.credit']);

        $comptes = [];
        foreach ($lignes as $l) {
            $c = (string) $l->compte;
            $comptes[$c] ??= ['compte' => $c, 'libelle' => Compta::libelle($c), 'report' => 0.0, 'mouvements' => [], 'debit' => 0.0, 'credit' => 0.0];
            $date = $l->date instanceof \DateTimeInterface ? $l->date->format('Y-m-d') : substr((string) $l->date, 0, 10);
            if ($date < $debut) { $comptes[$c]['report'] += $l->debit - $l->credit; continue; }
            $comptes[$c]['mouvements'][] = ['ecriture_id' => $l->ecriture_id, 'date' => $date, 'journal' => $l->journal, 'libelle' => $l->libelle,
                'debit' => (float) $l->debit, 'credit' => (float) $l->credit];
            $comptes[$c]['debit'] += $l->debit;
            $comptes[$c]['credit'] += $l->credit;
        }

        $sections = []; $T = ['report' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'solde' => 0.0];
        foreach ($racines as $rc) {
            $S = ['report' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'solde' => 0.0]; $liste = [];
            foreach ($comptes as $c => $x) {
                if (! str_starts_with($c, (string) $rc)) continue;
                $x['report'] = r3($x['report']); $x['debit'] = r3($x['debit']); $x['credit'] = r3($x['credit']);
                $x['solde'] = r3($x['report'] + $x['debit'] - $x['credit']);
                if (! $x['mouvements'] && abs($x['solde']) < 0.0005) continue;        // compte soldé et sans mouvement sur l'exercice
                // Solde progressif de chaque mouvement, à partir du report
                $s = $x['report'];
                foreach ($x['mouvements'] as $i => $m) { $s += $m['debit'] - $m['credit']; $x['mouvements'][$i]['solde'] = r3($s); }
                $liste[] = $x;
                foreach (['report', 'debit', 'credit', 'solde'] as $k) $S[$k] += $x[$k];
            }
            if (! $liste) continue;
            $sections[(string) $rc] = ['titre' => $rc.' — '.Balances::AUXILIAIRES[$rc], 'comptes' => $liste, 'T' => array_map('r3', $S)];
            foreach (['report', 'debit', 'credit', 'solde'] as $k) $T[$k] += $S[$k];
        }
        return ['sections' => $sections, 'T' => array_map('r3', $T)];
    }
}
