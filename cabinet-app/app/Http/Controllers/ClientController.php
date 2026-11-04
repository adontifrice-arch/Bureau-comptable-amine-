<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Echeance;
use App\Models\Exigence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string) $r->input('q'));
        $clients = Client::query()
            ->when($q, fn ($x) => $x->where(fn ($w) => $w->where('nom', 'like', "%$q%")->orWhere('matricule_fiscal', 'like', "%$q%")
                ->orWhere('gerant_nom', 'like', "%$q%")->orWhere('denomination_commerciale', 'like', "%$q%")))
            ->when($r->input('type'), fn ($x, $t) => $x->where('type_personne', $t))
            ->withCount(['echeances as retard_count' => fn ($e) => $e->whereNull('accomplie_le')->where('date_echeance', '<', now()->startOfDay())])
            ->withCount(['echeances as ouvertes_count' => fn ($e) => $e->whereNull('accomplie_le')])
            ->orderBy('nom')->paginate(20)->withQueryString();
        return view('clients.index', compact('clients', 'q'));
    }

    public function create() { return view('clients.form', ['client' => new Client(), 'associes' => []]); }

    public function store(Request $r)
    {
        $client = DB::transaction(function () use ($r) {
            $c = Client::create($this->valider($r));
            $this->syncAssocies($c, $r);
            EcheanceController::acomptesProvisionnels($c);
            return $c;
        });
        $this->log('Création du client '.$client->nom, $client->id);
        return redirect()->route('clients.show', $client)->with('ok', 'Client créé. Les acomptes provisionnels ont été ajoutés aux échéances.');
    }

    public function show(Request $r, Client $client)
    {
        $tab = $r->input('tab', 'fiche');
        $client->load(['associes', 'exigences' => fn ($q) => $q->orderByRaw('date_limite is null')->orderBy('date_limite')]);
        $echeances = $client->echeances()->orderByRaw('accomplie_le is not null')->orderBy('date_echeance')->limit(50)->get();
        $factures = $client->factures()->with('lignes')->latest('date')->get();
        $documents = $client->documents()->latest()->get();
        $actes = \App\Models\Acte::where('client_id', $client->id)->latest()->get();
        return view('clients.show', compact('client', 'tab', 'echeances', 'factures', 'documents', 'actes'));
    }

    public function edit(Client $client)
    {
        return view('clients.form', ['client' => $client, 'associes' => $client->associes->map(fn ($a) => $a->only([
            'nom', 'cin', 'cin_date', 'cin_lieu', 'passeport', 'nationalite', 'adresse', 'naissance_date', 'naissance_lieu', 'parts', 'parts_de', 'parts_a', 'apport',
        ]))->map(fn ($a) => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $a))->values()]);
    }

    public function update(Request $r, Client $client)
    {
        DB::transaction(function () use ($r, $client) {
            $client->update($this->valider($r));
            $this->syncAssocies($client, $r);
        });
        return redirect()->route('clients.show', $client)->with('ok', 'Fiche client enregistrée.');
    }

    public function destroy(Client $client)
    {
        $nom = $client->nom;
        $client->delete();
        $this->log('Suppression du client '.$nom);
        return redirect()->route('clients.index')->with('ok', "Client « $nom » supprimé avec ses échéances, factures et documents.");
    }

    // ---------- Exigences (obligations du client) ----------
    public function exigenceStore(Request $r, Client $client)
    {
        $client->exigences()->create($this->exig($r));
        return back()->with('ok', 'Exigence ajoutée.');
    }

    public function exigenceUpdate(Request $r, Exigence $exigence)
    {
        $exigence->update($this->exig($r));
        return back()->with('ok', 'Exigence modifiée.');
    }

    public function exigenceDestroy(Exigence $exigence)
    {
        $exigence->delete();
        return back()->with('ok', 'Exigence supprimée.');
    }

    public function exigenceToEcheance(Exigence $exigence)
    {
        if (! $exigence->date_limite) return back()->with('err', 'Indiquez d’abord une date limite pour créer l’échéance.');
        Echeance::create(['client_id' => $exigence->client_id, 'categorie' => $exigence->organisme, 'intitule' => $exigence->intitule, 'date_echeance' => $exigence->date_limite]);
        return back()->with('ok', 'Échéance créée : '.$exigence->intitule);
    }

    private function exig(Request $r): array
    {
        return $r->validate(['intitule' => 'required|max:255', 'organisme' => 'nullable|max:100', 'periodicite' => 'nullable|max:50',
            'date_limite' => 'nullable|date', 'remarque' => 'nullable|max:2000']);
    }

    private function valider(Request $r): array
    {
        $d = $r->validate([
            'nom' => 'required|max:255', 'forme' => 'nullable|max:100', 'type_personne' => 'nullable|in:morale,physique',
            'denomination_commerciale' => 'nullable|max:255', 'residence' => 'nullable|in:residente,non_residente',
            'regime_export' => 'nullable|in:totale,partielle', 'mission' => 'nullable|max:255', 'honoraires_mensuels' => 'nullable|numeric|min:0',
            'matricule_fiscal' => 'nullable|max:50', 'compte_comptable' => 'nullable|max:20', 'matricule_cnss' => 'nullable|max:50',
            'matricule_independant' => 'nullable|max:50', 'capital' => 'nullable|numeric|min:0', 'nb_parts' => 'nullable|integer|min:0',
            'valeur_nominale' => 'nullable|numeric|min:0', 'adresse' => 'nullable|max:255', 'email' => 'nullable|email|max:255', 'telephone' => 'nullable|max:50',
            'gerant_nom' => 'nullable|max:255', 'gerant_cin' => 'nullable|max:50', 'gerant_cin_date' => 'nullable|date', 'gerant_cin_lieu' => 'nullable|max:100',
            'gerant_carte_sejour' => 'nullable|max:50', 'gerant_passeport' => 'nullable|max:50', 'gerant_passeport_date' => 'nullable|date',
            'gerant_passeport_validite' => 'nullable|date', 'gerant_nationalite' => 'nullable|max:100', 'gerant_adresse' => 'nullable|max:255',
            'gerant_naissance_date' => 'nullable|date', 'gerant_naissance_lieu' => 'nullable|max:100', 'gerant_nomination_date' => 'nullable|date',
            'gerant_nomination_duree' => 'nullable|max:100', 'gerant_associe' => 'nullable|in:0,1', 'gerant_parts' => 'nullable|integer|min:0',
            'gerant_parts_de' => 'nullable|integer|min:0', 'gerant_parts_a' => 'nullable|integer|min:0', 'gerant_apport' => 'nullable|in:numeraire,nature',
        ]);
        $d['honoraires_mensuels'] = $d['honoraires_mensuels'] ?? 0;
        $d['capital'] = $d['capital'] ?? 0;
        if (! empty($d['nb_parts']) && ! empty($d['valeur_nominale']) && empty($r->input('capital'))) $d['capital'] = r3($d['nb_parts'] * $d['valeur_nominale']);
        $d['gerant_associe'] = isset($d['gerant_associe']) && $d['gerant_associe'] !== null && $d['gerant_associe'] !== '' ? (bool) $d['gerant_associe'] : null;
        return $d;
    }

    private function syncAssocies(Client $c, Request $r): void
    {
        $c->associes()->delete();
        foreach ((array) $r->input('associes', []) as $a) {
            if (empty(trim($a['nom'] ?? ''))) continue;
            $a = array_map(fn ($v) => $v === '' ? null : $v, array_intersect_key($a, array_flip([
                'nom', 'cin', 'cin_date', 'cin_lieu', 'passeport', 'nationalite', 'adresse', 'naissance_date', 'naissance_lieu', 'parts', 'parts_de', 'parts_a', 'apport'])));
            $a['parts'] = (int) ($a['parts'] ?? 0);
            if (! $a['parts'] && ! empty($a['parts_de']) && ! empty($a['parts_a']) && $a['parts_a'] >= $a['parts_de']) $a['parts'] = $a['parts_a'] - $a['parts_de'] + 1;
            $a['apport'] = $a['apport'] ?? 'numeraire';
            $c->associes()->create($a);
        }
    }
}
