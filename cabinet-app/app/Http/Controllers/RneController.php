<?php

namespace App\Http\Controllers;

use App\Models\ChecklistRne;
use App\Models\Client;
use App\Models\DepotRne;
use App\Models\Document;
use App\Services\Exports\ListeAssocies;
use App\Services\Rne\Formalites;
use App\Support\CabinetCourant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Module RNE : dépôt des états financiers (pièces PDF tirées de la liasse et de la fiche, PV d'AGO et rapport du CAC importés,
 * dossier ZIP, suivi du dépôt) et check-lists des formalités du RNE (site officiel) suivies par client.
 */
class RneController extends Controller
{
    private const DESCRIPTION = 'Dépôt RNE';

    public function index(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $tab = $r->input('tab', 'depot');
        $exercice = (int) $r->input('exercice', now()->subYear()->year);
        $depot = DepotRne::firstOrNew(['client_id' => $client->id, 'exercice' => $exercice]);
        $data = ['client' => $client, 'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'tab' => $tab, 'exercice' => $exercice, 'depot' => $depot];

        if ($tab === 'checklist') {
            $genre = $r->input('genre', $client->estPhysique() ? 'Personne physique' : 'Personne Morale');
            $operation = $r->input('operation');
            $q = trim((string) $r->input('q'));
            $cherche = mb_strtolower(Str::ascii($q));   // recherche sans tenir compte des accents
            $data += [
                'genre' => $genre, 'operation' => $operation, 'q' => $q, 'source' => Formalites::donnees(),
                'formalites' => collect(Formalites::toutes())
                    ->when($genre, fn ($c) => $c->where('genre', $genre))
                    ->when($operation, fn ($c) => $c->filter(fn ($f) => Formalites::operation($f['code']) === $operation))
                    ->when($q !== '', fn ($c) => $c->filter(fn ($f) => str_contains(mb_strtolower(Str::ascii($f['code'].' '.$f['type'].' '.$f['procedure'].' '.$f['service'].' '.implode(' ', $f['documents']))), $cherche)))
                    ->values(),
                'suivis' => ChecklistRne::where('client_id', $client->id)->get()->keyBy('code'),
                'ouverte' => $r->input('code'),
            ];
        }
        return view('rne.index', $data);
    }

    // ---------- Onglet 1 : dépôt des états financiers ----------

    /** Génère les pièces PDF (états financiers par partie, liste des associés) et les classe dans la GED. */
    public function generer(Request $r, Client $client, int $exercice)
    {
        $depot = $this->depot($client, $exercice);
        $pieces = (array) $depot->pieces;
        $faites = [];
        foreach (['bilan', 'resultat', 'flux', 'notes'] as $p) {
            $pieces[$p] = $this->classer($client, $exercice, $p, LiasseController::pdfEtats($client, $exercice, $p), $pieces[$p] ?? null)->id;
            $faites[] = DepotRne::PIECES[$p][0];
        }
        if (! $client->estPhysique()) {
            if (ListeAssocies::lignes($client)) {
                [$tmp] = ListeAssocies::fichier($client, 'pdf');
                $pieces['associes'] = $this->classer($client, $exercice, 'associes', (string) file_get_contents($tmp), $pieces['associes'] ?? null)->id;
                @unlink($tmp);
                $faites[] = 'liste des associés';
            }
        }
        $depot->update(['pieces' => $pieces]);
        $this->log('RNE : pièces du dépôt des états financiers '.$exercice.' générées', $client->id);
        $manque = ! $client->estPhysique() && empty($pieces['associes']);
        return back()->with($manque ? 'err' : 'ok', 'Pièces générées et classées dans la GED : '.implode(', ', $faites).'.'
            .($manque ? ' Liste des associés non générée : complétez les associés sur la fiche client.' : ''));
    }

    /** Approbation des états financiers (AGO) et suivi du dépôt. */
    public function enregistrer(Request $r, Client $client, int $exercice)
    {
        $d = $r->validate(['approbation' => 'nullable|boolean', 'date_approbation' => 'nullable|date|required_if:approbation,1',
            'statut' => 'required|in:preparation,depose', 'date_depot' => 'nullable|date|required_if:statut,depose', 'reference' => 'nullable|max:60',
            'observations' => 'nullable|max:2000'], ['date_approbation.required_if' => 'Indiquez la date de l’AGO.', 'date_depot.required_if' => 'Indiquez la date du dépôt.']);
        $depot = $this->depot($client, $exercice);
        $depot->update(['approbation' => $r->boolean('approbation'), 'date_approbation' => $r->boolean('approbation') ? $d['date_approbation'] : null,
            'statut' => $d['statut'], 'date_depot' => $d['statut'] === 'depose' ? $d['date_depot'] : null, 'reference' => $d['reference'] ?? null,
            'observations' => $d['observations'] ?? null]);
        $this->log('RNE : dépôt des états financiers '.$exercice.' — '.($depot->statut === 'depose' ? 'déposé le '.$depot->date_depot->format('d/m/Y') : 'en préparation'), $client->id);
        return back()->with('ok', 'Dépôt enregistré.');
    }

    /** Import d'une pièce scannée : PV de l'AGO ou rapport du commissaire aux comptes. */
    public function importer(Request $r, Client $client, int $exercice, string $piece)
    {
        abort_unless(in_array($piece, ['pv_ago', 'rapport_cac'], true), 404);
        $r->validate(['fichier' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png'], [], ['fichier' => 'fichier']);
        $depot = $this->depot($client, $exercice);
        abort_unless($depot->approbation, 422, 'Indiquez d’abord que les états financiers sont approuvés.');
        $f = $r->file('fichier');
        $pieces = (array) $depot->pieces;
        $doc = $this->classer($client, $exercice, $piece, (string) file_get_contents($f->getRealPath()), $pieces[$piece] ?? null,
            strtolower($f->getClientOriginalExtension()) ?: 'pdf', $f->getMimeType());
        $pieces[$piece] = $doc->id;
        $depot->update(['pieces' => $pieces]);
        $this->log('RNE : '.DepotRne::PIECES[$piece][0].' '.$exercice.' importé', $client->id);
        return back()->with('ok', DepotRne::PIECES[$piece][0].' importé et classé dans la GED.');
    }

    public function retirer(Client $client, int $exercice, string $piece)
    {
        abort_unless(isset(DepotRne::PIECES[$piece]), 404);
        $depot = $this->depot($client, $exercice);
        $pieces = (array) $depot->pieces;
        if ($doc = $depot->document($piece)) { Storage::delete($doc->chemin); $doc->delete(); }
        unset($pieces[$piece]);
        $depot->update(['pieces' => $pieces]);
        return back()->with('ok', 'Pièce retirée du dépôt et de la GED.');
    }

    /** Dossier complet à déposer sur le portail du RNE (ZIP des pièces). */
    public function zip(Client $client, int $exercice)
    {
        $depot = $this->depot($client, $exercice);
        $tmp = tempnam(sys_get_temp_dir(), 'rne');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $n = 0;
        foreach (DepotRne::PIECES as $k => [$lib]) {
            $doc = $depot->document($k);
            if (! $doc || ! Storage::exists($doc->chemin)) continue;
            $zip->addFromString(sprintf('%d - %s.%s', ++$n, Str::ascii(str_replace(['’', '(', ')'], ['', '', ''], $lib)), pathinfo($doc->nom, PATHINFO_EXTENSION) ?: 'pdf'), Storage::get($doc->chemin));
        }
        $zip->close();
        if (! $n) { @unlink($tmp); return back()->with('err', 'Aucune pièce à télécharger : générez d’abord les pièces.'); }
        return response()->download($tmp, 'Depot RNE '.$exercice.' - '.Str::ascii($client->nom).'.zip')->deleteFileAfterSend();
    }

    // ---------- Onglet 2 : check-lists des formalités ----------

    public function cocher(Request $r, Client $client)
    {
        $d = $r->validate(['code' => 'required|max:30', 'coches' => 'nullable|array', 'coches.*' => 'integer|min:0', 'notes' => 'nullable|max:2000']);
        $f = Formalites::trouver($d['code']);
        abort_unless($f, 404);
        $coches = array_values(array_filter(array_map('intval', $d['coches'] ?? []), fn ($i) => $i < count($f['documents'])));
        ChecklistRne::updateOrCreate(['client_id' => $client->id, 'code' => $f['code']], ['coches' => $coches, 'notes' => $d['notes'] ?? null]);
        return redirect()->route('rne.index', ['client' => $client->id, 'tab' => 'checklist', 'code' => $f['code']] + $r->only('genre', 'operation', 'q'))
            ->with('ok', 'Check-list « '.$f['procedure'].' » enregistrée : '.count($coches).'/'.count($f['documents']).' document(s).');
    }

    // ---------- Outils ----------

    private function depot(Client $client, int $exercice): DepotRne
    {
        abort_unless($exercice >= 2000 && $exercice <= now()->year + 1, 404);
        return DepotRne::firstOrCreate(['client_id' => $client->id, 'exercice' => $exercice]);
    }

    /** Classe une pièce dans la GED (en remplaçant la version précédente de la même pièce). */
    private function classer(Client $client, int $exercice, string $piece, string $contenu, ?int $ancien, string $ext = 'pdf', ?string $mime = 'application/pdf'): Document
    {
        if ($ancien && ($a = Document::find($ancien))) { Storage::delete($a->chemin); $a->delete(); }
        $lib = DepotRne::PIECES[$piece][0];
        $chemin = 'ged/'.CabinetCourant::exiger().'/'.$client->id.'/'.Str::random(12).'-rne-'.$piece.'.'.$ext;
        Storage::put($chemin, $contenu);
        $categorie = match ($piece) { 'associes' => 'Liste des associés', 'pv_ago' => 'PV d’AGO', 'rapport_cac' => 'États financiers et déclarations', default => 'États financiers et déclarations' };
        return Document::create(['client_id' => $client->id, 'categorie' => $categorie, 'nom' => Str::ascii($lib).' '.$exercice.' - '.Str::ascii($client->nom).'.'.$ext,
            'chemin' => $chemin, 'taille' => strlen($contenu), 'mime' => $mime, 'date' => now(), 'description' => self::DESCRIPTION.' '.$exercice.' : '.$lib, 'user_id' => auth()->id()]);
    }
}
