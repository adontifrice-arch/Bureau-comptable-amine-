<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PaieProfil;
use App\Models\PaieRubrique;
use App\Models\Salarie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Paie — menu Profil : fiche profil (nom, CNSS patronale, code exploitant, options de déclaration) et rubriques du profil.
 * Les rubriques cochées sont celles que les employés du profil peuvent recevoir ; l'information est portée par chaque rubrique
 * (liste de profils, vide = tous les employés).
 */
class PaieProfilController extends Controller
{
    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        $profils = PaieProfil::duDossier($client);
        $profil = $r->filled('p') ? ($profils->firstWhere('id', (int) $r->input('p')) ?? abort(404)) : new PaieProfil(['decl_cnss' => true, 'decl_employeur' => true, 'soumis_tfp_fop' => true]);
        return view('paie.profils', PaieController::contexte($r, $client, 'pro', $r->input('mois', now()->format('Y-m'))) + [
            'profils' => $profils, 'profil' => $profil, 'rubriques' => PaieRubrique::duDossier($client),
            'effectifs' => Salarie::where('client_id', $client->id)->whereNotNull('profil')->get()->countBy(fn ($e) => mb_strtolower(trim($e->profil))),
        ]);
    }

    public function store(Request $r)
    {
        $client = $this->dossier($r);
        $d = $this->valider($r, $client);
        $p = DB::transaction(function () use ($client, $d, $r) {
            $p = PaieProfil::create(['client_id' => $client->id] + $d);
            $this->rubriques($client, $p->nom, null, (array) $r->input('rubriques', []));
            return $p;
        });
        $this->log('Paie : création du profil '.$p->nom, $client->id);
        return redirect()->route('paie.profils', ['p' => $p->id, 'mois' => $r->input('mois')])->with('ok', 'Profil « '.$p->nom.' » enregistré.');
    }

    /** Un changement de nom suit sur les fiches employés et dans les rubriques. */
    public function update(Request $r, PaieProfil $profil)
    {
        $client = $this->dossier($r);
        abort_unless($profil->client_id === $client->id, 404);
        $d = $this->valider($r, $client, $profil);
        $ancien = $profil->nom;
        DB::transaction(function () use ($client, $profil, $d, $ancien, $r) {
            $profil->update($d);
            if ($ancien !== $profil->nom) {
                Salarie::where('client_id', $client->id)->where('profil', $ancien)->update(['profil' => $profil->nom]);
            }
            $this->rubriques($client, $profil->nom, $ancien, (array) $r->input('rubriques', []));
        });
        $this->log('Paie : modification du profil '.$profil->nom, $client->id);
        return redirect()->route('paie.profils', ['p' => $profil->id, 'mois' => $r->input('mois')])->with('ok', 'Profil « '.$profil->nom.' » enregistré.');
    }

    /** Suppression refusée tant que des employés ont ce profil. */
    public function destroy(Request $r, PaieProfil $profil)
    {
        $client = $this->dossier($r);
        abort_unless($profil->client_id === $client->id, 404);
        $n = Salarie::where('client_id', $client->id)->get()->filter(fn ($e) => mb_strtolower(trim((string) $e->profil)) === mb_strtolower($profil->nom))->count();
        if ($n) return back()->with('err', "$n employé(s) ont le profil « {$profil->nom} » : changez d’abord leur profil sur leur fiche.");
        DB::transaction(function () use ($client, $profil) {
            foreach (PaieRubrique::where('client_id', $client->id)->whereNotNull('profils')->get() as $rub) {
                $reste = array_values(array_filter((array) $rub->profils, fn ($x) => mb_strtolower($x) !== mb_strtolower($profil->nom)));
                if ($reste !== (array) $rub->profils) $rub->update(['profils' => $reste]);
            }
            $profil->delete();
        });
        $this->log('Paie : suppression du profil '.$profil->nom, $client->id);
        return redirect()->route('paie.profils', ['mois' => $r->input('mois')])->with('ok', 'Profil supprimé.');
    }

    /**
     * Rubriques du profil : une rubrique cochée concerne le profil, une rubrique décochée ne le concerne plus.
     * Une rubrique « tous les employés » décochée garde tous les autres profils du dossier.
     */
    private function rubriques(Client $client, string $nom, ?string $ancien, array $cochees): void
    {
        $cochees = array_map('intval', $cochees);
        $tous = PaieProfil::duDossier($client)->pluck('nom')->all();
        $cle = fn ($x) => mb_strtolower(trim($x));
        foreach (PaieRubrique::duDossier($client) as $rub) {
            $coche = in_array($rub->id, $cochees, true);
            $liste = $rub->profils;
            if ($liste !== null && $ancien !== null && $ancien !== $nom) {
                $liste = array_map(fn ($x) => $cle($x) === $cle($ancien) ? $nom : $x, $liste);
            }
            if ($liste === null) {
                if ($coche) continue;
                $liste = array_values(array_filter($tous, fn ($x) => $cle($x) !== $cle($nom)));
            } else {
                $liste = array_values(array_filter($liste, fn ($x) => $cle($x) !== $cle($nom)));
                if ($coche) $liste[] = $nom;
            }
            if ($liste !== $rub->profils) $rub->update(['profils' => $liste]);
        }
    }

    private function valider(Request $r, Client $client, ?PaieProfil $actuel = null): array
    {
        $d = $r->validate([
            'nom' => ['required', 'string', 'max:60', Rule::unique('paie_profils')->where('client_id', $client->id)->ignore($actuel?->id)],
            'cnss_patronale' => 'nullable|numeric|min:0|max:100',
            'code_exploitant' => 'nullable|string|max:10',
            'rubriques' => 'nullable|array',
        ], ['nom.unique' => 'Ce profil existe déjà dans le dossier.']);
        unset($d['rubriques']);
        $d['nom'] = trim($d['nom']);
        foreach (array_keys(PaieProfil::OPTIONS) as $c) $d[$c] = $r->boolean($c);
        return $d;
    }
}
