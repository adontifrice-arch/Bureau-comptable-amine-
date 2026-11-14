<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Immobilisation;
use App\Services\Amortissement;
use App\Services\ComptabilisationAmortissements as Compta;
use App\Services\Exports\EtatsImmobilisations;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Immobilisations et amortissements (spécification §3.8). */
class ImmobilisationController extends Controller
{
    private function exercice(Request $r): int
    {
        return max(1990, min(2100, (int) $r->input('exercice', now()->year)));
    }

    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        $exercice = $this->exercice($r);
        $etat = Compta::etat($client, $exercice);
        return view('immobilisations.index', [
            'client' => $client, 'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'exercice' => $exercice, 'tab' => $r->input('tab', 'registre'),
            'biens' => Immobilisation::with('dotationsComptabilisees', 'factureRecue', 'ecritureAcquisition')->where('client_id', $client->id)
                ->orderBy('nature')->orderBy('date_mise_service')->get(),
            'etat' => $etat, 'ecritures' => Compta::ecritures($client, $exercice), 'aImporter' => Compta::facturesAImporter($client),
        ]);
    }

    private function valider(Request $r): array
    {
        $d = $r->validate([
            'nature' => ['required', Rule::in(array_keys(Amortissement::NATURES))],
            'designation' => 'required|max:255', 'date_mise_service' => 'required|date', 'valeur_origine' => 'required|numeric|gt:0',
            'tva' => 'nullable|numeric|min:0', 'fournisseur' => 'nullable|max:255', 'reference' => 'nullable|max:100',
            'methode' => ['required', Rule::in(array_keys(Amortissement::METHODES))], 'taux' => 'nullable|numeric|min:0|max:100',
            'prorata' => ['required', Rule::in(array_keys(Amortissement::PRORATAS))],
            'valeur_residuelle' => 'nullable|numeric|min:0|lt:valeur_origine', 'date_cession' => 'nullable|date|after_or_equal:date_mise_service',
        ], ['valeur_residuelle.lt' => 'La valeur résiduelle doit être inférieure à la valeur d’origine.']);
        $d['tva'] = $d['tva'] ?? 0;
        $d['valeur_residuelle'] = $d['valeur_residuelle'] ?? 0;
        // Taux par défaut de la nature ; un terrain n'est pas amortissable.
        $d['taux'] = $d['nature'] === 'terrain' ? 0 : ($d['taux'] ?? Amortissement::NATURES[$d['nature']][3]);
        if ($d['nature'] !== 'terrain' && $d['taux'] <= 0) abort(back()->withInput()->withErrors(['taux' => 'Indiquez le taux d’amortissement.']));
        return $d;
    }

    public function store(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $i = Immobilisation::create($this->valider($r) + ['client_id' => $client->id]);
        $this->log('Immobilisation ajoutée : '.$i->designation, $client->id);
        return back()->with('ok', 'Immobilisation « '.$i->designation.' » ajoutée au registre.');
    }

    public function update(Request $r, Immobilisation $immobilisation)
    {
        $immobilisation->update($this->valider($r));
        $msg = 'Immobilisation modifiée.';
        if ($immobilisation->dotationsComptabilisees()->exists()) $msg .= ' Des dotations sont déjà comptabilisées : vérifiez l’onglet Dotations (complément ou régularisation).';
        return back()->with('ok', $msg);
    }

    public function destroy(Immobilisation $immobilisation)
    {
        if ($immobilisation->dotationsComptabilisees()->exists()) {
            return back()->with('err', 'Suppression impossible : des dotations de ce bien sont comptabilisées. Annulez-les d’abord.');
        }
        if ($immobilisation->acquisition_ecriture_id) {
            return back()->with('err', 'Suppression impossible : l’écriture d’acquisition est comptabilisée. Annulez-la d’abord dans le journal.');
        }
        $immobilisation->delete();
        $this->log('Immobilisation supprimée : '.$immobilisation->designation, $immobilisation->client_id);
        return back()->with('ok', 'Immobilisation supprimée.');
    }

    public function importer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $ids = array_map('intval', (array) $r->input('factures', []));
        $n = $ids ? Compta::importer($client, $ids) : 0;
        if (! $n) return back()->with('err', 'Cochez au moins une facture à importer.');
        $this->log("Import de $n immobilisation(s) depuis les factures reçues", $client->id);
        return back()->with('ok', "$n immobilisation(s) importée(s) : vérifiez la date de mise en service, la méthode et le taux.");
    }

    // ---------- Réservé au chef comptable ----------

    public function acquisition(Immobilisation $immobilisation)
    {
        try {
            Compta::acquisition($immobilisation, auth()->id());
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }
        $this->log('Écriture d’acquisition : '.$immobilisation->designation, $immobilisation->client_id);
        return back()->with('ok', 'Écriture d’acquisition passée au journal des achats.');
    }

    public function comptabiliser(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $exercice = $this->exercice($r);
        $e = Compta::comptabiliser($client, $exercice, auth()->id());
        if (! $e) return back()->with('err', 'Rien à comptabiliser : les dotations '.$exercice.' sont déjà passées.');
        $total = $e->lignes->sum('debit');
        $this->log('Comptabilisation des dotations '.$exercice.' ('.tnd($total).')', $client->id);
        return back()->with('ok', $e->libelle.' : '.tnd($total).' passés au journal AMO au 31/12/'.$exercice.'.');
    }

    /** Annulation en deux temps : l'écran affiche ce qui sera annulé, puis l'annulation exige la confirmation explicite. */
    public function annuler(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $exercice = $this->exercice($r);
        $r->validate(['confirmation' => 'required|in:'.$exercice], ['confirmation.*' => 'Saisissez l’exercice '.$exercice.' pour confirmer l’annulation.']);
        $n = Compta::annuler($client, $exercice);
        if (! $n) return back()->with('err', 'Aucune dotation comptabilisée pour '.$exercice.'.');
        $this->log("Annulation des dotations $exercice ($n écriture(s))", $client->id);
        return back()->with('ok', "Dotations $exercice annulées ($n écriture(s) supprimée(s) du journal AMO).");
    }

    // ---------- États ----------

    public function etat(Request $r, string $etat, string $format)
    {
        $client = $this->dossier($r);
        abort_unless($client && in_array($etat, ['tableau', 'plan'], true) && in_array($format, ['imprimer', 'pdf', 'xlsx', 'docx'], true), 404);
        $e = $etat === 'plan' ? EtatsImmobilisations::plan($client, $this->exercice($r)) : EtatsImmobilisations::tableau($client, $this->exercice($r));
        return $e->reponse($format);
    }
}
