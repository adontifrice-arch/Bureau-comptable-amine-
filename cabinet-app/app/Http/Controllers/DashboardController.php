<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Echeance;
use App\Models\Facture;
use App\Models\FactureRecue;
use App\Models\NoteInterne;
use App\Models\RendezVous;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = now()->startOfDay();
        $ouvertes = Echeance::with('client')->whereNull('accomplie_le');
        $factures = Facture::with('lignes')->where('type', 'facture')->get();
        $aEncaisser = $factures->where('statut', '<', 2)->sum(fn ($f) => $f->totaux()['ttc']);
        $encaisse = $factures->where('statut', 2)->sum(fn ($f) => $f->totaux()['ttc']);

        // Chiffre d'affaires HT des 12 derniers mois
        $mois = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $caMois = $mois->map(fn ($m) => [
            'label' => ucfirst($m->locale('fr')->translatedFormat('M')),
            'v' => $factures->filter(fn ($f) => $f->date->format('Y-m') === $m->format('Y-m'))->sum(fn ($f) => $f->totaux()['ht']),
        ]);

        return view('dashboard', [
            'nbClients' => Client::count(),
            'retard' => (clone $ouvertes)->where('date_echeance', '<', $today)->count(),
            'ouvertes' => (clone $ouvertes)->count(),
            'prioritaires' => (clone $ouvertes)->orderBy('date_echeance')->limit(8)->get(),
            'aEncaisser' => $aEncaisser,
            'encaisse' => $encaisse,
            'impayees' => $factures->filter->enRetard()->sortBy('echeance')->take(5),
            'aValider' => FactureRecue::with('client')->where('statut', 'prop')->latest()->limit(5)->get(),
            'rdv' => RendezVous::with('client')->where('date', '>=', $today)->orderBy('date')->orderBy('heure')->limit(5)->get(),
            'rappels' => NoteInterne::where('type', 'note')->where('fait', false)->whereNotNull('rappel_le')->orderBy('rappel_le')->limit(5)->get(),
            'caMois' => $caMois,
        ]);
    }
}
