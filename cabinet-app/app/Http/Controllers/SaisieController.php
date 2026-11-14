<?php

namespace App\Http\Controllers;

use App\Models\FactureVente;
use App\Models\ModeleSaisie;
use App\Services\Compta;
use App\Services\Saisie;
use App\Support\CabinetCourant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Paramétrage de la saisie, modèles de factures d'achat / de vente et factures de vente du dossier. */
class SaisieController extends Controller
{
    // ---------- Paramétrage du dossier ----------
    public function parametres(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $cpt = 'nullable|regex:/^\d{1,8}$/';
        $d = $r->validate([
            'achat.compte_tiers' => 'required|regex:/^\d{1,8}$/', 'achat.tva_taux' => 'required|numeric|min:0|max:100',
            'achat.timbre' => 'nullable|numeric|min:0', 'achat.retenue_taux' => 'nullable|numeric|min:0|max:100', 'achat.tva_deductible' => 'nullable|boolean',
            'achat.comptes' => 'nullable|array|max:60', 'achat.comptes.*.compte' => $cpt, 'achat.comptes.*.tva_taux' => 'nullable|numeric|min:0|max:100',
            'vente.compte_tiers' => 'required|regex:/^\d{1,8}$/', 'vente.tva_taux' => 'required|numeric|min:0|max:100',
            'vente.timbre' => 'nullable|numeric|min:0', 'vente.compte_timbre' => 'required|regex:/^\d{1,8}$/',
            'vente.comptes' => 'nullable|array|max:60', 'vente.comptes.*.compte' => 'nullable|regex:/^7\d{0,7}$/', 'vente.comptes.*.tva_taux' => 'nullable|numeric|min:0|max:100',
            'banque.compte' => 'required|regex:/^\d{1,8}$/', 'banque.attente' => 'required|regex:/^\d{1,8}$/',
            'banque.regles' => 'nullable|array|max:100', 'banque.regles.*.mot_cle' => 'nullable|max:100', 'banque.regles.*.compte' => $cpt,
        ], ['regex' => 'Le compte doit être un numéro du plan comptable (chiffres uniquement).',
            'vente.comptes.*.compte.regex' => 'Un compte de vente proposé doit être un compte de produits (classe 7).']);
        $d['achat']['tva_deductible'] = $r->boolean('achat.tva_deductible');
        foreach (['achat', 'vente'] as $k) {
            $d[$k]['timbre'] = (float) ($d[$k]['timbre'] ?? 0);
            // Comptes proposés : lignes sans compte écartées, un compte une seule fois, taux général à défaut de taux
            $d[$k]['comptes'] = collect($d[$k]['comptes'] ?? [])->filter(fn ($x) => trim((string) ($x['compte'] ?? '')) !== '')
                ->map(fn ($x) => ['compte' => trim($x['compte']), 'tva_taux' => (float) ($x['tva_taux'] ?? $d[$k]['tva_taux'])])
                ->unique('compte')->values()->all();
            $d[$k]['compte'] = $d[$k]['comptes'][0]['compte'] ?? '';
        }
        $d['achat']['retenue_taux'] = (float) ($d['achat']['retenue_taux'] ?? 0);
        $d['banque']['regles'] = collect($d['banque']['regles'] ?? [])->filter(fn ($x) => trim($x['mot_cle'] ?? '') !== '' && trim($x['compte'] ?? '') !== '')
            ->map(fn ($x) => ['mot_cle' => trim($x['mot_cle']), 'compte' => trim($x['compte'])])->values()->all();
        Saisie::enregistrer($client->id, $d);
        $this->log('Paramétrage de la saisie comptable', $client->id);
        return back()->with('ok', 'Paramétrage enregistré pour '.$client->nom.'.');
    }

    // ---------- Modèles ----------
    private function modele(Request $r): array
    {
        $d = $r->validate(['type' => 'required|in:achat,vente', 'nom' => 'required|max:100', 'tiers' => 'nullable|max:255', 'matricule_fiscal' => 'nullable|max:50',
            'description' => 'nullable|max:255', 'compte' => 'nullable|regex:/^\d{1,8}$/', 'tva_taux' => 'required|numeric|min:0|max:100',
            'timbre' => 'nullable|numeric|min:0', 'retenue_taux' => 'nullable|numeric|min:0|max:100', 'tva_deductible' => 'nullable|boolean']);
        return ['timbre' => (float) ($d['timbre'] ?? 0), 'retenue_taux' => (float) ($d['retenue_taux'] ?? 0), 'tva_deductible' => $r->boolean('tva_deductible', true)] + $d;
    }

