<?php

namespace App\Http\Controllers;

use App\Models\Ecriture;
use App\Models\Paie;
use App\Models\Pret;
use App\Models\Salarie;
use App\Services\Compta;
use App\Services\Ecritures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Menu Prêt : prêts société et prêts CNSS (saisie, suppression, échéancier). */
class PaiePretController extends Controller
{
    /** Saisie d'un prêt : nombre d'échéances ou montant de l'échéance (l'autre est calculé). */
    public function store(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate([
            'type' => 'required|in:'.implode(',', array_keys(Pret::TYPES)), 'salarie_id' => 'required|integer', 'date' => 'required|date',
            'montant' => 'required|numeric|min:0.001|max:10000000', 'nb_echeances' => 'nullable|required_without:echeance|integer|min:1|max:240',
            'echeance' => 'nullable|required_without:nb_echeances|numeric|min:0.001', 'premier_mois' => 'required|date_format:Y-m',
            'reference' => 'nullable|string|max:60', 'observation' => 'nullable|string|max:255',
        ], ['nb_echeances.required_without' => 'Indiquez le nombre d’échéances ou le montant de l’échéance.', 'salarie_id.required' => 'Choisissez le salarié.']);
        $s = Salarie::where('client_id', $client->id)->findOrFail((int) $d['salarie_id']);
        $p = Paie::where('client_id', $client->id)->where('mois', $d['premier_mois'])->normale()->first();
        if ($p && $p->statut !== 'brouillon') return back()->withInput()->with('err', 'La paie de '.mois_fr($d['premier_mois']).' est clôturée : choisissez un premier mois de retenue ultérieur.');
        $montant = (float) $d['montant'];
        $nb = ! empty($d['nb_echeances']) ? (int) $d['nb_echeances'] : (int) ceil($montant / (float) $d['echeance'] - 1e-9);
        $echeance = ! empty($d['echeance']) ? (float) $d['echeance'] : r3($montant / $nb);
        $mode = in_array($r->input('mode'), array_keys(Salarie::MODES), true) ? $r->input('mode') : 'virement';
        $pret = DB::transaction(function () use ($client, $s, $d, $montant, $echeance, $nb, $mode) {
            $pret = Pret::create(['client_id' => $client->id, 'salarie_id' => $s->id, 'type' => $d['type'], 'date' => $d['date'], 'montant' => $montant, 'mode' => $mode,
                'echeance' => $echeance, 'nb_echeances' => max(1, $nb), 'premier_mois' => $d['premier_mois'], 'reference' => $d['reference'] ?? null, 'observation' => $d['observation'] ?? null]);
            // Prêt société : versement comptabilisé (D 421 Personnel – avances et acomptes / C 532 Banques ou 54 Caisse).
            if ($pret->type === 'societe') static::comptabiliser($pret);
            return $pret;
        });
        $this->log('Paie : '.Pret::TYPES[$pret->type].' de '.tnd($montant).' à '.$s->nomComplet(), $client->id);
        return redirect()->route('paie.index', ['tab' => 'prets', 'type' => $pret->type, 'mois' => $r->input('retour', $pret->premier_mois)])
            ->with('ok', Pret::TYPES[$pret->type].' enregistré : '.$pret->nb_echeances.' échéance(s) de '.tnd($pret->echeance).' à partir de '.mois_fr($pret->premier_mois)
                .($pret->type === 'societe' ? '. Versement comptabilisé au journal '.($pret->mode === 'especes' ? 'de caisse' : 'de banque').'.' : '.'));
    }

    /** Écriture de versement d'un prêt société : D 421 / C 532 (virement, chèque) ou 54 (espèces), à la date du prêt. */
    public static function comptabiliser(Pret $pret): Ecriture
    {
        $especes = $pret->mode === 'especes';
        return Compta::passer($pret->client_id, $pret->date->format('Y-m-d'), $especes ? 'CAI' : 'BQ', 'Prêt au personnel — '.$pret->salarie->nomComplet(), [
            ['compte' => '421', 'debit' => $pret->montant, 'credit' => 0],
            ['compte' => $especes ? '54' : '532', 'debit' => 0, 'credit' => $pret->montant],
        ], 'pret:'.$pret->id, auth()->id());
    }

    /** Suppression tant qu'aucune échéance n'a été retenue sur une paie clôturée. */
    public function destroy(Request $r, Pret $pret)
    {
        $client = $this->dossier($r);
        abort_unless($pret->client_id === $client?->id, 404);
        $closes = Paie::where('client_id', $client->id)->normale()->where('statut', '!=', 'brouillon')->pluck('mois')->all();
        if (array_intersect(array_keys($pret->echeancier()), $closes)) return back()->with('err', 'Des échéances de ce prêt ont été retenues sur des paies clôturées : il ne peut plus être supprimé.');
        DB::transaction(function () use ($pret, $r) {
            // L'écriture de versement est supprimée avec le prêt (elle reste consultable dans les écritures supprimées).
            foreach (Ecriture::where('client_id', $pret->client_id)->where('source', 'pret:'.$pret->id)->get() as $e) Ecritures::supprimer($e, $r->user(), 'Suppression du prêt');
            $pret->delete();
        });
        return back()->with('ok', 'Prêt supprimé'.($pret->type === 'societe' ? ', avec son écriture de versement.' : '.'));
    }

    /** Échéancier d'un prêt. */
    public function echeancier(Request $r, Pret $pret)
    {
        $client = $this->dossier($r);
        abort_unless($pret->client_id === $client?->id, 404);
        return $this->vueEcheancier($r, $client, $pret->salarie, collect([$pret]));
    }

    /** Échéancier de prêt (salarié) : tous les prêts du salarié, d'un type ou de tous les types. */
    public function echeancierSalarie(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['salarie' => 'required|integer', 'type' => 'nullable|in:tous,'.implode(',', array_keys(Pret::TYPES)), 'mois' => 'nullable|date_format:Y-m'],
            ['salarie.required' => 'Choisissez le salarié.']);
        $s = Salarie::where('client_id', $client->id)->findOrFail((int) $d['salarie']);
        $prets = Pret::where('client_id', $client->id)->where('salarie_id', $s->id)
            ->when(($d['type'] ?? 'tous') !== 'tous', fn ($q) => $q->where('type', $d['type']))->orderBy('date')->get();
        if ($prets->isEmpty()) return back()->with('err', $s->nomComplet().' n’a aucun prêt'.(($d['type'] ?? 'tous') !== 'tous' ? ' de ce type' : '').'.');
        return $this->vueEcheancier($r, $client, $s, $prets);
    }

    private function vueEcheancier(Request $r, $client, Salarie $s, $prets)
    {
        $mois = preg_match('/^\d{4}-\d{2}$/', (string) $r->input('mois')) ? $r->input('mois') : now()->format('Y-m');
        $closes = Paie::where('client_id', $client->id)->normale()->where('statut', '!=', 'brouillon')->pluck('mois')->all();
        return view('paie.echeancier_pret', ['client' => $client, 'salarie' => $s, 'prets' => $prets, 'mois' => $mois, 'closes' => $closes]);
    }
}
