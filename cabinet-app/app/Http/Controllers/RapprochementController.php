<?php

namespace App\Http\Controllers;

use App\Services\Ecritures;
use App\Services\Rapprochement;
use Illuminate\Http\Request;

/** Banque → État de rapprochement : solde du relevé, pointage des lignes du compte de banque, impression. */
class RapprochementController extends Controller
{
    /** Paramètres de l'état : compte de banque du dossier et date d'arrêté (fin du mois précédent par défaut). */
    public static function parametres(Request $r, int $client): array
    {
        $comptes = Rapprochement::comptes($client);
        $compte = in_array((string) $r->input('compte'), $comptes, true) ? (string) $r->input('compte') : $comptes[0];
        $date = $r->filled('date') && strtotime((string) $r->input('date')) ? date('Y-m-d', strtotime((string) $r->input('date'))) : now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
        return ['comptesBq' => $comptes, 'compteBq' => $compte, 'dateRap' => $date,
            'R' => Rapprochement::etat($client, $compte, $date, Rapprochement::soldeReleve($client, $compte, $date))];
    }

    private function retour(string $compte, string $date)
    {
        return redirect()->route('compta.index', ['tab' => 'bq', 'vue' => 'rapprochement', 'compte' => $compte, 'date' => $date, 'annee' => substr($date, 0, 4)]);
    }

    public function solde(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $d = $r->validate(['compte' => 'required|regex:/^\d{1,10}$/', 'date' => 'required|date', 'solde' => 'required|numeric']);
        $date = date('Y-m-d', strtotime($d['date']));
        Rapprochement::enregistrerSoldeReleve($client->id, $d['compte'], $date, (float) $d['solde']);
        $this->log('Rapprochement bancaire '.$d['compte'].' au '.dfr($date).' : solde du relevé '.tnd((float) $d['solde']), $client->id);
        return $this->retour($d['compte'], $date)->with('ok', 'Solde du relevé enregistré.');
    }

    public function pointer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        if (! Ecritures::autorise($r->user())) return back()->with('err', 'Seuls le chef comptable et les collaborateurs autorisés à modifier les écritures peuvent pointer.');
        $d = $r->validate(['compte' => 'required|regex:/^\d{1,10}$/', 'date' => 'required|date', 'lignes' => 'required|array', 'lignes.*' => 'integer', 'action' => 'required|in:pointer,depointer'],
            ['lignes.required' => 'Cochez les lignes à '.($r->input('action') === 'depointer' ? 'dépointer' : 'pointer').'.']);
        $date = date('Y-m-d', strtotime($d['date']));
        $n = Rapprochement::pointer($client->id, $d['compte'], $d['lignes'], $d['action'] === 'pointer' ? $date : null);
        $this->log(($d['action'] === 'pointer' ? 'Pointage' : 'Dépointage').' de '.$n.' ligne(s) du compte '.$d['compte'].' au '.dfr($date), $client->id);
        return $this->retour($d['compte'], $date)->with('ok', $n.' ligne(s) '.($d['action'] === 'pointer' ? 'pointée(s) au '.dfr($date) : 'dépointée(s)').'.');
    }

    public function imprimer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        return view('compta.rapprochement-print', ['client' => $client] + self::parametres($r, $client->id));
    }
}
