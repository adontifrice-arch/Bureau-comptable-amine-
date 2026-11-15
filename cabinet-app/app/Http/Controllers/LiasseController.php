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
        $data = $this->data($r) + ['tab' => $r->input('tab', 'bil'), 'clients' => Client::orderBy('nom')->get(['id', 'nom'])];
        if ($data['tab'] === 'ef') {
            $data['ef'] = \App\Services\EtatsFinanciers::calculer($data['client'], $data['annee']);
            $data['infos'] = self::infosEtats($data['client']);
        }
        return view('liasse.index', $data);
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

    // ---------- États financiers complets (modèle du cabinet) ----------

    /** Informations de présentation absentes de la fiche client (activité, dates, effectif), mémorisées par dossier. */
    public static function infosEtats(Client $c): array
    {
        $effectif = \Illuminate\Support\Facades\DB::table('salaries')->where('client_id', $c->id)->count();
        return array_merge(['activite' => '', 'creation' => '', 'entree' => '', 'effectif' => $effectif ?: ''], (array) Setting::get('ef.'.$c->id, []));
    }

    public function infos(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $d = $r->validate(['activite' => 'nullable|max:255', 'creation' => 'nullable|date', 'entree' => 'nullable|date', 'effectif' => 'nullable|integer|min:0|max:100000']);
        Setting::put('ef.'.$client->id, array_map(fn ($v) => $v ?? '', $d));
        return back()->with('ok', 'Informations de présentation enregistrées.');
    }

    /** États financiers : aperçu imprimable, ou PDF paginé (« Page x of y », en-tête et pied répétés). */
    public function etatsFinanciers(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $annee = (int) $r->input('annee', now()->year);
        $data = ['client' => $client, 'ef' => \App\Services\EtatsFinanciers::calculer($client, $annee), 'infos' => self::infosEtats($client)];
        if ($r->input('format') !== 'pdf') return view('liasse.etats-financiers', $data + ['impression' => true]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('liasse.etats-financiers', $data + ['impression' => false])
            ->setPaper('a4', 'portrait')->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dom = $pdf->getDomPDF();
        $dom->render();
        $c = $dom->getCanvas();
        $police = $dom->getFontMetrics()->getFont('DejaVu Sans');
        [$l, $h] = [$c->get_width(), $c->get_height()];
        $gris = [0.35, 0.35, 0.35];
        $c->page_text(42, 30, 'ÉTATS FINANCIERS ARRÊTÉS AU 31/12/'.$annee, $police, 7.5, $gris);
        $c->page_text($l - 110, 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $police, 7.5, $gris);
        $c->line(42, 42, $l - 42, 42, $gris, 0.5);
        $c->line(42, $h - 40, $l - 42, $h - 40, $gris, 0.5);
        $c->page_text(42, $h - 32, $client->nom, $police, 7.5, $gris);
        if ($client->matricule_fiscal) $c->page_text($l - 160, $h - 32, $client->matricule_fiscal, $police, 7.5, $gris);
        return response($dom->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.\Illuminate\Support\Str::slug('etats-financiers-'.$annee.'-'.$client->nom).'.pdf"']);
    }
}
