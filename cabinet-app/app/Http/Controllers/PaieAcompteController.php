<?php

namespace App\Http\Controllers;

use App\Models\Acompte;
use App\Models\Paie;
use App\Models\Salarie;
use Illuminate\Http\Request;

/** Menu Acompte : saisie des acomptes sur salaire (registre) et reçu d'acompte. */
class PaieAcompteController extends Controller
{
    public function store(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate([
            'salarie_id' => 'required|integer', 'date' => 'required|date', 'montant' => 'required|numeric|min:0.001|max:1000000',
            'mode' => 'required|in:'.implode(',', array_keys(Salarie::MODES)), 'mois' => 'required|date_format:Y-m', 'observation' => 'nullable|string|max:255',
        ], ['salarie_id.required' => 'Choisissez le salarié.', 'mois.required' => 'Indiquez le mois de retenue.']);
        $s = Salarie::where('client_id', $client->id)->findOrFail((int) $d['salarie_id']);
        // La paie du mois de retenue ne doit pas être clôturée : l'acompte n'y serait pas retenu.
        $p = Paie::where('client_id', $client->id)->where('mois', $d['mois'])->normale()->first();
        if ($p && $p->statut !== 'brouillon') return back()->withInput()->with('err', 'La paie de '.mois_fr($d['mois']).' est clôturée : choisissez un mois de retenue ultérieur.');
        $a = Acompte::create(['client_id' => $client->id, 'salarie_id' => $s->id] + $d);
        $this->log('Paie : acompte de '.tnd($a->montant).' à '.$s->nomComplet(), $client->id);
        return redirect()->route('paie.index', ['tab' => 'acomptes', 'mois' => $r->input('retour', $d['mois'])])
            ->with('ok', 'Acompte de '.tnd($a->montant).' enregistré pour '.$s->nomComplet().', retenu sur la paie de '.mois_fr($a->mois).'.')
            ->with('recu_acompte', $a->id);   // le reçu d'avance sur salaire est proposé aussitôt
    }

    public function destroy(Request $r, Acompte $acompte)
    {
        $client = $this->dossier($r);
        abort_unless($acompte->client_id === $client?->id, 404);
        $p = Paie::where('client_id', $client->id)->where('mois', $acompte->mois)->normale()->first();
        if ($p && $p->statut !== 'brouillon') return back()->with('err', 'La paie de '.mois_fr($acompte->mois).' est clôturée : l’acompte y a été retenu.');
        $acompte->delete();
        return back()->with('ok', 'Acompte supprimé.');
    }

    /** Reçu d'avance sur salaire à faire signer par le salarié (deux exemplaires). */
    public function recu(Request $r, Acompte $acompte)
    {
        $client = $this->dossier($r);
        abort_unless($acompte->client_id === $client?->id, 404);
        return view('paie.recu_acompte', ['client' => $client, 'a' => $acompte->load('salarie')]);
    }
}
