<?php

namespace App\Http\Controllers;

use App\Models\Activite;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Services\EspaceClientAvis;
use App\Support\CabinetCourant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Espace client (garde « client ») : le client envoie ses pièces au cabinet et télécharge
 * les documents que le cabinet partage avec lui. Il ne voit jamais que son propre dossier.
 */
class EspaceClientController extends Controller
{
    // ---------- Connexion ----------

    public function showLogin()
    {
        if ($this->compte()) return redirect()->route('espace.index');
        return view('espace.login');
    }

    public function login(Request $r)
    {
        $r->merge(['email' => ClientUser::normaliserEmail($r->input('email'))]);
        $data = $r->validate(['email' => 'required|email', 'password' => 'required']);
        $key = 'espace-login|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withInput($r->only('email'))->with('err', 'Trop de tentatives : réessayez dans '.RateLimiter::availableIn($key).' secondes.');
        }
        if (! Auth::guard('client')->attempt($data + ['actif' => true])) {
            RateLimiter::hit($key, 60);
            return back()->withInput($r->only('email'))->with('err', 'Identifiants incorrects.');
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();
        $this->compte()->forceFill(['derniere_connexion_at' => now()])->save();
        return redirect()->intended(route('espace.index'));
    }

    public function logout(Request $r)
    {
        Auth::guard('client')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('espace.login');
    }

    // ---------- Activation / nouveau mot de passe (lien reçu par e-mail) ----------

    public function showActiver(string $jeton)
    {
        $u = ClientUser::parJeton($jeton);
        if (! $u) return redirect()->route('espace.login')->with('err', 'Ce lien n’est plus valable. Demandez un nouveau lien au cabinet ou utilisez « Mot de passe oublié ».');
        return view('espace.activer', ['u' => $u, 'jeton' => $jeton]);
    }

    public function activer(Request $r, string $jeton)
    {
        $u = ClientUser::parJeton($jeton);
        if (! $u) return redirect()->route('espace.login')->with('err', 'Ce lien n’est plus valable. Demandez un nouveau lien au cabinet ou utilisez « Mot de passe oublié ».');
        $d = $r->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
        $u->forceFill(['password' => $d['password'], 'jeton' => null, 'jeton_expire_at' => null, 'derniere_connexion_at' => now()])->save();
        Auth::guard('client')->login($u);
        $r->session()->regenerate();
        return redirect()->route('espace.index')->with('ok', 'Votre mot de passe est enregistré. Bienvenue dans votre espace client.');
    }

    public function showOubli()
    {
        return view('espace.oubli');
    }

    /** Toujours la même réponse, que l'adresse existe ou non (pas de divulgation des comptes). */
    public function oubli(Request $r)
    {
        $r->merge(['email' => ClientUser::normaliserEmail($r->input('email'))]);
        $d = $r->validate(['email' => 'required|email']);
        $u = ClientUser::where('email', $d['email'])->where('actif', true)->first();
        if ($u) {
            CabinetCourant::pour($u->cabinet_id, function () use ($u) {
                if (Client::find($u->client_id)) EspaceClientAvis::invitation($u, $u->nouveauJeton(), $u->estActive());
            });
        }
        return redirect()->route('espace.login')->with('ok', 'Si cette adresse correspond à un accès actif, un lien pour choisir votre mot de passe vient de vous être envoyé.');
    }

    // ---------- Espace ----------

    public function index(Request $r)
    {
        $u = $this->compte();
        $client = $this->client($r);
        $docs = Document::visiblesPar($u)->with('deposant')->latest()->get();
        return view('espace.index', [
            'u' => $u, 'client' => $client,
            'partages' => $docs->where('partage', true)->where('origine', '!=', 'client')->values(),
            'envois' => $docs->where('origine', 'client')->values(),
        ]);
    }

