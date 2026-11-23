<?php

namespace App\Http\Controllers;

use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\ModeleFacture;
use App\Services\Compta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Comptabilité : paramétrage des factures d'achat et de vente par modèle (« paramètres facture achat / vente »)
 * et saisie d'une facture selon un modèle (« Facture achat / Facture vente ») : le modèle donne les lignes,
 * on y saisit l'assiette et la TVA de chacune, le timbre, l'avance et le FODEC ; le TTC est calculé.
 * La facture saisie attend la validation, qui la comptabilise au journal du modèle.
 */
class FactureModeleController extends Controller
{
    private const CPT = 'nullable|regex:/^\d{1,20}$/';

    private static function type(string $type): string
    {
        abort_unless(isset(ModeleFacture::TYPES[$type]), 404);
        return $type;
    }

    // ---------- Paramétrage ----------
    public function parametrage(Request $r, string $type)
    {
        $type = self::type($type);
        $client = $this->dossier($r);
        if (! $client) return redirect()->route('compta.index');
        $modeles = ModeleFacture::where('client_id', $client->id)->where('type', $type)->orderBy('numero')->get();
        $m = $r->filled('modele') ? $modeles->firstWhere('id', (int) $r->input('modele')) : ($r->boolean('nouveau') ? null : $modeles->first());
        return view('compta.modele-facture', ['client' => $client, 'type' => $type, 'modeles' => $modeles, 'm' => $m ?? ModeleFacture::nouveau($client->id, $type),
            'journaux' => Ecriture::journaux($client->id), 'plan' => Compta::plan()]);
    }

    private function donneesModele(Request $r, string $type): array
    {
        $d = $r->validate(['libelle' => 'required|max:100', 'journal' => 'required|max:10',
            'compte_ttc' => self::CPT, 'compte_fodec' => self::CPT, 'compte_avance' => self::CPT, 'compte_timbre' => self::CPT,
            'montant_timbre' => 'nullable|numeric|min:0',
            'lignes' => 'nullable|array|max:100', 'lignes.*.objet' => 'nullable|max:100', 'lignes.*.compte' => self::CPT, 'lignes.*.tva_taux' => 'nullable|numeric|min:0|max:100',
            'lignes.*.compte_tva' => self::CPT, 'lignes.*.compte_majore' => self::CPT, 'lignes.*.taux_majore' => 'nullable|numeric|min:0|max:100',
            'lignes.*.compte_tva_majore' => self::CPT, 'lignes.*.debit' => 'nullable|boolean'],
            ['regex' => 'Un compte ne comporte que des chiffres.', 'libelle.required' => 'Saisissez le libellé du modèle.']);
        foreach (['ttc', 'fodec', 'avance', 'timbre'] as $k) $d[$k.'_debit'] = $r->boolean($k.'_debit');
        $d['montant_timbre'] = (float) ($d['montant_timbre'] ?? 0);
        $d['journal'] = strtoupper(trim($d['journal']));
        $d['lignes'] = collect($d['lignes'] ?? [])->map(fn ($l) => ModeleFacture::ligne(['debit' => (bool) ($l['debit'] ?? false)] + $l, $type === 'achat'))
            ->filter(fn ($l) => $l['objet'] !== '' || $l['compte'] !== '')->values()->all();
        if (! $d['lignes']) throw ValidationException::withMessages(['lignes' => 'Ajoutez au moins une ligne (objet et compte assiette).']);
        foreach ($d['lignes'] as $l) if ($l['objet'] === '' || $l['compte'] === '') throw ValidationException::withMessages(['lignes' => 'Chaque ligne a un objet et un compte assiette.']);
        return $d;
    }

    public function parametrageStore(Request $r, string $type)
    {
        $type = self::type($type);
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $m = ModeleFacture::create($this->donneesModele($r, $type) + ['client_id' => $client->id, 'type' => $type, 'numero' => ModeleFacture::prochainNumero($client->id, $type)]);
        $this->log('Paramétrage '.mb_strtolower(ModeleFacture::TYPES[$type]).' : modèle '.$m->numero.' '.$m->libelle, $client->id);
        return redirect()->route('compta.modeles-factures', ['type' => $type, 'modele' => $m->id])->with('ok', 'Modèle '.$m->numero.' « '.$m->libelle.' » enregistré.');
    }

    public function parametrageUpdate(Request $r, ModeleFacture $modele)
    {
        $modele->update($this->donneesModele($r, $modele->type));
        $this->log('Paramétrage '.mb_strtolower(ModeleFacture::TYPES[$modele->type]).' : modèle '.$modele->numero.' modifié', $modele->client_id);
        return redirect()->route('compta.modeles-factures', ['type' => $modele->type, 'modele' => $modele->id])->with('ok', 'Modèle '.$modele->numero.' enregistré.');
    }

    public function parametrageDestroy(ModeleFacture $modele)
    {
        $modele->delete();
        return redirect()->route('compta.modeles-factures', ['type' => $modele->type])->with('ok', 'Modèle '.$modele->numero.' supprimé (les factures déjà saisies gardent leur paramétrage).');
    }

    // ---------- Saisie d'une facture selon un modèle ----------
    private static function classe(string $type): string
    {
        return $type === 'achat' ? FactureRecue::class : FactureVente::class;
    }

