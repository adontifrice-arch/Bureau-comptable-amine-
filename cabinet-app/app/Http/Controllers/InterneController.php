<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\NoteInterne;
use App\Models\RendezVous;
use Illuminate\Http\Request;

class InterneController extends Controller
{
    public function index(Request $r)
    {
        $tab = $r->input('tab', 'rdv');
        return view('interne.index', [
            'tab' => $tab, 'clients' => Client::orderBy('nom')->get(['id', 'nom']),
            'rdv' => RendezVous::with('client')->when($r->input('client'), fn ($q, $c) => $q->where('client_id', $c))->orderByDesc('date')->orderByDesc('heure')->get(),
            'notes' => NoteInterne::where('type', 'note')->orderBy('fait')->orderByRaw('rappel_le is null')->orderBy('rappel_le')->get(),
            'autres' => NoteInterne::where('type', 'autre')->orderByDesc('date')->get(),
        ]);
    }

    public function rdvStore(Request $r) { RendezVous::create($this->rdv($r)); return back()->with('ok', 'Rendez-vous enregistré.'); }
    public function rdvUpdate(Request $r, RendezVous $rdv) { $rdv->update($this->rdv($r)); return back()->with('ok', 'Rendez-vous modifié.'); }
    public function rdvDestroy(RendezVous $rdv) { $rdv->delete(); return back()->with('ok', 'Rendez-vous supprimé.'); }

    public function noteStore(Request $r) { NoteInterne::create($this->note($r) + ['user_id' => auth()->id()]); return back()->with('ok', 'Élément enregistré.'); }
    public function noteUpdate(Request $r, NoteInterne $note) { $note->update($this->note($r)); return back()->with('ok', 'Élément modifié.'); }
    public function noteToggle(NoteInterne $note) { $note->update(['fait' => ! $note->fait]); return back(); }
    public function noteDestroy(NoteInterne $note) { $note->delete(); return back()->with('ok', 'Élément supprimé.'); }

    private function rdv(Request $r): array
    {
        $d = $r->validate(['client_id' => 'nullable|exists:clients,id', 'objet' => 'required|max:255', 'date' => 'required|date', 'heure' => 'nullable|max:5',
            'lieu' => 'nullable|max:255', 'participants' => 'nullable|max:255', 'statut' => 'nullable|max:50', 'compte_rendu' => 'nullable|max:5000', 'actions' => 'nullable|max:5000']);
        $d['statut'] = $d['statut'] ?? 'Prévu';
        return $d;
    }

    private function note(Request $r): array
    {
        $d = $r->validate(['type' => 'required|in:note,autre', 'titre' => 'required|max:255', 'categorie' => 'nullable|max:100', 'contenu' => 'nullable|max:10000',
            'date' => 'nullable|date', 'rappel_le' => 'nullable|date']);
        $d['date'] = $d['date'] ?? now()->format('Y-m-d');
        return $d;
    }
}
