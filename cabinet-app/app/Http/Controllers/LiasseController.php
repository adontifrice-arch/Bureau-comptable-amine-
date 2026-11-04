<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PassageFiscal;
use App\Services\Compta;
use App\Services\PaieCalc;
use App\Models\Setting;
use Illuminate\Http\Request;

class LiasseController extends Controller
{
    public static function fiscal(Client $c, array $P, ?array $L): array
    {
        $L = array_merge(['rei' => 0, 'ded' => 0, 'taux' => 15, 'min' => 0, 'acp' => 0, 'rs' => 0], $L ?? []);
        $rf = r3($P['rav'] + $L['rei'] - $L['ded']);
        $th = $c->estPhysique() ? r3(PaieCalc::bareme(max(0, $rf), Setting::paie()['bar'])) : r3(max(0, $rf) * $L['taux'] / 100);
        $du = r3(max($th, $L['min']));
        return $L + ['rf' => $rf, 'th' => $th, 'du' => $du, 'sol' => r3($du - $L['acp'] - $L['rs'])];
    }

    private function data(Request $r): array
    {
        $client = $this->dossier($r);
        $annee = (int) $r->input('annee', now()->year);
        $F = Compta::etats($client->id, $annee);
        $Fp = Compta::etats($client->id, $annee - 1);
        $L = PassageFiscal::where('client_id', $client->id)->where('annee', $annee)->first()?->donnees;
        return compact('client', 'annee', 'F', 'Fp') + ['fisc' => static::fiscal($client, $F['P'], $L), 'saved' => (bool) $L];
    }

    public function index(Request $r)
    {
        if (! $this->dossier($r)) return view('compta.vide');
        return view('liasse.index', $this->data($r) + ['tab' => $r->input('tab', 'bil'), 'clients' => Client::orderBy('nom')->get(['id', 'nom'])]);
    }

    public function passage(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['annee' => 'required|integer', 'rei' => 'nullable|numeric', 'ded' => 'nullable|numeric', 'taux' => 'nullable|numeric', 'min' => 'nullable|numeric', 'acp' => 'nullable|numeric', 'rs' => 'nullable|numeric']);
        $annee = $d['annee']; unset($d['annee']);
        PassageFiscal::updateOrCreate(['client_id' => $client->id, 'annee' => $annee], ['donnees' => array_map(fn ($v) => (float) $v, $d)]);
        return back()->with('ok', 'Tableau de passage enregistré.');
    }

    public function print(Request $r) { return view('liasse.print', $this->data($r)); }
}
