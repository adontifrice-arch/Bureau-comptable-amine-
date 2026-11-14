<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Compte;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\Paie;
use App\Services\Compta;
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
        $data = ['client' => $client, 'tab' => $tab, 'annee' => $annee, 'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'plan' => Compta::plan()];
        if ($tab === 'ach') {
            $data['factures'] = FactureRecue::with('validePar')->where('client_id', $client->id)->orderByRaw("CASE statut WHEN 'prop' THEN 0 WHEN 'rej' THEN 1 ELSE 2 END")->orderByDesc('date')->get();
            $data['ia'] = (bool) config('services.anthropic.key');
        } elseif ($tab === 'pai') {
            $data['paies'] = Paie::where('client_id', $client->id)->whereIn('statut', ['transmise', 'val'])->orderByDesc('mois')->get();
        } elseif ($tab === 'jnl') {
            $data['ecritures'] = Ecriture::with(['lignes', 'validePar'])->where('client_id', $client->id)->whereYear('date', $annee)
                ->when($r->input('journal'), fn ($q, $j) => $q->where('journal', $j))->orderByDesc('date')->orderByDesc('id')->paginate(30)->withQueryString();
        } elseif ($tab === 'bal') {
            $data['B'] = Compta::balance($client->id, $annee);
            $data['T'] = Compta::totaux($data['B']);
        } elseif ($tab === 'gl') {
            $cpt = $r->input('compte');
            $data['compte'] = $cpt;
            $data['mouvements'] = $cpt ? DB::table('ecriture_lignes')->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
                ->where('ecritures.client_id', $client->id)->whereYear('ecritures.date', $annee)->where('ecriture_lignes.compte', 'like', $cpt.'%')
                ->orderBy('ecritures.date')->orderBy('ecritures.id')->select('ecritures.date', 'ecritures.journal', 'ecritures.libelle', 'ecriture_lignes.compte', 'debit', 'credit')->get() : collect();
        }
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
        return back()->with('ok', 'Facture enregistrée : en attente de validation par le chef comptable.');
    }

    public function factureUpdate(Request $r, FactureRecue $fr)
    {
        if ($fr->statut === 'val') return back()->with('err', 'Facture déjà comptabilisée : annulez d’abord l’écriture.');
        $d = $this->donnees($r);
        if ($r->hasFile('fichier')) $d['fichier'] = $r->file('fichier')->store('factures-recues/'.\App\Support\CabinetCourant::exiger());
        $fr->update($d + ['statut' => 'prop', 'motif_rejet' => null]);
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

    private function donnees(Request $r): array
    {
        {
            $d = $r->validate(['date' => 'required|date', 'fournisseur' => 'required|max:255', 'matricule_fiscal' => 'nullable|max:50', 'reference' => 'nullable|max:100',
                'description' => 'nullable|max:255', 'ht' => 'required|numeric|min:0.001', 'tva_taux' => 'nullable|numeric|min:0', 'tva' => 'nullable|numeric|min:0',
                'timbre' => 'nullable|numeric|min:0', 'compte' => 'nullable|max:20', 'tva_deductible' => 'nullable|boolean', 'retenue_taux' => 'nullable|numeric|min:0|max:100',
                'fichier' => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,webp']);
            unset($d['fichier']);
            $d['tva_taux'] = $d['tva_taux'] ?? 19;
            $d['tva'] = $r->filled('tva') ? $d['tva'] : r3($d['ht'] * $d['tva_taux'] / 100);
            $d['timbre'] = $d['timbre'] ?? 0;
            $d['retenue_taux'] = $d['retenue_taux'] ?? 0;
            $d['tva_deductible'] = $r->boolean('tva_deductible', true);
            return $d;
        }
    }

    /** Validation par le chef comptable → comptabilisation au journal des achats. */
    public function valider(FactureRecue $fr)
    {
        if (! $fr->compte) return back()->with('err', 'Choisissez le compte de charge avant de valider (bouton modifier).');
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

    /** Annule une écriture (et remet la facture / paie d'origine en attente). */
    public function annuler(Ecriture $ecriture)
    {
        DB::transaction(function () use ($ecriture) {
            if ($ecriture->source && str_starts_with($ecriture->source, 'fr:')) FactureRecue::whereKey(substr($ecriture->source, 3))->update(['statut' => 'prop', 'valide_par' => null]);
            if ($ecriture->source && str_starts_with($ecriture->source, 'paie:')) Paie::whereKey(substr($ecriture->source, 5))->update(['statut' => 'transmise']);
            $ecriture->delete();
        });
        $this->log('Annulation de l’écriture '.$ecriture->libelle, $ecriture->client_id);
        return back()->with('ok', 'Écriture annulée.');
    }

    public function validerPaie(Paie $paie)
    {
        if (Ecriture::where('source', 'paie:'.$paie->id)->exists()) return back()->with('err', 'Cette paie est déjà comptabilisée.');
        DB::transaction(function () use ($paie) {
            Compta::passer($paie->client_id, \Carbon\Carbon::parse($paie->mois.'-01')->endOfMonth()->format('Y-m-d'), 'PAI', 'Paie de '.mois_fr($paie->mois),
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

    public function compteStore(Request $r)
    {
        $d = $r->validate(['numero' => 'required|max:20|'.unique_cab('comptes', 'numero'), 'libelle' => 'required|max:255']);
        Compte::create($d + ['personnalise' => true]);
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
