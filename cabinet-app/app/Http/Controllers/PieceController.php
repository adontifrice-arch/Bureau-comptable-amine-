<?php

namespace App\Http\Controllers;

use App\Models\Ecriture;
use App\Services\Compta;
use App\Services\Ecritures;
use App\Support\Formule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comptabilité → Journal & écritures → Pièce comptable : ajout et modification d'une pièce (écriture) ligne par ligne,
 * avec libellé, compte, débit, crédit et référence par ligne, numéro de pièce par journal, navigation entre les pièces
 * du journal et calcul direct dans les montants (« 450+632 », « 100/5 »…).
 */
class PieceController extends Controller
{
    public function show(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return redirect()->route('compta.index');
        $e = $r->filled('ecriture') ? Ecriture::with('lignes')->where('client_id', $client->id)->findOrFail((int) $r->input('ecriture')) : null;
        $journaux = Ecriture::journauxSaisie($client->id);
        // Une pièce d'un journal automatique (paie, amortissements) s'ouvre dans son journal.
        if ($e && ! isset($journaux[$e->journal])) $journaux[$e->journal] = Ecriture::journaux($client->id)[$e->journal] ?? $e->journal;
        $journal = $e?->journal ?? (isset($journaux[$r->input('journal')]) ? (string) $r->input('journal') : (string) array_key_first($journaux));
        $numeros = collect($journaux)->map(fn ($_, $k) => Ecriture::prochainNumero($client->id, (string) $k))->all();
        return view('compta.piece', [
            'client' => $client, 'e' => $e, 'journaux' => $journaux, 'journal' => $journal, 'numeros' => $numeros,
            'plan' => Compta::plan(), 'comptesJ' => (object) Ecriture::comptesJournaux($client->id),
            'nav' => $this->navigation($client->id, $journal, $e), 'modeles' => $this->modeles($client->id),
            'peutCreer' => $r->user()->peutValider(), 'peutModifier' => Ecritures::autorise($r->user()),
        ]);
    }

    public function store(Request $r)
    {
        $client = $this->dossier($r);
        [$d, $lignes] = $this->donnees($r, array_keys(Ecriture::journauxSaisie($client->id)));
        $e = Compta::passer($client->id, $d['date'], $d['journal'], $d['libelle'], $lignes, null, $r->user()->id, ['reference' => $d['reference'] ?? null]);
        $this->log('Saisie de la pièce '.$e->journal.' n° '.$e->numero.' — '.$e->libelle, $client->id);
        return redirect()->route('compta.piece', ['journal' => $e->journal, 'enregistree' => $e->id])
            ->with('ok', 'Pièce '.$e->journal.' n° '.$e->numero.' enregistrée.');
    }

    public function update(Request $r, Ecriture $ecriture)
    {
        if (! Ecritures::autorise($r->user())) return back()->with('err', 'Modifier une écriture est réservé au chef comptable et aux collaborateurs qu’il y autorise (Paramètres → Utilisateurs).');
        if ($ecriture->trashed()) return back()->with('err', 'Restaurez la pièce avant de la modifier.');
        [$d, $lignes] = $this->donnees($r, array_keys(Ecriture::journaux($ecriture->client_id)));
        try {
            Ecritures::modifier($ecriture, ['lignes' => $lignes, 'reference' => $d['reference'] ?? null] + $d);
        } catch (\RuntimeException $x) {
            return back()->withInput()->with('err', $x->getMessage());
        }
        $this->log('Modification de la pièce '.$ecriture->journal.' n° '.$ecriture->numero, $ecriture->client_id);
        // Enregistrer (F4) passe à la pièce suivante du journal, ou à une nouvelle pièce après la dernière.
        $ecriture->refresh();
        $suivante = $this->navigation($ecriture->client_id, $ecriture->journal, $ecriture)['suivante'];
        return redirect()->route('compta.piece', $suivante ? ['ecriture' => $suivante] : ['journal' => $ecriture->journal])
            ->with('ok', 'Pièce '.$ecriture->journal.' n° '.$ecriture->numero.' modifiée.');
    }

