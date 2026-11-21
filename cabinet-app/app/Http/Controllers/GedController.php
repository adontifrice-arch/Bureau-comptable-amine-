<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GedController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string) $r->input('q'));
        $docs = Document::with(['client', 'user'])
            ->when($q, fn ($x) => $x->where(fn ($w) => $w->where('nom', 'like', "%$q%")->orWhere('description', 'like', "%$q%")->orWhere('categorie', 'like', "%$q%")))
            ->when($r->filled('client'), fn ($x) => $r->input('client') === '0' ? $x->whereNull('client_id') : $x->where('client_id', $r->input('client')))
            ->when($r->input('categorie'), fn ($x, $c) => $x->where('categorie', $c))
            ->when($r->input('annee'), fn ($x, $y) => $x->whereYear('date', $y))
            ->latest()->paginate(30)->withQueryString();
        $clients = Client::orderBy('nom')->withCount('documents')->get(['id', 'nom']);
        $base = array_slice(config('cabinet.GC'), 0, 9);
        $completude = $clients->map(fn ($c) => ['c' => $c, 'n' => Document::where('client_id', $c->id)->whereIn('categorie', $base)->distinct('categorie')->count('categorie')]);
        return view('ged.index', ['docs' => $docs, 'clients' => $clients, 'completude' => $completude, 'q' => $q,
            'total' => Document::count(), 'taille' => Document::sum('taille')]);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['client_id' => 'nullable|'.existe_cab('clients'), 'categorie' => 'required|max:100', 'description' => 'nullable|max:1000', 'date' => 'nullable|date',
            'fichiers' => 'required|array', 'fichiers.*' => Document::REGLE_FICHIER]);
        // Partage avec le client : seulement pour un document de dossier client (pas un document interne au cabinet)
        $partager = $r->boolean('partager') && ! empty($d['client_id']);
        $docs = collect($r->file('fichiers'))->map(fn ($f) => Document::create(['client_id' => $d['client_id'] ?: null, 'categorie' => $d['categorie'], 'description' => $d['description'] ?? null, 'date' => $d['date'] ?? now(),
            'nom' => $f->getClientOriginalName(), 'chemin' => $f->store('ged/'.\App\Support\CabinetCourant::exiger().'/'.($d['client_id'] ?: 'cabinet')), 'taille' => $f->getSize(), 'mime' => $f->getMimeType(), 'user_id' => auth()->id(),
            'partage' => $partager, 'partage_at' => $partager ? now() : null]));
        $this->log($docs->count().' document(s) ajouté(s) en GED ('.$d['categorie'].')'.($partager ? ' et partagé(s) avec le client' : ''), $d['client_id'] ?: null);
        if ($partager) \App\Services\EspaceClientAvis::documentsPartages(Client::find($d['client_id']), $docs);
        return back()->with('ok', $docs->count().' document(s) classé(s)'.($partager ? ' et partagé(s) avec le client' : '').'.');
    }

    public function update(Request $r, Document $document)
    {
        $document->update($r->validate(['client_id' => 'nullable|'.existe_cab('clients'), 'categorie' => 'required|max:100', 'description' => 'nullable|max:1000', 'date' => 'nullable|date']));
        return back()->with('ok', 'Informations enregistrées.');
    }

    public function download(Document $document)
    {
        abort_unless(Storage::exists($document->chemin), 404);
        return Storage::download($document->chemin, $document->nom);
    }

    public function destroy(Document $document)
    {
        Storage::delete($document->chemin);
        $document->delete();
        return back()->with('ok', 'Document supprimé.');
    }
}
