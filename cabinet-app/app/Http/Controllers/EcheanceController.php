<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Echeance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EcheanceController extends Controller
{
    public function index(Request $r)
    {
        $f = $r->input('f', 'open');
        $items = Echeance::with('client')
            ->when($f === 'open', fn ($q) => $q->whereNull('accomplie_le'))
            ->when($f === 'late', fn ($q) => $q->whereNull('accomplie_le')->where('date_echeance', '<', now()->startOfDay()))
            ->when($f === 'done', fn ($q) => $q->whereNotNull('accomplie_le'))
            ->when($r->input('client'), fn ($q, $c) => $q->where('client_id', $c))
            ->when($r->input('mois'), fn ($q, $m) => $q->where('date_echeance', 'like', $m.'%'))
            ->orderByRaw('accomplie_le is not null')->orderBy($f === 'done' ? 'accomplie_le' : 'date_echeance', $f === 'done' ? 'desc' : 'asc')
            ->paginate(40)->withQueryString();
        $counts = [
            'open' => Echeance::whereNull('accomplie_le')->count(),
            'late' => Echeance::whereNull('accomplie_le')->where('date_echeance', '<', now()->startOfDay())->count(),
            'week' => Echeance::whereNull('accomplie_le')->whereBetween('date_echeance', [now()->startOfDay(), now()->addDays(7)])->count(),
        ];
        return view('echeances.index', ['items' => $items, 'f' => $f, 'counts' => $counts, 'clients' => Client::orderBy('nom')->get(['id', 'nom'])]);
    }

    public function calendrier(Request $r, Client $client)
    {
        $annee = (int) $r->input('annee', now()->year);
        $items = $client->echeances()->whereYear('date_echeance', $annee)->orderBy('date_echeance')->get();
        $series = $client->echeances()->whereNotNull('serie')->get()->groupBy('serie')
            ->map(fn ($g) => ['label' => preg_replace('/( – [^–]+)? \d{4}$/u', '', $g->first()->intitule).($g->first()->categorie ? ' · '.$g->first()->categorie : ''), 'n' => $g->count()]);
        return view('echeances.calendrier', compact('client', 'annee', 'items', 'series'));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['client_id' => 'required|exists:clients,id', 'categorie' => 'nullable|max:100', 'intitule' => 'nullable|max:255', 'date_echeance' => 'required|date']);
        $d['intitule'] = $d['intitule'] ?: ($d['categorie'] ?: 'Échéance');
        Echeance::create($d);
        return back()->with('ok', 'Échéance « '.$d['intitule'].' » ajoutée au '.dfr($d['date_echeance']).'.');
    }

    /** Génère une série d'échéances (mensuelle, trimestrielle…) sur plusieurs années. */
    public function serie(Request $r)
    {
        $d = $r->validate(['client_id' => 'required|exists:clients,id', 'categorie' => 'nullable|max:100', 'intitule' => 'nullable|max:255',
            'periodicite' => 'required|in:Mensuelle,Trimestrielle,Semestrielle,Annuelle', 'jour' => 'required|integer|min:1|max:31',
            'mois_debut' => 'required|integer|min:1|max:12', 'annee' => 'required|integer|min:2000|max:2100', 'nb_annees' => 'required|integer|min:1|max:10']);
        foreach (['jour', 'mois_debut', 'annee', 'nb_annees'] as $k) $d[$k] = (int) $d[$k];
        $pas = ['Mensuelle' => 1, 'Trimestrielle' => 3, 'Semestrielle' => 6, 'Annuelle' => 12][$d['periodicite']];
        $base = $d['intitule'] ?: ($d['categorie'] ?: 'Échéance');
        $serie = (string) Str::uuid();
        $n = 0;
        for ($k = 0; $k < $d['nb_annees']; $k++) {
            $y = $d['annee'] + $k;
            $start = $k ? (($d['mois_debut'] - 1) % $pas) + 1 : $d['mois_debut'];
            for ($m = $start; $m <= 12; $m += $pas) {
                $date = Carbon::create($y, $m, 1);
                $date->day(min($d['jour'], $date->daysInMonth));
                $lib = $base.' – '.ucfirst($date->locale('fr')->translatedFormat($pas === 12 ? 'Y' : 'F Y'));
                if ($pas === 12) $lib = $base.' '.$y;
                if (Echeance::where('client_id', $d['client_id'])->where('intitule', $lib)->whereDate('date_echeance', $date)->exists()) continue;
                Echeance::create(['client_id' => $d['client_id'], 'categorie' => $d['categorie'], 'intitule' => $lib, 'date_echeance' => $date, 'serie' => $serie]);
                $n++;
            }
        }
        return back()->with('ok', "$n échéance(s) générée(s).");
    }

    public function serieDestroy(Request $r)
    {
        $d = $r->validate(['serie' => 'required', 'portee' => 'required|in:all,year,from', 'annee' => 'required|integer']);
        $q = Echeance::where('serie', $d['serie']);
        if ($d['portee'] === 'year') $q->whereYear('date_echeance', $d['annee']);
        if ($d['portee'] === 'from') $q->whereYear('date_echeance', '>=', $d['annee']);
        $n = $q->delete();
        return back()->with('ok', "$n échéance(s) supprimée(s).");
    }

    public function toggle(Echeance $echeance)
    {
        $echeance->update(['accomplie_le' => $echeance->accomplie_le ? null : now()]);
        return back()->with('ok', $echeance->accomplie_le ? 'Échéance marquée comme accomplie.' : 'Échéance rouverte.');
    }

    public function destroy(Echeance $echeance)
    {
        $echeance->delete();
        return back()->with('ok', 'Échéance supprimée.');
    }

    /** Acomptes provisionnels (juin, septembre, décembre) pour l'année en cours et la suivante. */
    /** Nombre d'années couvertes par les acomptes provisionnels : l'année en cours et les 4 suivantes. */
    public const ACOMPTES_ANNEES = 5;

    /**
     * Acomptes provisionnels (spécification §3.3) : juin, septembre et décembre, le 28 pour une personne morale
     * et le 25 pour une personne physique. Les acomptes déjà passés à la création ne sont pas ajoutés.
     * Un acompte supprimé n'est jamais recréé ; ceux qui ne sont pas cochés suivent la nature du client.
     */
    public static function acomptesProvisionnels(Client $c): void
    {
        $jour = $c->estPhysique() ? 25 : 28;
        $existants = Echeance::withTrashed()->where('client_id', $c->id)->where('auto', 'ap')->get()->keyBy('intitule');
        for ($y = now()->year; $y < now()->year + self::ACOMPTES_ANNEES; $y++) {
            foreach ([6 => '1er', 9 => '2e', 12 => '3e'] as $m => $rang) {
                $date = Carbon::create($y, $m, $jour);
                $intitule = "$rang acompte provisionnel $y";
                if ($e = $existants->get($intitule)) {
                    if (! $e->trashed() && ! $e->accomplie_le && ! $e->date_echeance->isSameDay($date)) $e->update(['date_echeance' => $date]);
                    continue;
                }
                if ($date->lt(now()->startOfDay())) continue;
                Echeance::create(['client_id' => $c->id, 'auto' => 'ap', 'date_echeance' => $date, 'categorie' => 'Échéance fiscale', 'intitule' => $intitule]);
            }
        }
    }
}
