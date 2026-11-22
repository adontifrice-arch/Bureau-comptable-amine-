<?php

namespace App\Http\Controllers;

use App\Models\Conge;
use App\Models\Salarie;
use App\Services\PaieCalc;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Menu Congé : saisie des congés (registre), saisie des congés antérieurs (report des années précédentes). */
class PaieCongeController extends Controller
{
    /** Saisie des congés : ajoute un congé ; sans nombre de jours, les jours ouvrables de la période. */
    public function store(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate([
            'salarie_id' => 'required|integer', 'type' => 'required|in:'.implode(',', array_keys(Conge::TYPES)),
            'du' => 'required|date', 'au' => 'required|date|after_or_equal:du', 'jours' => 'nullable|numeric|min:0|max:366', 'observation' => 'nullable|string|max:255',
        ], ['au.after_or_equal' => 'La fin du congé doit suivre son début.', 'salarie_id.required' => 'Choisissez le salarié.']);
        $s = Salarie::where('client_id', $client->id)->findOrFail((int) $d['salarie_id']);
        $cinq = (float) PaieCalc::parametres($client)['jours_mois'] <= 22;
        $ouvres = Conge::joursOuvres(Carbon::parse($d['du']), Carbon::parse($d['au']), $cinq);
        $c = Conge::create(['client_id' => $client->id, 'salarie_id' => $s->id, 'type' => $d['type'], 'du' => $d['du'], 'au' => $d['au'],
            'jours' => isset($d['jours']) && $d['jours'] !== null && $d['jours'] !== '' ? (float) $d['jours'] : $ouvres, 'observation' => $d['observation'] ?? null]);
        $this->log('Paie : congé de '.$s->nomComplet().' du '.$c->du->format('d/m/Y').' au '.$c->au->format('d/m/Y'), $client->id);
        return redirect()->route('paie.index', ['tab' => 'conges', 'mois' => $r->input('mois', $c->du->format('Y-m')), 'annee' => $c->du->year])
            ->with('ok', Conge::TYPES[$c->type].' de '.$s->nomComplet().' enregistré : '.rtrim(rtrim(number_format($c->jours, 2, ',', ''), '0'), ',').' jour(s).');
    }

    /**
     * Demande de congé à faire signer par le salarié : depuis un congé du registre (conge) ou pour un salarié et une période
     * (salarie, du, au, type), sans rien enregistrer. Solde disponible à la fin du mois précédant le congé.
     */
    public function demande(Request $r)
    {
        $client = $this->dossier($r);
        // Depuis le formulaire de saisie du congé (bouton « Demande de congé ») : le salarié choisi s'appelle salarie_id.
        if (! $r->filled('salarie') && $r->filled('salarie_id')) $r->merge(['salarie' => $r->input('salarie_id')]);
        $d = $r->validate(['conge' => 'nullable|integer', 'salarie' => 'nullable|required_without:conge|integer', 'type' => 'nullable|in:'.implode(',', array_keys(Conge::TYPES)),
            'du' => 'nullable|date', 'au' => 'nullable|date|after_or_equal:du', 'jours' => 'nullable|numeric|min:0|max:366', 'observation' => 'nullable|string|max:255'],
            ['salarie.required_without' => 'Choisissez le salarié.', 'au.after_or_equal' => 'La fin du congé doit suivre son début.']);
        if (! empty($d['conge'])) {
            $c = Conge::with('salarie')->where('client_id', $client->id)->findOrFail((int) $d['conge']);
        } else {
            $s = Salarie::where('client_id', $client->id)->findOrFail((int) $d['salarie']);
            $c = new Conge(['type' => $d['type'] ?? 'paye', 'du' => $d['du'] ?? null, 'au' => $d['au'] ?? null, 'observation' => $d['observation'] ?? null]);
            $c->setRelation('salarie', $s);
        }
        $cinq = (float) PaieCalc::parametres($client)['jours_mois'] <= 22;
        if ($c->jours === null && $c->du && $c->au) $c->jours = isset($d['jours']) ? (float) $d['jours'] : Conge::joursOuvres($c->du, $c->au, $cinq);
        // Reprise : premier jour ouvrable après la fin du congé.
        $reprise = $c->au?->copy()->addDay();
        while ($reprise && ($reprise->isSunday() || ($cinq && $reprise->isSaturday()))) $reprise->addDay();
        $avant = PaieCalc::soldeConges($c->salarie, ($c->du ?? now())->copy()->startOfMonth()->subMonth()->format('Y-m'));
        return view('paie.demande_conge', ['client' => $client, 'c' => $c, 'e' => $c->salarie, 'reprise' => $reprise, 'solde' => $avant,
            'mois' => $r->input('mois', ($c->du ?? now())->format('Y-m'))]);
    }

    public function destroy(Request $r, Conge $conge)
    {
        $client = $this->dossier($r);
        abort_unless($conge->client_id === $client?->id, 404);
        $conge->delete();
        return back()->with('ok', 'Congé supprimé.');
    }

    /** Saisie congé antérieur : report des congés des années précédentes de chaque salarié (jours dus au 1er janvier d'une année). */
    public function anterieurs(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['annee' => 'required|integer|min:1990|max:2100', 'report' => 'array', 'report.*' => 'nullable|numeric|min:-366|max:1000']);
        $n = 0;
        foreach ((array) ($d['report'] ?? []) as $id => $v) {
            $s = Salarie::where('client_id', $client->id)->find((int) $id);
            if (! $s) continue;
            $vide = $v === null || $v === '';
            $s->update(['conges_report' => $vide ? null : (float) $v, 'conges_report_annee' => $vide ? null : (int) $d['annee']]);
            $n++;
        }
        $this->log('Paie : saisie des congés antérieurs ('.$n.' salarié(s))', $client->id);
        return back()->with('ok', 'Congés antérieurs enregistrés au 1er janvier '.$d['annee'].'.');
    }
}