    public function saisie(Request $r, string $type)
    {
        $type = self::type($type);
        $client = $this->dossier($r);
        if (! $client) return redirect()->route('compta.index');
        $modeles = ModeleFacture::where('client_id', $client->id)->where('type', $type)->orderBy('numero')->get();
        $f = $r->filled('facture') ? (self::classe($type))::where('client_id', $client->id)->findOrFail((int) $r->input('facture')) : null;
        if ($f && ! $f->selonModele()) return redirect()->route('compta.index', ['tab' => $type === 'achat' ? 'ach' : 'vte'])->with('err', 'Cette facture a été saisie sans modèle : modifiez-la depuis la liste.');
        $m = $f?->modeleFacture ?? ($r->filled('modele') ? $modeles->firstWhere('id', (int) $r->input('modele')) : null) ?? $modeles->first();
        return view('compta.facture-modele', ['client' => $client, 'type' => $type, 'modeles' => $modeles, 'm' => $m, 'f' => $f,
            'numeros' => $modeles->mapWithKeys(fn ($x) => [$x->id => Ecriture::prochainNumero($client->id, $x->journal)])->all(), 'plan' => Compta::plan()]);
    }

    /** Montants saisis → valeurs de la facture (lignes avec la copie des comptes du modèle, totaux, TTC). */
    private function donneesFacture(Request $r, string $type, ?ModeleFacture $mo): array
    {
        $d = $r->validate(['modele_id' => 'required|integer', 'compte_tiers' => self::CPT, 'tiers' => 'nullable|max:255', 'reference' => 'nullable|max:100',
            'date' => 'required|date', 'lignes' => 'nullable|array|max:100', 'lignes.*.ht' => 'nullable|numeric|min:0', 'lignes.*.tva' => 'nullable|numeric|min:0',
            'timbre' => 'nullable|numeric|min:0', 'fodec' => 'nullable|numeric|min:0', 'avance' => 'nullable|numeric|min:0'],
            ['compte_tiers.regex' => 'Le compte du tiers ne comporte que des chiffres.', 'date.required' => 'Saisissez la date de la facture.']);
        $mo ??= ModeleFacture::where('client_id', (int) session('dossier'))->where('type', $type)->findOrFail((int) $d['modele_id']);
        $lignes = FactureRecue::lignesDepuisModele($mo, (array) ($d['lignes'] ?? []));
        FactureRecue::exigerMontants($lignes);
        $p = $mo->parametres();
        $m = ['timbre' => round((float) ($d['timbre'] ?? 0), 3), 'fodec' => round((float) ($d['fodec'] ?? 0), 3), 'avance' => round((float) ($d['avance'] ?? 0), 3)];
        $tiersCpt = trim((string) ($d['compte_tiers'] ?? ''));
        if ($err = FactureRecue::comptesManquants($p, $lignes, $m, $tiersCpt)) throw ValidationException::withMessages(['compte_tiers' => $err]);
        $calc = FactureRecue::calculerSelonModele($p, $lignes, $m, $tiersCpt);
        if ($calc['ttc'] <= 0) throw ValidationException::withMessages(['lignes' => 'Le total TTC doit être positif : vérifiez les montants et le sens des comptes du modèle.']);
        $nom = trim((string) ($d['tiers'] ?? '')) ?: ($tiersCpt !== '' ? (Compta::libelle($tiersCpt) ?: $tiersCpt) : $mo->libelle);
        return [($type === 'achat' ? 'fournisseur' : 'tiers') => $nom, 'compte_tiers' => $tiersCpt ?: null, 'reference' => $d['reference'] ?? null, 'date' => $d['date'],
            'description' => $mo->libelle, 'lignes' => $lignes, 'ht' => round(array_sum(array_column($lignes, 'ht')), 3), 'tva' => round(array_sum(array_column($lignes, 'tva')), 3),
            'compte' => $lignes[0]['compte'] ?: null, 'tva_taux' => $lignes[0]['tva_taux'], 'ttc' => $calc['ttc'], 'modele_facture_id' => $mo->id, 'parametres' => $p,
            'tva_suspension' => false, 'tva_suspendue' => 0] + $m
            + ($type === 'achat' ? ['retenue_taux' => 0, 'tva_deductible' => collect($lignes)->contains(fn ($l) => $l['compte_tva'] !== '')] : []);
    }

    public function saisieStore(Request $r, string $type)
    {
        $type = self::type($type);
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $d = $this->donneesFacture($r, $type, null);
        $f = (self::classe($type))::create($d + ['client_id' => $client->id, 'statut' => 'prop']);
        $this->log('Saisie de la '.mb_strtolower(ModeleFacture::TYPES[$type]).' '.($f->reference ?: '').' – '.($f->fournisseur ?? $f->tiers).' (modèle)', $client->id);
        // Enregistrer (F4) : nouvelle facture du même modèle
        return redirect()->route('compta.factures-modele', ['type' => $type, 'modele' => $d['modele_facture_id']])
            ->with('ok', 'Facture '.($f->reference ?: '').' enregistrée ('.number_format($f->ttc(), 3, ',', ' ').' TTC) : en attente de validation.');
    }

    public function saisieUpdate(Request $r, string $type, int $facture)
    {
        $type = self::type($type);
        $f = (self::classe($type))::findOrFail($facture);
        if ($f->statut === 'val') return back()->with('err', 'Facture déjà comptabilisée : annulez d’abord l’écriture.');
        // Le modèle d'origine supprimé : la copie de son paramétrage reste celle de la facture
        $mo = $f->modeleFacture;
        if (! $mo || (int) $r->input('modele_id') !== $mo->id) $mo = null;
        $d = $mo || ModeleFacture::find((int) $r->input('modele_id')) ? $this->donneesFacture($r, $type, $mo) : null;
        if (! $d) return back()->with('err', 'Le modèle de cette facture a été supprimé : supprimez la facture et saisissez-la de nouveau.');
        $f->update($d + ['statut' => 'prop', 'motif_rejet' => null]);
        return redirect()->route('compta.index', ['tab' => $type === 'achat' ? 'ach' : 'vte'])->with('ok', 'Facture modifiée : en attente de validation.');
    }
}
