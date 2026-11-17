<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PaieRubrique;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Paie — menu Rubrique : rubriques du dossier classées par catégorie (primes, indemnités, avantages en nature, autre) et fiche rubrique. */
class PaieRubriqueController extends Controller
{
    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        // Premier passage dans le menu : le dossier reçoit les listes standard (primes, indemnités).
        if ($n = PaieRubrique::initialiserStandard($client)) $this->log("Paie : rubriques standard ajoutées ($n)", $client->id);
        $rubriques = PaieRubrique::duDossier($client);
        $rubrique = match (true) {
            $r->filled('r') => $rubriques->firstWhere('id', (int) $r->input('r')) ?? abort(404),
            default => new PaieRubrique(['code' => PaieRubrique::prochainCode($client), 'mode' => 'manuelle',
                'categorie' => array_key_exists($r->input('categorie'), PaieRubrique::CATEGORIES) ? $r->input('categorie') : null]),
        };
        return view('paie.rubriques', PaieController::contexte($r, $client, 'rub', $r->input('mois', now()->format('Y-m'))) + [
            'rubriques' => $rubriques, 'rubrique' => $rubrique, 'profils' => PaieRubrique::profilsDisponibles($client),
        ]);
    }

    public function store(Request $r)
    {
        $client = $this->dossier($r);
        $rub = PaieRubrique::create(['client_id' => $client->id] + $this->valider($r, $client));
        $this->log('Paie : création de la rubrique '.$rub->code.' '.$rub->nom, $client->id);
        return redirect()->route('paie.rubriques', ['r' => $rub->id, 'mois' => $r->input('mois')])->with('ok', 'Rubrique « '.$rub->nom.' » enregistrée.');
    }

    public function update(Request $r, PaieRubrique $rubrique)
    {
        $client = $this->dossier($r);
        abort_unless($rubrique->client_id === $client->id, 404);
        $rubrique->update($this->valider($r, $client, $rubrique));
        $this->log('Paie : modification de la rubrique '.$rubrique->code.' '.$rubrique->nom, $client->id);
        return redirect()->route('paie.rubriques', ['r' => $rubrique->id, 'mois' => $r->input('mois')])->with('ok', 'Rubrique « '.$rubrique->nom.' » enregistrée.');
    }

    /** Rajoute les rubriques standard manquantes d'une catégorie (primes ou indemnités supprimées). */
    public function standard(Request $r)
    {
        $client = $this->dossier($r);
        $cat = $r->validate(['categorie' => ['required', Rule::in(array_keys(PaieRubrique::STANDARD))]])['categorie'];
        $n = PaieRubrique::ajouterStandard($client, $cat);
        if ($n) $this->log("Paie : rubriques standard ajoutées ($n, ".mb_strtolower(PaieRubrique::CATEGORIES[$cat]).')', $client->id);
        return back()->with('ok', $n ? "$n rubrique(s) standard ajoutée(s) dans « ".PaieRubrique::CATEGORIES[$cat].' ».' : 'Toute la liste standard est déjà dans le dossier.');
    }

    /** Suppression refusée si une autre rubrique est calculée sur celle-ci. Les paies clôturées gardent leurs montants figés. */
    public function destroy(Request $r, PaieRubrique $rubrique)
    {
        $client = $this->dossier($r);
        abort_unless($rubrique->client_id === $client->id, 404);
        $dep = PaieRubrique::duDossier($client)->filter(fn ($x) => in_array($rubrique->id, $x->dependances(), true));
        if ($dep->isNotEmpty()) return back()->with('err', 'Rubrique utilisée par : '.$dep->pluck('nom')->join(', ').'. Modifiez d’abord ces rubriques.');
        $rubrique->delete();
        $this->log('Paie : suppression de la rubrique '.$rubrique->code.' '.$rubrique->nom, $client->id);
        return redirect()->route('paie.rubriques', ['mois' => $r->input('mois')])->with('ok', 'Rubrique supprimée.');
    }

    private function valider(Request $r, Client $client, ?PaieRubrique $actuelle = null): array
    {
        $autres = PaieRubrique::where('client_id', $client->id)->when($actuelle, fn ($q) => $q->where('id', '!=', $actuelle->id))->pluck('id')->all();
        $d = $r->validate([
            'code' => ['required', 'integer', 'min:1', Rule::unique('paie_rubriques')->where('client_id', $client->id)->ignore($actuelle?->id)],
            'categorie' => ['required', Rule::in(array_keys(PaieRubrique::CATEGORIES))],
            'nom' => 'required|string|max:120',
            'profils' => 'nullable|array', 'profils.*' => 'string|max:60',
            'mode' => ['required', Rule::in(array_keys(PaieRubrique::MODES))],
            'montant' => 'nullable|required_if:mode,montant|numeric',
            'base' => ['nullable', 'required_if:mode,pourcentage,proportion', Rule::in([...array_keys(PaieRubrique::BASES), ...array_map(fn ($id) => 'r:'.$id, $autres)])],
            'taux' => 'nullable|required_if:mode,pourcentage|numeric',
            'numerateur' => 'nullable|required_if:mode,proportion|numeric',
            'denominateur' => 'nullable|required_if:mode,proportion|numeric|not_in:0',
            'note_id' => ['nullable', 'required_if:depend_note,1', Rule::in($autres)],
            'nb_jours_ouv' => 'nullable|numeric|min:0|max:31',
            'nb_heures_ouv' => 'nullable|numeric|min:0|max:744',
        ], [
            'code.unique' => 'Ce code est déjà utilisé par une autre rubrique du dossier.',
            'note_id.required_if' => 'Choisissez la note dont dépend la rubrique.',
            '*.required_if' => 'Champ obligatoire pour ce mode de calcul.',
        ]);
        foreach (array_keys(PaieRubrique::OPTIONS) as $c) $d[$c] = $r->boolean($c);
        if ($d['note_id'] ?? null) {
            $note = PaieRubrique::find($d['note_id']);
            if (! $note?->note) throw \Illuminate\Validation\ValidationException::withMessages(['note_id' => 'La rubrique choisie n’est pas une note.']);
        }
        if (! $d['depend_note']) $d['note_id'] = null;
        $d['profils'] = array_values(array_unique(array_filter((array) ($d['profils'] ?? [])))) ?: null;
        // Seules les valeurs du mode choisi sont conservées.
        $garder = ['montant' => ['montant'], 'pourcentage' => ['base', 'taux'], 'proportion' => ['base', 'numerateur', 'denominateur'], 'manuelle' => []][$d['mode']];
        foreach (['montant', 'base', 'taux', 'numerateur', 'denominateur'] as $c) if (! in_array($c, $garder, true)) $d[$c] = null;
        // Total brut et salaire imposable : seulement pour une retenue non cotisable (déduite du net), sinon la rubrique changerait sa propre base.
        if (in_array($d['base'], PaieRubrique::BASES_TOTAUX, true) && (! $d['a_deduire'] || ! $d['non_cotisable'] || $d['valeur_net'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['base' => 'Le total brut et le salaire imposable ne servent de base qu’à une retenue « à déduire » et « non cotisable » (retenue sur le net).']);
        }
        return $d;
    }
}
