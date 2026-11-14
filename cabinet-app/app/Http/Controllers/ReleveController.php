<?php

namespace App\Http\Controllers;

use App\Models\Releve;
use App\Models\ReleveLigne;
use App\Services\Compta;
use App\Services\Releves\ImportReleve;
use App\Services\Releves\LecteurReleve;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Relevés bancaires : import (Excel, CSV, Word, PDF), affectation des lignes, comptabilisation au journal BQ. */
class ReleveController extends Controller
{
    public function importer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $r->validate(['fichier' => 'required|file|max:20480|extensions:'.implode(',', LecteurReleve::EXTENSIONS)],
            ['fichier.extensions' => 'Formats acceptés : Excel (.xlsx, .xls, .ods), CSV, Word (.docx), PDF ou image.']);
        try {
            [$releve, $doublons, $controle] = ImportReleve::importer($client, $r->file('fichier'), auth()->id());
        } catch (\Throwable $e) {
            return back()->with('err', 'Import impossible : '.$e->getMessage());
        }
        $n = $releve->lignes()->count();
        $incertains = $releve->lignes()->where('sens_incertain', true)->count();
        $this->log('Import du relevé bancaire '.$releve->nom_fichier, $client->id);
        $msg = $n.' opération(s) importée(s) ('.Releve::METHODES[$releve->methode].')'
            .($doublons ? ', '.$doublons.' déjà importée(s) écartée(s)' : '').($incertains ? ' — '.$incertains.' dont le sens (débit / crédit) est à vérifier' : '')
            .($controle ? ' — '.$controle['message'] : '').'. Vérifiez les contreparties proposées puis comptabilisez.';
        // Un écart avec les totaux du relevé est signalé comme une alerte
        return redirect()->route('compta.index', ['tab' => 'bq', 'releve' => $releve->id])->with($controle && ! $controle['ok'] ? 'err' : 'ok', $msg);
    }

    public function destroy(Releve $releve)
    {
        if ($releve->lignes()->where('statut', 'cpt')->exists()) return back()->with('err', 'Des lignes de ce relevé sont comptabilisées : annulez d’abord leurs écritures.');
        if ($releve->fichier) Storage::delete($releve->fichier);
        $releve->delete();
        return redirect()->route('compta.index', ['tab' => 'bq'])->with('ok', 'Relevé supprimé.');
    }

    public function fichier(Releve $releve)
    {
        abort_unless($releve->fichier && Storage::exists($releve->fichier), 404);
        return Storage::download($releve->fichier, $releve->nom_fichier);
    }

    /** Actions sur les lignes : enregistrer les contreparties, comptabiliser (chef), ignorer, rétablir, confirmer ou inverser le sens. */
    public function lignes(Request $r)
    {
        $d = $r->validate(['action' => 'required|in:enregistrer,comptabiliser,comptabiliser_tout,ignorer,retablir,inverser,confirmer', 'comptes' => 'nullable|array', 'comptes.*' => 'nullable|regex:/^\d{1,8}$/',
            'sel' => 'nullable|array', 'sel.*' => 'integer', 'ligne' => 'nullable|integer', 'releve' => 'nullable|integer'],
            ['comptes.*.regex' => 'Une contrepartie doit être un numéro de compte (chiffres uniquement).']);

        // Les contreparties saisies sont toujours enregistrées (lignes non comptabilisées)
        foreach ((array) ($d['comptes'] ?? []) as $id => $compte) {
            ReleveLigne::whereKey($id)->where('statut', '!=', 'cpt')->update(['compte' => $compte ?: null]);
        }
        $sel = ReleveLigne::whereKey((array) ($d['sel'] ?? []))->get();

        if ($d['action'] === 'comptabiliser_tout') {
            // Toutes les lignes à traiter du relevé affiché (ou du dossier), au-delà de la page courante
            $client = $this->dossier($r);
            abort_unless($client, 404);
            $sel = ReleveLigne::with('releve')->where('client_id', $client->id)->where('statut', 'att')
                ->when($d['releve'] ?? null, fn ($q, $id) => $q->where('releve_id', $id))->orderBy('date')->orderBy('id')->get();
            $d['action'] = 'comptabiliser';
        }

        switch ($d['action']) {
            case 'inverser':
                $l = ReleveLigne::where('statut', '!=', 'cpt')->findOrFail($d['ligne'] ?? 0);
                $l->update(['debit' => $l->credit, 'credit' => $l->debit, 'sens_incertain' => false]);
                return back()->with('ok', 'Sens de l’opération inversé.');
            case 'confirmer':
                $n = ReleveLigne::whereKey($sel->pluck('id'))->where('statut', 'att')->update(['sens_incertain' => false]);
                return back()->with('ok', 'Sens confirmé pour '.$n.' ligne(s).');
            case 'ignorer':
                $n = ReleveLigne::whereKey($sel->pluck('id'))->where('statut', 'att')->update(['statut' => 'ign']);
                return back()->with('ok', $n.' ligne(s) ignorée(s).');
            case 'retablir':
                $n = ReleveLigne::whereKey($sel->pluck('id'))->where('statut', 'ign')->update(['statut' => 'att']);
                return back()->with('ok', $n.' ligne(s) rétablie(s).');
            case 'comptabiliser':
                if (! auth()->user()->estChef()) return back()->with('err', 'Action réservée au chef comptable.');
                $aPasser = $sel->where('statut', 'att');
                $sansCompte = $aPasser->filter(fn ($l) => ! $l->compte)->count();
                $incertains = $aPasser->filter(fn ($l) => $l->compte && $l->sens_incertain)->count();
                $n = 0;
                DB::transaction(function () use ($aPasser, &$n) {
                    foreach ($aPasser->filter(fn ($l) => $l->compte && ! $l->sens_incertain) as $l) {
                        $e = Compta::passer($l->client_id, $l->date->format('Y-m-d'), 'BQ', $l->libelle, $l->lignesEcriture($l->releve->compte_banque ?: '532'), 'rb:'.$l->id, auth()->id());
                        $l->update(['statut' => 'cpt', 'ecriture_id' => $e->id]);
                        $n++;
                    }
                });
                if ($n) $this->log($n.' ligne(s) de relevé bancaire comptabilisée(s)', $sel->first()?->client_id);
                if (! $n && $aPasser->isEmpty()) return back()->with('err', 'Aucune ligne à comptabiliser : sélectionnez des lignes « À traiter ».');
                $msg = $n.' opération(s) comptabilisée(s) au journal de banque.';
                if ($sansCompte) $msg .= ' '.$sansCompte.' ligne(s) sans contrepartie non passée(s).';
                if ($incertains) $msg .= ' '.$incertains.' ligne(s) au sens à vérifier non passée(s) : confirmez ou inversez le sens (⇄).';
                return back()->with($n ? 'ok' : 'err', $msg);
            default:
                return back()->with('ok', 'Contreparties enregistrées.');
        }
    }
}
