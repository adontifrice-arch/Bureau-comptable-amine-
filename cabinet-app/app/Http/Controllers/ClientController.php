<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Associe;
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

    public function store(ClientRequest $r)
    {
        // Reprise : balance N-1 facultative, lue après la création puis présentée pour contrôle dans la comptabilité du dossier
        $r->validate(['balance_n1' => 'nullable|'.str_replace('required|', '', BalanceN1Controller::FICHIER), 'exercice' => 'nullable|integer|min:2000|max:2100'],
            collect(BalanceN1Controller::messages())->mapWithKeys(fn ($m, $k) => [str_replace('fichier', 'balance_n1', $k) => $m])->all());
        $client = DB::transaction(function () use ($r) {
            $c = Client::create($r->client());
            $this->syncAssocies($c, $r->associes());
            EcheanceController::acomptesProvisionnels($c);
            \App\Services\EcheancesFiscales::genererMois($c, now()->year, now()->month);
            return $c;
        });
        $this->log('Création du client '.$client->nom, $client->id);
        if ($r->hasFile('balance_n1')) {
            session(['dossier' => $client->id]);
            $annee = (int) ($r->input('exercice') ?: now()->year);
            if ($err = BalanceN1Controller::lireFichier($r, $client, $r->file('balance_n1'), $annee)) {
                return redirect()->route('compta.balance-n1', ['annee' => $annee])->with('err', 'Client créé. '.$err);
            }
            return redirect()->route('compta.balance-n1')->with('ok', 'Client créé. Contrôlez la balance '.($annee - 1).' lue avant de l’enregistrer.');
        }
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
        return view('clients.form', ['client' => $client, 'associes' => $client->associes->map(fn ($a) => $a->only(Associe::CHAMPS))->map(fn ($a) => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $a))->values()]);
    }

    public function update(ClientRequest $r, Client $client)
    {
        DB::transaction(function () use ($r, $client) {
            $etaitPhysique = $client->estPhysique();
            $client->update($r->client());
            $this->syncAssocies($client, $r->associes());
            // Changement de nature (personne morale ↔ physique) : les acomptes non cochés passent au 25 ou au 28.
            if ($client->estPhysique() !== $etaitPhysique) EcheanceController::acomptesProvisionnels($client);
            // Nature ou mode de dépôt modifiés : échéances non cochées du mois remises au bon jour
            \App\Services\EcheancesFiscales::genererMois($client->fresh(), now()->year, now()->month);
        });
        $this->log('Modification du client '.$client->nom, $client->id);
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

    private function syncAssocies(Client $c, array $associes): void
    {
        $c->associes()->delete();
        foreach ($associes as $a) $c->associes()->create($a);
    }
}
