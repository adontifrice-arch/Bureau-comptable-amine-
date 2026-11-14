<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Immobilisation;
use App\Services\Amortissement;

/** États imprimables du module Immobilisations (spécification §3.8). */
class EtatsImmobilisations
{
    private static function biens(Client $client, int $exercice)
    {
        return Immobilisation::where('client_id', $client->id)->whereYear('date_mise_service', '<=', $exercice)
            ->where(fn ($q) => $q->whereNull('date_cession')->orWhereYear('date_cession', '>=', $exercice))
            ->orderBy('nature')->orderBy('date_mise_service')->get();
    }

    /** Tableau des immobilisations et des amortissements de l'exercice, par nature avec sous-totaux. */
    public static function tableau(Client $client, int $exercice): Etat
    {
        $etat = new Etat('Tableau des immobilisations et amortissements', $client->nom, 'Exercice '.$exercice, [
            ['Désignation', 'text'], ['Mise en service', 'date'], ['Méthode', 'text'], ['Valeur d’origine', 'num'], ['Amort. antérieurs', 'num'],
            ['Dotation '.$exercice, 'num'], ['Amort. cumulés', 'num'], ['VNC au 31/12/'.$exercice, 'num'], ['Cession', 'date'],
        ]);
        $total = array_fill(0, 5, 0.0);
        foreach (self::biens($client, $exercice)->groupBy('nature') as $nature => $biens) {
            $etat->ligne([Amortissement::NATURES[$nature][0] ?? $nature], 'groupe');
            $st = array_fill(0, 5, 0.0);
            foreach ($biens as $i) {
                $s = Amortissement::situation($i, $exercice);
                $v = [(float) $i->valeur_origine, $s['anterieur'], $s['dotation'], $s['cumul'], $s['vnc']];
                $etat->ligne([$i->designation, $i->date_mise_service, Amortissement::METHODES[$i->methode].($i->amortissable() ? ' '.number_format($i->taux, 2, ',', ' ').' %' : ' (non amortissable)'),
                    ...$v, $i->date_cession]);
                foreach ($v as $k => $x) { $st[$k] += $x; $total[$k] += $x; }
            }
            $etat->ligne(['Sous-total '.mb_strtolower(Amortissement::NATURES[$nature][0] ?? $nature), null, null, ...array_map('r3', $st), null], 'soustotal');
        }
        $etat->ligne(['TOTAL', null, null, ...array_map('r3', $total), null], 'total');
        return $etat;
    }

    /** Plan d'amortissement prévisionnel sur 5 ans (exercice et 4 suivants). */
    public static function plan(Client $client, int $exercice): Etat
    {
        $annees = range($exercice, $exercice + 4);
        $etat = new Etat('Plan d’amortissement prévisionnel sur 5 ans', $client->nom, 'Exercices '.$exercice.' à '.end($annees), [
            ['Désignation', 'text'], ['Nature', 'text'], ['Valeur d’origine', 'num'], ['VNC au 01/01/'.$exercice, 'num'],
            ...array_map(fn ($a) => ['Dotation '.$a, 'num'], $annees), ['VNC au 31/12/'.end($annees), 'num'],
        ]);
        $total = array_fill(0, count($annees) + 3, 0.0);
        foreach (self::biens($client, $exercice)->filter(fn ($i) => $i->amortissable()) as $i) {
            $plan = Amortissement::planDe($i, end($annees));
            $v = [(float) $i->valeur_origine, $plan[$exercice]['debut'], ...array_map(fn ($a) => $plan[$a]['dotation'] ?? 0.0, $annees), $plan[end($annees)]['vnc']];
            $etat->ligne([$i->designation, $i->libelleNature(), ...$v]);
            foreach ($v as $k => $x) $total[$k] += $x;
        }
        $etat->ligne(['TOTAL', null, ...array_map('r3', $total)], 'total');
        return $etat;
    }
}