    public function envoyer(Request $r)
    {
        $u = $this->compte();
        $client = $this->client($r);
        // Plusieurs groupes à la fois, chacun avec son type de documents (et son mois) et ses fichiers ;
        // l'ancien envoi d'un seul type (type_piece + fichiers) reste accepté.
        $d = \Illuminate\Support\Facades\Validator::make(['lots' => self::lotsDocuments($r, 'type_piece', ['periode']), 'message' => $r->input('message')], [
            'lots' => 'required|array|min:1|max:10',
            'lots.*.type_piece' => ['required', Rule::in(array_keys(\App\Support\TypesPieces::TYPES))],
            'lots.*.periode' => 'nullable|date_format:Y-m',
            'lots.*.fichiers' => 'required|array|max:20', 'lots.*.fichiers.*' => Document::REGLE_FICHIER,
            'message' => 'nullable|max:2000',
        ], ['lots.*.type_piece.required' => 'Choisissez le type de chaque groupe de documents.', 'lots.*.fichiers.required' => 'Ajoutez au moins un fichier dans chaque groupe.'],
            ['lots.*.type_piece' => 'type de documents', 'lots.*.periode' => 'mois concerné', 'lots.*.fichiers' => 'fichiers', 'lots.*.fichiers.*' => 'fichier'])->validate();
        if (collect($d['lots'])->sum(fn ($l) => count($l['fichiers'])) > 40) {
            return back()->withInput()->with('err', '40 fichiers au plus par envoi : envoyez le reste en une autre fois.');
        }

        $docs = collect();
        foreach ($d['lots'] as $lot) {
            foreach ($lot['fichiers'] as $f) {
                $doc = Document::create([
                    'client_id' => $client->id, 'categorie' => \App\Support\TypesPieces::categorie($lot['type_piece']), 'description' => $d['message'] ?? null, 'date' => now(),
                    'nom' => $f->getClientOriginalName(), 'chemin' => $f->store('ged/'.CabinetCourant::exiger().'/'.$client->id),
                    'taille' => $f->getSize(), 'mime' => $f->getMimeType(), 'origine' => 'client', 'depose_par' => $u->id,
                ]);
                // Aiguillage vers la comptabilité, les immobilisations ou la paie, et lecture en arrière-plan
                \App\Services\TraitementPieces::recevoir($doc, $lot['type_piece'], $lot['periode'] ?? null);
                $docs->push($doc->fresh());
            }
        }
        $types = collect($d['lots'])->pluck('type_piece')->unique()->map(fn ($t) => \App\Support\TypesPieces::libelle($t))->implode(', ');
        Activite::create(['user_id' => null, 'client_id' => $client->id, 'action' => $docs->count().' document(s) envoyé(s) par '.$u->name.' depuis l’espace client ('.$types.')']);
        EspaceClientAvis::envoiClient($u, $client, $docs, $d['message'] ?? null);
        return back()->with('ok', $docs->count() > 1 ? $docs->count().' documents envoyés au cabinet.' : 'Document envoyé au cabinet.');
    }


    public function telecharger(Request $r, int $document)
    {
        $doc = Document::visiblesPar($this->compte())->findOrFail($document);
        abort_unless(Storage::exists($doc->chemin), 404);
        if ($doc->partage && ! $doc->consulte_at) $doc->forceFill(['consulte_at' => now()])->save();
        return Storage::download($doc->chemin, $doc->nom);
    }

    /** Le client peut retirer un envoi tant que le cabinet ne l'a pas pris en charge. */
    public function retirer(Request $r, int $document)
    {
        $doc = Document::visiblesPar($this->compte())->where('origine', 'client')->whereNull('traite_at')->findOrFail($document);
        Storage::delete($doc->chemin);
        $doc->delete();
        return back()->with('ok', 'Envoi retiré.');
    }

    public function motDePasse(Request $r)
    {
        $u = $this->compte();
        $d = $r->validate(['current_password' => 'required|current_password:client', 'password' => ['required', 'confirmed', Password::min(8)]],
            [], ['current_password' => 'mot de passe actuel']);
        $u->forceFill(['password' => $d['password']])->save();
        return back()->with('ok', 'Mot de passe modifié.');
    }

    private function compte(): ?ClientUser
    {
        return Auth::guard('client')->user();
    }

    private function client(Request $r): Client
    {
        return $r->attributes->get('client');
    }
}
