<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Paie;
use App\Models\Salarie;
use App\Models\Setting;
use App\Services\PaieCalc;
use Illuminate\Http\Request;

class PaieController extends Controller
{
    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        $tab = $r->input('tab', 'calc');
        $mois = $r->input('mois', now()->format('Y-m'));
        $P = Setting::paie();
        $salaries = Salarie::where('client_id', $client->id)->orderBy('nom')->get();
        $paie = Paie::where('client_id', $client->id)->where('mois', $mois)->first();
        $R = $salaries->map(fn ($e) => PaieCalc::calcul($e, (float) (($paie?->primes ?? [])[$e->id] ?? 0), $P))->all();
        if ($paie && $paie->statut !== 'brouillon' && $paie->resultats) $R = $paie->resultats;
        return view('paie.index', ['client' => $client, 'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'tab' => $tab, 'mois' => $mois, 'P' => $P,
            'salaries' => $salaries, 'paie' => $paie, 'R' => $R, 'T' => PaieCalc::totaux($R),
            'historique' => Paie::where('client_id', $client->id)->where('statut', '!=', 'brouillon')->orderByDesc('mois')->get()]);
    }

    public function salarieStore(Request $r)
    {
        Salarie::create($this->valider($r) + ['client_id' => $this->dossier($r)->id]);
        return back()->with('ok', 'Salarié ajouté.');
    }

    public function salarieUpdate(Request $r, Salarie $salarie) { $salarie->update($this->valider($r)); return back()->with('ok', 'Fiche salarié enregistrée.'); }
    public function salarieDestroy(Salarie $salarie) { $salarie->delete(); return back()->with('ok', 'Salarié supprimé.'); }

    /** Enregistre les primes du mois (brouillon). */
    public function calcul(Request $r)
    {
        $client = $this->dossier($r);
        $mois = $r->validate(['mois' => 'required|date_format:Y-m'])['mois'];
        $paie = Paie::firstOrNew(['client_id' => $client->id, 'mois' => $mois]);
        if ($paie->exists && $paie->statut !== 'brouillon') return back()->with('err', 'Cette paie est déjà clôturée.');
        $paie->primes = collect((array) $r->input('primes'))->map(fn ($v) => (float) $v)->all();
        $paie->save();
        return redirect()->route('paie.index', ['mois' => $mois])->with('ok', 'Primes enregistrées et paie recalculée.');
    }

    /** Clôture : fige les résultats et transmet à la comptabilité. */
    public function cloturer(Request $r)
    {
        $client = $this->dossier($r);
        $mois = $r->validate(['mois' => 'required|date_format:Y-m'])['mois'];
        $paie = Paie::firstOrNew(['client_id' => $client->id, 'mois' => $mois]);
        if ($paie->statut === 'val') return back()->with('err', 'Cette paie est déjà comptabilisée.');
        $P = Setting::paie();
        $R = Salarie::where('client_id', $client->id)->get()->map(fn ($e) => PaieCalc::calcul($e, (float) (($paie->primes ?? [])[$e->id] ?? 0), $P))->all();
        if (! $R) return back()->with('err', 'Aucun salarié à payer.');
        $paie->fill(['resultats' => $R, 'totaux' => PaieCalc::totaux($R), 'statut' => 'transmise'])->save();
        $this->log('Clôture de la paie '.$mois, $client->id);
        return back()->with('ok', 'Paie clôturée et transmise à la comptabilité pour validation.');
    }

    public function bulletins(Request $r)
    {
        $client = $this->dossier($r);
        $mois = $r->input('mois', now()->format('Y-m'));
        $paie = Paie::where('client_id', $client->id)->where('mois', $mois)->first();
        $P = Setting::paie();
        $R = $paie && $paie->resultats ? $paie->resultats : Salarie::where('client_id', $client->id)->get()->map(fn ($e) => PaieCalc::calcul($e, (float) (($paie?->primes ?? [])[$e->id] ?? 0), $P))->all();
        return view('paie.bulletins', compact('client', 'mois', 'R', 'P'));
    }

    public function parametres(Request $r)
    {
        if ($r->boolean('reset')) { Setting::put('paie', []); return back()->with('ok', 'Valeurs de départ rétablies.'); }
        $d = $r->validate(['cnssS' => 'required|numeric', 'cnssP' => 'required|numeric', 'tfp' => 'required|numeric', 'fop' => 'required|numeric', 'css' => 'required|numeric',
            'frais' => 'required|numeric', 'fraisMax' => 'required|numeric', 'chef' => 'required|numeric', 'enf' => 'required|numeric', 'enfMax' => 'required|integer', 'bar' => 'required|string']);
        Setting::put('paie', array_map(fn ($v) => is_numeric($v) ? +$v : $v, $d));
        return back()->with('ok', 'Paramètres de paie enregistrés.');
    }

    private function valider(Request $r): array
    {
        $d = $r->validate(['nom' => 'required|max:255', 'cin' => 'nullable|max:20', 'cnss' => 'nullable|max:30', 'poste' => 'nullable|max:100',
            'salaire_base' => 'required|numeric|min:0', 'chef_famille' => 'nullable|boolean', 'enfants' => 'nullable|integer|min:0|max:20', 'date_embauche' => 'nullable|date']);
        $d['chef_famille'] = $r->boolean('chef_famille');
        $d['enfants'] = $d['enfants'] ?? 0;
        return $d;
    }
}
