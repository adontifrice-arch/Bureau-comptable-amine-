<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\NoteFrais;
use App\Models\NoteFraisDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FraisController extends Controller
{
    public function index(Request $r)
    {
        $f = $r->input('f', 'all');
        $items = NoteFrais::with(['client', 'document'])
            ->when($f === '0', fn ($q) => $q->where('rembourse', false))->when($f === '1', fn ($q) => $q->where('rembourse', true))
            ->orderByDesc('date')->get();
        $all = NoteFrais::all();
        return view('frais.index', [
            'items' => $items, 'f' => $f, 'total' => $all->sum('montant'), 'aRembourser' => $all->where('rembourse', false)->sum('montant'),
            'docs' => NoteFraisDocument::with('client')->latest('date')->get(), 'clients' => Client::orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function store(Request $r)
    {
        $d = $this->valider($r);
        if ($r->hasFile('justificatif')) $d['justificatif'] = $r->file('justificatif')->store('justificatifs/'.\App\Support\CabinetCourant::exiger());
        NoteFrais::create($d + ['user_id' => auth()->id()]);
        return back()->with('ok', 'Note de frais ajoutée.');
    }

    public function update(Request $r, NoteFrais $note)
    {
        $d = $this->valider($r);
        if ($r->hasFile('justificatif')) {
            if ($note->justificatif) Storage::delete($note->justificatif);
            $d['justificatif'] = $r->file('justificatif')->store('justificatifs/'.\App\Support\CabinetCourant::exiger());
        }
        $note->update($d);
        return back()->with('ok', 'Note de frais modifiée.');
    }

    public function toggle(NoteFrais $note)
    {
        $note->update(['rembourse' => ! $note->rembourse]);
        return back();
    }

    public function destroy(NoteFrais $note)
    {
        if ($note->justificatif) Storage::delete($note->justificatif);
        $note->delete();
        return back()->with('ok', 'Note de frais supprimée.');
    }

    public function justificatif(NoteFrais $note)
    {
        abort_unless($note->justificatif && Storage::exists($note->justificatif), 404);
        return Storage::response($note->justificatif);
    }

    /** Note de frais adressée à un client, à partir des frais sélectionnés (ou saisie libre). */
    public function documentStore(Request $r)
    {
        $d = $r->validate(['client_id' => 'required|'.existe_cab('clients'), 'date' => 'required|date', 'observations' => 'nullable|max:2000',
            'ids' => 'nullable|array', 'lignes' => 'nullable|array']);
        $doc = DB::transaction(function () use ($d) {
            $notes = NoteFrais::whereIn('id', $d['ids'] ?? [])->whereNull('document_id')->get();
            $lignes = $notes->map(fn ($n) => ['date' => $n->date->format('Y-m-d'), 'designation' => $n->description, 'montant' => (float) $n->montant])->all();
            foreach ((array) ($d['lignes'] ?? []) as $l) if (! empty($l['designation']) && (float) ($l['montant'] ?? 0)) $lignes[] = ['date' => $l['date'] ?? $d['date'], 'designation' => $l['designation'], 'montant' => (float) $l['montant']];
            if (! $lignes) abort(back()->with('err', 'Ajoutez au moins une ligne.'));
            $y = now()->year;
            $max = NoteFraisDocument::where('numero', 'like', "NF-$y-%")->pluck('numero')->map(fn ($n) => (int) explode('-', $n)[2])->max() ?? 0;
            $doc = NoteFraisDocument::create(['numero' => sprintf('NF-%d-%03d', $y, $max + 1), 'client_id' => $d['client_id'], 'date' => $d['date'], 'observations' => $d['observations'] ?? null, 'lignes' => $lignes]);
            NoteFrais::whereIn('id', $notes->pluck('id'))->update(['document_id' => $doc->id]);
            return $doc;
        });
        return redirect()->route('frais.documents.show', $doc)->with('ok', 'Note de frais '.$doc->numero.' créée.');
    }

    public function documentShow(NoteFraisDocument $doc)
    {
        return view('frais.document', ['doc' => $doc->load('client')]);
    }

    public function documentDestroy(NoteFraisDocument $doc)
    {
        $doc->delete();
        return redirect()->route('frais.index')->with('ok', 'Note de frais client supprimée (les frais sont de nouveau disponibles).');
    }

    private function valider(Request $r): array
    {
        $d = $r->validate(['date' => 'required|date', 'description' => 'required|max:255', 'categorie' => 'nullable|max:100',
            'client_id' => 'nullable|'.existe_cab('clients'), 'montant' => 'required|numeric|min:0.001', 'justificatif' => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,webp']);
        unset($d['justificatif']);
        return $d;
    }
}
