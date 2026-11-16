<?php

namespace App\Http\Controllers;

use App\Models\Activite;
use App\Models\Client;
use App\Services\PaieAlertes;
use App\Models\Paie;
use App\Models\Salarie;
use App\Models\Setting;
use App\Services\Exports\EtatsPaie;
use App\Services\PaieCalc;
use Illuminate\Http\Request;

class PaieController extends Controller
{
    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        return view('paie.index', static::contexte($r, $client, $r->input('tab', 'calc'), $r->input('mois', now()->format('Y-m'))));
    }

    /** Données communes aux écrans du module Paie (barre de menus, fenêtres du menu Fichier, notifications). */
    public static function contexte(Request $r, Client $client, string $tab, string $mois): array
    {
        $P = PaieCalc::parametres($client);
        $salaries = PaieCalc::trier(Salarie::where('client_id', $client->id)->get(), $P['tri']);
        $paie = Paie::where('client_id', $client->id)->where('mois', $mois)->first();
        $R = PaieCalc::mois($client, $mois, true);
        $alertes = PaieAlertes::pour($client);
        $notifications = PaieAlertes::tableaux($client);
        $nbNotifs = count($alertes) + array_sum(array_map(fn ($t) => count($t['lignes']), $notifications));
        // Notification au démarrage : une fois par dossier et par session, s'il y a quelque chose à signaler.
        $notifAuto = $nbNotifs > 0 && ! empty($client->paie_config['notif_demarrage']) && ! $r->session()->has('paie_notif.'.$client->id);
        if ($notifAuto) $r->session()->put('paie_notif.'.$client->id, true);
        return ['client' => $client, 'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'tab' => $tab, 'mois' => $mois, 'P' => $P,
            'salaries' => $salaries, 'paie' => $paie, 'R' => $R, 'T' => PaieCalc::totaux($R),
            'historique' => Paie::where('client_id', $client->id)->where('statut', '!=', 'brouillon')->orderByDesc('mois')->get(),
            'alertes' => $alertes, 'notifications' => $notifications, 'nbNotifs' => $nbNotifs, 'notifAuto' => $notifAuto,
            'journal' => Activite::with('user:id,name')->where('client_id', $client->id)->where('action', 'like', '%paie%')->latest()->take(30)->get()];
    }

    /** Enregistre les primes du mois (brouillon). */
    public function calcul(Request $r)
    {
        $client = $this->dossier($r);
        $mois = $r->validate(['mois' => 'required|date_format:Y-m'])['mois'];
        $paie = Paie::firstOrNew(['client_id' => $client->id, 'mois' => $mois]);
        if ($paie->exists && $paie->statut !== 'brouillon') return back()->with('err', 'Cette paie est déjà clôturée.');
        $paie->primes = collect((array) $r->input('primes'))->map(fn ($v) => (float) $v)->all();
        $paie->pointage = PaieCalc::pointage((array) $r->input('pointage'));
        $paie->save();
        return redirect()->route('paie.index', ['mois' => $mois])->with('ok', 'Pointage et primes enregistrés, paie recalculée.');
    }

    /** Clôture : fige les résultats et transmet à la comptabilité. */
    public function cloturer(Request $r)
    {
        $client = $this->dossier($r);
        $mois = $r->validate(['mois' => 'required|date_format:Y-m'])['mois'];
        $paie = Paie::firstOrNew(['client_id' => $client->id, 'mois' => $mois]);
        if ($paie->statut === 'val') return back()->with('err', 'Cette paie est déjà comptabilisée.');
        $R = PaieCalc::mois($client, $mois, true);
        if (! $R) return back()->with('err', 'Aucun salarié à payer.');
        $paie->fill(['resultats' => $R, 'totaux' => PaieCalc::totaux($R), 'statut' => 'transmise'])->save();
        $this->log('Clôture de la paie '.$mois, $client->id);
        return back()->with('ok', 'Paie clôturée et transmise à la comptabilité pour validation.');
    }

    public function bulletins(Request $r)
    {
        $client = $this->dossier($r);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        $format = $r->input('format', $client->paie_config['format_fiche'] ?? 'a4') === 'a5' ? 'a5' : 'a4';
        $R = PaieCalc::mois($client, $mois);
        // Chaque bulletin est édité autant de fois que demandé sur la fiche employé (Nb bulletin).
        $copies = Salarie::whereIn('id', array_column($R, 'salarie_id'))->pluck('nb_bulletin', 'id');
        $R = collect($R)->flatMap(fn ($x) => array_fill(0, max(1, (int) ($copies[$x['salarie_id']] ?? 1)), $x))->all();
        return view('paie.bulletins', ['client' => $client, 'mois' => $mois, 'R' => $R, 'P' => PaieCalc::parametres($client), 'format' => $format]);
    }

    /** Éditions de la barre d'outils : journal global, déclarations CNSS, déclaration employeur, ordre de virement, bordereau. */
    public function edition(Request $r, string $etat, string $format)
    {
        $client = $this->dossier($r);
        abort_unless($client && isset((EtatsPaie::EDITIONS + EtatsPaie::EXPORTS)[$etat]) && in_array($format, ['imprimer', 'pdf', 'xlsx', 'docx'], true), 404);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        return EtatsPaie::etat($etat, $client, $mois)->reponse($format);
    }

    public function parametres(Request $r)
    {
        if ($r->boolean('reset')) { Setting::put('paie', []); return back()->with('ok', 'Valeurs de départ rétablies.'); }
        $d = $r->validate(['cnssS' => 'required|numeric', 'cnssP' => 'required|numeric', 'at' => 'required|numeric|min:0', 'tfp' => 'required|numeric', 'fop' => 'required|numeric', 'css' => 'required|numeric',
            'frais' => 'required|numeric', 'fraisMax' => 'required|numeric', 'chef' => 'required|numeric', 'enf' => 'required|numeric', 'enfMax' => 'required|integer', 'enfEtu' => 'required|numeric', 'enfInf' => 'required|numeric', 'parentTaux' => 'required|numeric', 'parentMax' => 'required|numeric', 'bar' => 'required|string']);
        Setting::put('paie', array_map(fn ($v) => is_numeric($v) ? +$v : $v, $d));
        return back()->with('ok', 'Paramètres de paie enregistrés.');
    }

}
