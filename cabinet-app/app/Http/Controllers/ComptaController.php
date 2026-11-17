<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Compte;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\ModeleSaisie;
use App\Models\Releve;
use App\Models\ReleveLigne;
use App\Models\Paie;
use App\Services\Compta;
use App\Services\Recherche;
use App\Services\Saisie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ComptaController extends Controller
{
    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        $tab = $r->input('tab', 'ach');
        $annee = (int) $r->input('annee', now()->year);
        $data = ['client' => $client, 'tab' => $tab, 'annee' => $annee, 'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'plan' => Compta::plan(),
            'saisie' => Saisie::parametres($client->id), 'aTraiter' => ReleveLigne::where('client_id', $client->id)->where('statut', 'att')->count()];
        if (in_array($tab, ['ach', 'vte', 'param'], true)) $data['modeles'] = ModeleSaisie::where('client_id', $client->id)->orderBy('type')->orderBy('nom')->get();
        // Recherche multicritère (bouton « Rechercher ») : période, montant, partie du libellé, compte
        $rech = $data['rech'] = Recherche::depuis($r);
        if ($tab === 'ach') {
            $q = FactureRecue::with('validePar')->where('client_id', $client->id);
            $rech->dates($q, 'date'); $rech->montants($q, ['ht', 'ht + tva + timbre']); $rech->texte($q, ['fournisseur', 'description', 'reference', 'matricule_fiscal']); $rech->compte($q, 'compte');
            $data['factures'] = $q->orderByRaw("CASE statut WHEN 'prop' THEN 0 WHEN 'rej' THEN 1 ELSE 2 END")->orderByDesc('date')->get();
            $data['resultats'] = ['n' => $data['factures']->count(), 'total' => $data['factures']->sum(fn ($f) => $f->ttc()), 'unite' => 'TTC'];
            $data['ia'] = (bool) config('services.anthropic.key');
        } elseif ($tab === 'vte') {
            $q = FactureVente::with('validePar')->where('client_id', $client->id);
            $rech->dates($q, 'date'); $rech->montants($q, ['ht', 'ht + tva + timbre']); $rech->texte($q, ['tiers', 'description', 'reference', 'matricule_fiscal']); $rech->compte($q, 'compte');
            $data['ventes'] = $q->orderByRaw("CASE statut WHEN 'prop' THEN 0 WHEN 'rej' THEN 1 ELSE 2 END")->orderByDesc('date')->get();
            $data['resultats'] = ['n' => $data['ventes']->count(), 'total' => $data['ventes']->sum(fn ($f) => $f->ttc()), 'unite' => 'TTC'];
        } elseif ($tab === 'bq') {
            $data['releves'] = Releve::withCount(['lignes', 'lignes as a_traiter' => fn ($q) => $q->where('statut', 'att')])->with('importePar')->where('client_id', $client->id)->latest()->get();
            $data['releve'] = $r->filled('releve') ? $data['releves']->firstWhere('id', (int) $r->input('releve')) : null;
            $data['filtre'] = $r->input('statut', $rech->active() ? 'tout' : 'att');      // une recherche porte sur toutes les lignes
            $q = ReleveLigne::where('client_id', $client->id)->when($data['releve'], fn ($q, $rl) => $q->where('releve_id', $rl->id))
                ->when($data['filtre'] !== 'tout', fn ($q) => $q->where('statut', $data['filtre']));
            $rech->dates($q, 'date'); $rech->montants($q, ['debit', 'credit']); $rech->texte($q, ['libelle']); $rech->compte($q, 'compte');
            $data['resultats'] = ['n' => (clone $q)->count(), 'debit' => (float) (clone $q)->sum('debit'), 'credit' => (float) (clone $q)->sum('credit')];
            $data['lignes'] = $q->orderBy('date')->orderBy('id')->paginate(100)->withQueryString();
        } elseif ($tab === 'pai') {
            $data['paies'] = Paie::where('client_id', $client->id)->whereIn('statut', ['transmise', 'val'])->orderByDesc('mois')->get();
        } elseif ($tab === 'jnl') {
            // Une période recherchée remplace l'exercice affiché
            $q = Ecriture::with(['lignes', 'validePar'])->where('client_id', $client->id)->when(! $rech->du && ! $rech->au, fn ($q) => $q->whereYear('date', $annee))
                ->when($r->input('journal'), fn ($q, $j) => $q->where('journal', $j));
            $rech->dates($q, 'date'); $rech->texte($q, ['libelle']);
            if ($rech->min !== null || $rech->max !== null) $q->whereHas('lignes', fn ($l) => $rech->montants($l, ['debit', 'credit']));
            if ($rech->compte) $q->whereHas('lignes', fn ($l) => $rech->compte($l, 'compte'));
            $data['resultats'] = ['n' => (clone $q)->count()];
            $data['ecritures'] = $q->orderByDesc('date')->orderByDesc('id')->paginate(30)->withQueryString();
        } elseif ($tab === 'bal') {
            $data['B'] = Compta::balance($client->id, $annee);
            $data['T'] = Compta::totaux($data['B']);
        } elseif ($tab === 'gl') {
            // Compte exact (double-clic sur une ligne de la balance) ou racine (« 6 », « 401 »…)
            $cpt = $r->input('compte');
            $data['compte'] = $cpt;
            $data['exact'] = $r->boolean('exact');
            $data['mouvements'] = $cpt ? DB::table('ecriture_lignes')->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
                ->whereNull('ecritures.deleted_at')->where('ecritures.client_id', $client->id)->whereYear('ecritures.date', $annee)
                ->when($data['exact'], fn ($q) => $q->where('ecriture_lignes.compte', $cpt), fn ($q) => $q->where('ecriture_lignes.compte', 'like', $cpt.'%'))
                ->orderBy('ecritures.date')->orderBy('ecritures.id')
                ->select('ecritures.id as ecriture_id', 'ecritures.date', 'ecritures.journal', 'ecritures.libelle', 'ecriture_lignes.compte', 'debit', 'credit')->get() : collect();
        } elseif ($tab === 'corb') {
            // Pièces supprimées : écritures restaurables
            $data['supprimees'] = Ecriture::onlyTrashed()->with(['lignes', 'supprimePar'])->where('client_id', $client->id)->orderByDesc('deleted_at')->paginate(30)->withQueryString();
        }
        $data['nbSupprimees'] = Ecriture::onlyTrashed()->where('client_id', $client->id)->count();
        return view('compta.index', $data);
    }

    // ---------- Factures reçues ----------
    public function factureStore(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        if ($r->boolean('ia')) return $this->analyser($r, $client);
        $d = $this->donnees($r);
        if ($r->hasFile('fichier')) $d['fichier'] = $r->file('fichier')->store('factures-recues/'.\App\Support\CabinetCourant::exiger());
        FactureRecue::create($d + ['client_id' => $client->id, 'statut' => 'prop']);
        if ($r->boolean('memoriser')) ModeleSaisie::memoriser($client->id, 'achat', $d, $d['fournisseur']);
        return back()->with('ok', 'Facture enregistrée : en attente de validation par le chef comptable.');
    }

    public function factureUpdate(Request $r, FactureRecue $fr)
    {
        if ($fr->statut === 'val') return back()->with('err', 'Facture déjà comptabilisée : annulez d’abord l’écriture.');
        $d = $this->donnees($r);
        if ($r->hasFile('fichier')) $d['fichier'] = $r->file('fichier')->store('factures-recues/'.\App\Support\CabinetCourant::exiger());
        $fr->update($d + ['statut' => 'prop', 'motif_rejet' => null]);
        if ($r->boolean('memoriser')) ModeleSaisie::memoriser($fr->client_id, 'achat', $d, $d['fournisseur']);
        return back()->with('ok', 'Facture modifiée.');
    }

    public function factureDestroy(FactureRecue $fr)
    {
        if ($fr->statut === 'val') return back()->with('err', 'Facture comptabilisée : annulez d’abord l’écriture.');
        if ($fr->fichier) Storage::delete($fr->fichier);
        $fr->delete();
        return back()->with('ok', 'Facture supprimée.');
    }

    public function factureFichier(FactureRecue $fr)
    {
        abort_unless($fr->fichier && Storage::exists($fr->fichier), 404);
        return Storage::response($fr->fichier);
    }

    /** Facture d'achat : lignes « compte · HT · taux · TVA » (ou, saisie à une seule ligne, champs ht / compte / tva_taux / tva). */
    private function donnees(Request $r): array
    {
        $ventilee = $r->has('lignes');
        $d = $r->validate(['date' => 'required|date', 'fournisseur' => 'required|max:255', 'matricule_fiscal' => 'nullable|max:50', 'reference' => 'nullable|max:100',
            'description' => 'nullable|max:255', 'timbre' => 'nullable|numeric|min:0', 'tva_deductible' => 'nullable|boolean', 'retenue_taux' => 'nullable|numeric|min:0|max:100',
            'fichier' => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,webp'] + ['suspension_ref' => 'nullable|regex:/^\d{1,13}$/'] + FactureRecue::reglesSuspension() + ($ventilee
            ? ['lignes' => 'required|array|min:1|max:50', 'lignes.*.compte' => 'nullable|regex:/^\d{1,10}$/', 'lignes.*.ht' => 'nullable|numeric|min:0',
                'lignes.*.tva_taux' => 'nullable|numeric|min:0|max:100', 'lignes.*.tva' => 'nullable|numeric|min:0']
            : ['ht' => 'required|numeric|min:0.001', 'tva_taux' => 'nullable|numeric|min:0', 'tva' => 'nullable|numeric|min:0', 'compte' => 'nullable|max:20']),
            ['lignes.*.compte.regex' => 'Un compte ne comporte que des chiffres.']);
        unset($d['fichier']);
        $lignes = $ventilee ? $d['lignes'] : [['compte' => $d['compte'] ?? '', 'ht' => $d['ht'], 'tva_taux' => $d['tva_taux'] ?? null, 'tva' => $r->filled('tva') ? $d['tva'] : null]];
        $v = FactureRecue::ventiler($lignes, (float) ($d['tva_taux'] ?? Saisie::achat((int) session('dossier'))['tva_taux']));
        if ($v['ht'] <= 0) throw \Illuminate\Validation\ValidationException::withMessages(['lignes' => 'Saisissez au moins une ligne avec un montant HT.']);
        unset($d['lignes']);
        $d = $v + $d;
        $d['timbre'] = $d['timbre'] ?? 0;
        $d['retenue_taux'] = $d['retenue_taux'] ?? 0;
        $d['tva_deductible'] = $r->boolean('tva_deductible', true);
        return FactureRecue::appliquerSuspension($d, $r->boolean('tva_suspension'));
    }

    /** Validation par le chef comptable → comptabilisation au journal des achats. */
    public function valider(FactureRecue $fr)
    {
        if ($fr->compteManquant()) return back()->with('err', 'Choisissez le compte de chaque ligne avant de valider (bouton modifier).');
        DB::transaction(function () use ($fr) {
            Compta::passer($fr->client_id, $fr->date->format('Y-m-d'), 'ACH', 'Facture '.($fr->reference ?: '').' – '.$fr->fournisseur, $fr->lignesEcriture(), 'fr:'.$fr->id, auth()->id());
            $fr->update(['statut' => 'val', 'valide_par' => auth()->id()]);
        });
        $this->log('Validation de la facture '.$fr->fournisseur.' '.$fr->reference, $fr->client_id);
        return back()->with('ok', 'Facture validée et comptabilisée au journal des achats.');
    }

    public function rejeter(Request $r, FactureRecue $fr)
    {
        $fr->update(['statut' => 'rej', 'motif_rejet' => $r->input('motif') ?: 'Rejetée par le chef comptable']);
        return back()->with('ok', 'Facture rejetée.');
    }

    // ---------- Écriture : consultation, modification, suppression (« Pièces supprimées »), restauration ----------

    private const REFUS = 'Modifier ou supprimer une écriture est réservé au chef comptable et aux collaborateurs qu’il y autorise (Paramètres → Utilisateurs).';

    /** Retour vers la page d'origine (grand livre, journal, pièces supprimées), limité à la comptabilité. */
    private function retour(Request $r, Ecriture $e): string
    {
        $u = (string) $r->input('retour');
        return $u !== '' && str_starts_with($u, route('compta.index')) ? $u : route('compta.index', ['tab' => 'jnl', 'annee' => $e->date->year]);
    }

    public function ecriture(Request $r, Ecriture $ecriture)
    {
        $ecriture->load(['lignes', 'validePar', 'supprimePar']);
        if ((int) session('dossier') !== $ecriture->client_id) session(['dossier' => $ecriture->client_id]);
        return view('compta.ecriture', ['e' => $ecriture, 'plan' => Compta::plan(), 'retour' => $this->retour($r, $ecriture),
            'autorise' => \App\Services\Ecritures::autorise($r->user())]);
    }

    public function ecritureUpdate(Request $r, Ecriture $ecriture)
    {
        if (! \App\Services\Ecritures::autorise($r->user())) return back()->with('err', self::REFUS);
        if ($ecriture->trashed()) return back()->with('err', 'Restaurez l’écriture avant de la modifier.');
        $d = $r->validate(['date' => 'required|date', 'journal' => 'required|in:'.implode(',', array_keys(Ecriture::JOURNAUX)), 'libelle' => 'required|max:255',
            'lignes' => 'required|array', 'lignes.*.compte' => 'nullable|regex:/^\d{1,10}$/', 'lignes.*.debit' => 'nullable|numeric|min:0', 'lignes.*.credit' => 'nullable|numeric|min:0'],
            ['lignes.*.compte.regex' => 'Un compte ne comporte que des chiffres.']);
        try {
            \App\Services\Ecritures::modifier($ecriture, $d);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('err', $e->getMessage());
        }
        $this->log('Modification de l’écriture '.$ecriture->libelle, $ecriture->client_id);
        return back()->with('ok', 'Écriture modifiée.');
    }

    /** Suppression (aussi le bouton « Annuler » du journal) : l'écriture part dans « Pièces supprimées » et sa pièce d'origine repasse en attente. */
    public function annuler(Request $r, Ecriture $ecriture)
    {
        if (! \App\Services\Ecritures::autorise($r->user())) return back()->with('err', self::REFUS);
        $r->validate(['motif' => 'nullable|max:255']);
        \App\Services\Ecritures::supprimer($ecriture, $r->user(), $r->input('motif'));
        $this->log('Suppression de l’écriture '.$ecriture->libelle.($r->filled('motif') ? ' — motif : '.$r->input('motif') : ''), $ecriture->client_id);
        $msg = 'Écriture supprimée : elle est conservée dans « Pièces supprimées », d’où elle peut être restaurée.';
        return $r->boolean('depuis_fiche') ? redirect($this->retour($r, $ecriture))->with('ok', $msg) : back()->with('ok', $msg);
    }

    public function restaurer(Request $r, Ecriture $ecriture)
    {
        if (! \App\Services\Ecritures::autorise($r->user())) return back()->with('err', self::REFUS);
        try {
            \App\Services\Ecritures::restaurer($ecriture);
        } catch (\RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }
        $this->log('Restauration de l’écriture '.$ecriture->libelle, $ecriture->client_id);
        return back()->with('ok', 'Écriture restaurée : elle figure de nouveau au journal, dans la balance et le grand livre.');
    }

    public function validerPaie(Paie $paie)
    {
        if (Ecriture::where('source', 'paie:'.$paie->id)->exists()) return back()->with('err', 'Cette paie est déjà comptabilisée.');
        DB::transaction(function () use ($paie) {
            Compta::passer($paie->client_id, \Carbon\Carbon::parse($paie->mois.'-01')->endOfMonth()->format('Y-m-d'), 'PAI', $paie->libelle(),
                \App\Services\PaieCalc::lignes($paie->totaux), 'paie:'.$paie->id, auth()->id());
            $paie->update(['statut' => 'val']);
        });
        return back()->with('ok', 'Paie comptabilisée au journal de paie.');
    }

    public function odStore(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['date' => 'required|date', 'journal' => 'required|in:OD,VTE,ACH,BQ,CAI,PAI,AN', 'libelle' => 'required|max:255', 'lignes' => 'required|array']);
        $L = collect($d['lignes'])->filter(fn ($l) => ! empty($l['compte']) && ((float) ($l['debit'] ?? 0) || (float) ($l['credit'] ?? 0)))
            ->map(fn ($l) => ['compte' => trim($l['compte']), 'debit' => r3($l['debit'] ?? 0), 'credit' => r3($l['credit'] ?? 0)])->values();
        if ($L->count() < 2) return back()->withInput()->with('err', 'Saisissez au moins deux lignes.');
        if (abs($L->sum('debit') - $L->sum('credit')) > 0.0005) return back()->withInput()->with('err', 'Écriture déséquilibrée : débit '.tnd($L->sum('debit')).' ≠ crédit '.tnd($L->sum('credit')).'.');
        Compta::passer($client->id, $d['date'], $d['journal'], $d['libelle'], $L->all(), null, auth()->id());
        return back()->with('ok', 'Écriture enregistrée.');
    }

    /** Ajout d'un compte (onglet Plan comptable, ou création d'un sous-compte depuis un champ de saisie : réponse JSON). */
    public function compteStore(Request $r)
    {
        $d = $r->validate(['numero' => 'required|regex:/^\d{1,10}$/|'.unique_cab('comptes', 'numero'), 'libelle' => 'required|max:255'],
            ['numero.regex' => 'Le numéro de compte ne comporte que des chiffres (10 au plus).', 'numero.unique' => 'Ce compte existe déjà dans le plan comptable.']);
        $d['libelle'] = trim($d['libelle']);
        Compte::create($d + ['personnalise' => true]);
        $this->log('Compte '.$d['numero'].' '.$d['libelle'].' ajouté au plan comptable');
        if ($r->expectsJson()) return response()->json(['numero' => $d['numero'], 'libelle' => $d['libelle']], 201);
        return back()->with('ok', 'Compte ajouté au plan comptable.');
    }

    public function balancePrint(Request $r)
    {
        $client = $this->dossier($r); $annee = (int) $r->input('annee', now()->year);
        $B = Compta::balance($client->id, $annee);
        return view('compta.balance-print', ['client' => $client, 'annee' => $annee, 'B' => $B, 'T' => Compta::totaux($B)]);
    }

    public function balanceCsv(Request $r)
    {
        $client = $this->dossier($r); $annee = (int) $r->input('annee', now()->year);
        $n = fn ($v) => number_format((float) $v, 3, ',', '');
        $out = "\xEF\xBB\xBFCompte;Libellé;RAN débit;RAN crédit;Mouvements débit;Mouvements crédit;Solde débiteur;Solde créditeur\r\n";
        foreach (Compta::balance($client->id, $annee) as $b) $out .= implode(';', [$b['compte'], '"'.$b['libelle'].'"', $n($b['rd']), $n($b['rc']), $n($b['md']), $n($b['mc']), $n($b['sd']), $n($b['sc'])])."\r\n";
        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="balance-'.$annee.'-'.\Str::slug($client->nom).'.csv"']);
    }

    /** Lecture d'une facture fournisseur par l'IA (clé ANTHROPIC_API_KEY requise) : proposition à valider. */
    private function analyser(Request $r, Client $client)
    {
        $key = config('services.anthropic.key');
        if (! $key) return back()->with('err', 'L’analyse IA n’est pas configurée (ANTHROPIC_API_KEY dans le fichier .env).');
        $r->validate(['fichier' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,webp', 'texte' => 'nullable|max:20000']);
        if (! $r->hasFile('fichier') && ! $r->filled('texte')) return back()->with('err', 'Ajoutez une photo / un PDF de la facture ou collez son texte.');
        $comptes = collect(Compta::plan())->filter(fn ($l, $n) => preg_match('/^[26]/', $n) && ! preg_match('/^2[89]/', $n))->map(fn ($l, $n) => "$n $l")->implode(' ; ');
        $prompt = 'Tu es un comptable tunisien (Système comptable des entreprises). Lis la facture fournisseur fournie et réponds UNIQUEMENT par un objet JSON de cette forme : '
            .'{"fournisseur":"","matricule_fiscal":"","reference":"","date":"AAAA-MM-JJ","description":"","ht":0,"taux_tva":19,"tva":0,"timbre":0,"ttc":0,"compte":"","retenue_taux":0,"anomalies":[]}. '
            .'Choisis le compte de charge parmi : '.$comptes.'. Signale dans anomalies toute incohérence (calculs, matricule fiscal absent, date manquante). Montants en dinars avec 3 décimales.'
            .($r->filled('texte') ? "\n\nTexte de la facture :\n".$r->input('texte') : '');
        $content = [];
        $path = null;
        if ($r->hasFile('fichier')) {
            $f = $r->file('fichier'); $path = $f->store('factures-recues/'.\App\Support\CabinetCourant::exiger());
            $b64 = base64_encode(file_get_contents($f->getRealPath()));
            $content[] = $f->getMimeType() === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $f->getMimeType(), 'data' => $b64]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];
        try {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(90)
                ->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $content]]])->throw()->json();
            $txt = collect($res['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
            $j = json_decode(preg_replace('/^[^{]*|[^}]*$/s', '', $txt), true) ?: [];
        } catch (\Throwable $e) {
            return back()->with('err', 'Analyse IA impossible : '.$e->getMessage());
        }
        $ht = (float) ($j['ht'] ?? 0); $tx = (float) ($j['taux_tva'] ?? 19);
        FactureRecue::create(['client_id' => $client->id, 'statut' => 'prop', 'fichier' => $path,
            'fournisseur' => $j['fournisseur'] ?? 'Fournisseur à préciser', 'matricule_fiscal' => $j['matricule_fiscal'] ?? null, 'reference' => $j['reference'] ?? null,
            'date' => ! empty($j['date']) && strtotime($j['date']) ? $j['date'] : now(), 'description' => $j['description'] ?? null,
            'ht' => $ht, 'tva_taux' => $tx, 'tva' => isset($j['tva']) ? (float) $j['tva'] : r3($ht * $tx / 100), 'timbre' => (float) ($j['timbre'] ?? 0),
            'compte' => (string) ($j['compte'] ?? ''), 'retenue_taux' => (float) ($j['retenue_taux'] ?? 0), 'tva_deductible' => true, 'anomalies' => array_values((array) ($j['anomalies'] ?? []))]);
        return back()->with('ok', 'Facture analysée par l’IA : vérifiez la proposition avant validation par le chef comptable.');
    }
}