    /**
     * Pièce saisie : en-tête validé et lignes retenues (compte et montant renseignés). Les montants peuvent être des calculs
     * (évalués ici aussi, au cas où le navigateur ne l'aurait pas fait). Le libellé de la pièce est celui de sa première ligne.
     */
    private function donnees(Request $r, array $journaux): array
    {
        $d = $r->validate([
            'date' => 'required|date', 'journal' => ['required', Rule::in($journaux)], 'reference' => 'nullable|max:60',
            'lignes' => 'required|array', 'lignes.*.libelle' => 'nullable|max:255', 'lignes.*.compte' => 'nullable|regex:/^\d{1,10}$/',
            'lignes.*.debit' => 'nullable|max:60', 'lignes.*.credit' => 'nullable|max:60', 'lignes.*.reference' => 'nullable|max:60',
        ], ['lignes.*.compte.regex' => 'Un compte ne comporte que des chiffres.']);
        $erreurs = [];
        $lignes = [];
        foreach (array_values($d['lignes']) as $i => $l) {
            $m = [];
            foreach (['debit', 'credit'] as $k) {
                $brut = trim((string) ($l[$k] ?? ''));
                $m[$k] = $brut === '' ? 0.0 : Formule::evaluer($brut);
                if ($m[$k] === null) $erreurs['lignes.'.$i.'.'.$k] = 'Ligne '.($i + 1).' : montant ou calcul invalide (« '.$brut.' »).';
                elseif ($m[$k] < 0) $erreurs['lignes.'.$i.'.'.$k] = 'Ligne '.($i + 1).' : montant négatif.';
            }
            if (trim((string) ($l['compte'] ?? '')) === '' || (! ($m['debit'] ?? 0) && ! ($m['credit'] ?? 0))) continue;
            $lignes[] = ['compte' => trim($l['compte']), 'debit' => r3($m['debit']), 'credit' => r3($m['credit']),
                'libelle' => trim((string) ($l['libelle'] ?? '')) ?: null, 'reference' => trim((string) ($l['reference'] ?? '')) ?: null];
        }
        if ($erreurs) throw \Illuminate\Validation\ValidationException::withMessages($erreurs);
        if (count($lignes) < 2) throw \Illuminate\Validation\ValidationException::withMessages(['lignes' => 'Saisissez au moins deux lignes (compte et montant).']);
        $D = array_sum(array_column($lignes, 'debit')); $C = array_sum(array_column($lignes, 'credit'));
        if (abs($D - $C) > 0.0005) throw \Illuminate\Validation\ValidationException::withMessages(['lignes' => 'Pièce déséquilibrée : débit '.tnd($D).' ≠ crédit '.tnd($C).'.']);
        $d['libelle'] = mb_substr(collect($lignes)->pluck('libelle')->filter()->first() ?? 'Pièce '.$d['journal'], 0, 255);
        return [$d, $lignes];
    }

    /** Pièces du journal pour les boutons « première, précédente, suivante, dernière » (par numéro de pièce). */
    private function navigation(int $client, string $journal, ?Ecriture $e): array
    {
        $q = fn () => Ecriture::where('client_id', $client)->where('journal', $journal);
        $id = fn ($b) => $b->value('id');
        if (! $e) {
            $der = $id($q()->orderByDesc('numero')->orderByDesc('id'));
            return ['premiere' => $id($q()->orderBy('numero')->orderBy('id')), 'precedente' => $der, 'suivante' => null, 'derniere' => $der];
        }
        $prec = $id($q()->where(fn ($w) => $w->where('numero', '<', $e->numero)->orWhere(fn ($x) => $x->where('numero', $e->numero)->where('id', '<', $e->id)))->orderByDesc('numero')->orderByDesc('id'));
        $suiv = $id($q()->where(fn ($w) => $w->where('numero', '>', $e->numero)->orWhere(fn ($x) => $x->where('numero', $e->numero)->where('id', '>', $e->id)))->orderBy('numero')->orderBy('id'));
        return ['premiere' => $prec ? $id($q()->orderBy('numero')->orderBy('id')) : null, 'precedente' => $prec, 'suivante' => $suiv,
            'derniere' => $suiv ? $id($q()->orderByDesc('numero')->orderByDesc('id')) : null];
    }

    /**
     * « Utiliser un modèle » : les pièces récentes de chaque journal, une par libellé, reprises comme modèle
     * (libellés et comptes des lignes, montants à saisir).
     */
    private function modeles(int $client): array
    {
        return Ecriture::with('lignes')->where('client_id', $client)->whereNull('source')->latest('id')->limit(200)->get()
            ->unique(fn ($e) => $e->journal.'|'.mb_strtolower($e->libelle))
            ->groupBy('journal')->map(fn ($g) => $g->take(25)->map(fn ($e) => ['id' => $e->id, 'libelle' => $e->libelle,
                'lignes' => $e->lignes->map(fn ($l) => ['libelle' => $l->libelle ?? $e->libelle, 'compte' => $l->compte, 'sens' => $l->debit > 0 ? 'D' : 'C'])->values()])->values())
            ->all();
    }
}
