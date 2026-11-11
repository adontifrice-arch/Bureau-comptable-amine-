<?php

namespace App\Http\Controllers;

use App\Models\ModeleActe;
use App\Services\Actes\Balises;
use App\Services\Actes\ModeleDocx;
use App\Services\Actes\ModeleTexte;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Modèles d'actes importés (.docx / .txt) avec balises {{…}} — spécification §3.2. */
class ModeleActeController extends Controller
{
    public function index()
    {
        return view('actes.modeles', [
            'modeles' => ModeleActe::withCount('actes')->with('user')->orderBy('type')->orderBy('nom')->get(),
            'types' => ActeController::types(),
        ]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'type' => 'required|in:'.ActeController::types()->keys()->implode(','),
            'nom' => 'required|max:255',
            'fichier' => 'required|file|extensions:docx,txt|max:20480',
        ], ['fichier.extensions' => 'Le modèle doit être un fichier Word (.docx) ou texte (.txt).']);

        $f = $r->file('fichier');
        $format = strtolower($f->getClientOriginalExtension());
        try {
            $texte = $format === 'docx' ? ModeleDocx::texte($f->getRealPath()) : ModeleTexte::lire($f->getRealPath());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['fichier' => $e->getMessage()]);
        }
        $balises = Balises::trouver($texte);

        $m = ModeleActe::create(['type' => $d['type'], 'nom' => $d['nom'], 'format' => $format, 'fichier' => $f->store('modeles-actes'),
            'fichier_nom' => $f->getClientOriginalName(), 'balises' => $balises, 'user_id' => auth()->id()]);
        $this->log('Import du modèle d’acte « '.$m->nom.' »');

        $inconnues = array_diff($balises, Balises::noms());
        $r = back()->with('ok', 'Modèle « '.$m->nom.' » importé : '.(count($balises) - count($inconnues)).' balise(s) reconnue(s).');
        if (! $balises) return $r->with('err', 'Aucune balise {{…}} trouvée : le document sera repris tel quel.');
        if ($inconnues) return $r->with('err', 'Balise(s) inconnue(s), laissée(s) telle(s) quelle(s) : {{'.implode('}}, {{', $inconnues).'}}.');
        return $r;
    }

    public function download(ModeleActe $modele)
    {
        abort_unless(Storage::exists($modele->fichier), 404);
        return Storage::download($modele->fichier, $modele->fichier_nom);
    }

    public function destroy(ModeleActe $modele)
    {
        Storage::delete($modele->fichier);
        $modele->delete();
        $this->log('Suppression du modèle d’acte « '.$modele->nom.' »');
        return back()->with('ok', 'Modèle supprimé. Les documents déjà rédigés sont conservés.');
    }
}