    public function modeleStore(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $m = ModeleSaisie::create($this->modele($r) + ['client_id' => $client->id]);
        return back()->with('ok', 'Modèle « '.$m->nom.' » créé.');
    }

    public function modeleUpdate(Request $r, ModeleSaisie $modele)
    {
        $modele->update($this->modele($r));
        return back()->with('ok', 'Modèle modifié.');
    }

    public function modeleDestroy(ModeleSaisie $modele)
    {
        $modele->delete();
        return back()->with('ok', 'Modèle supprimé.');
    }

    // ---------- Factures de vente ----------
    private function vente(Request $r): array
    {
        $d = $r->validate(['date' => 'required|date', 'tiers' => 'required|max:255', 'matricule_fiscal' => 'nullable|max:50', 'reference' => 'nullable|max:100',
            'description' => 'nullable|max:255', 'ht' => 'required|numeric|min:0.001', 'tva_taux' => 'nullable|numeric|min:0', 'tva' => 'nullable|numeric|min:0',
            'timbre' => 'nullable|numeric|min:0', 'compte' => 'nullable|regex:/^7\d{0,7}$/', 'fichier' => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,webp'],
            ['compte.regex' => 'Le compte de vente doit être un compte de produits (classe 7).']);
        unset($d['fichier']);
        $d['tva_taux'] = $d['tva_taux'] ?? 19;
        $d['tva'] = $r->filled('tva') ? $d['tva'] : r3($d['ht'] * $d['tva_taux'] / 100);
        $d['timbre'] = $d['timbre'] ?? 0;
        return $d;
    }

    public function venteStore(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $d = $this->vente($r);
        if ($r->hasFile('fichier')) $d['fichier'] = $r->file('fichier')->store('factures-ventes/'.CabinetCourant::exiger());
        FactureVente::create($d + ['client_id' => $client->id, 'statut' => 'prop']);
        if ($r->boolean('memoriser')) ModeleSaisie::memoriser($client->id, 'vente', $d, $d['tiers']);
        return back()->with('ok', 'Facture de vente enregistrée : en attente de validation par le chef comptable.');
    }

    public function venteUpdate(Request $r, FactureVente $fv)
    {
        if ($fv->statut === 'val') return back()->with('err', 'Facture déjà comptabilisée : annulez d’abord l’écriture.');
        $d = $this->vente($r);
        if ($r->hasFile('fichier')) $d['fichier'] = $r->file('fichier')->store('factures-ventes/'.CabinetCourant::exiger());
        $fv->update($d + ['statut' => 'prop', 'motif_rejet' => null]);
        if ($r->boolean('memoriser')) ModeleSaisie::memoriser($fv->client_id, 'vente', $d, $d['tiers']);
        return back()->with('ok', 'Facture de vente modifiée.');
    }

    public function venteDestroy(FactureVente $fv)
    {
        if ($fv->statut === 'val') return back()->with('err', 'Facture comptabilisée : annulez d’abord l’écriture.');
        if ($fv->fichier) Storage::delete($fv->fichier);
        $fv->delete();
        return back()->with('ok', 'Facture de vente supprimée.');
    }

    public function venteFichier(FactureVente $fv)
    {
        abort_unless($fv->fichier && Storage::exists($fv->fichier), 404);
        return Storage::response($fv->fichier);
    }

    /** Validation par le chef comptable → journal des ventes. */
    public function venteValider(FactureVente $fv)
    {
        if ($fv->statut === 'val') return back()->with('err', 'Facture déjà comptabilisée.');
        if (! $fv->compte) return back()->with('err', 'Choisissez le compte de vente avant de valider (bouton modifier).');
        DB::transaction(function () use ($fv) {
            Compta::passer($fv->client_id, $fv->date->format('Y-m-d'), 'VTE', 'Facture '.($fv->reference ?: '').' – '.$fv->tiers, $fv->lignesEcriture(), 'fv:'.$fv->id, auth()->id());
            $fv->update(['statut' => 'val', 'valide_par' => auth()->id()]);
        });
        $this->log('Validation de la facture de vente '.$fv->tiers.' '.$fv->reference, $fv->client_id);
        return back()->with('ok', 'Facture validée et comptabilisée au journal des ventes.');
    }

    public function venteRejeter(Request $r, FactureVente $fv)
    {
        if ($fv->statut === 'val') return back()->with('err', 'Facture déjà comptabilisée.');
        $fv->update(['statut' => 'rej', 'motif_rejet' => $r->input('motif') ?: 'Rejetée par le chef comptable']);
        return back()->with('ok', 'Facture rejetée.');
    }
}
