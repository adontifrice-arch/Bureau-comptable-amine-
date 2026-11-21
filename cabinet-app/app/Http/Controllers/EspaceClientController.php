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
        $d = $r->validate([
            'categorie' => ['required', Rule::in(Document::CATEGORIES_ENVOI_CLIENT)],
            'message' => 'nullable|max:2000',
            'fichiers' => 'required|array|max:20', 'fichiers.*' => Document::REGLE_FICHIER,
        ], [], ['fichiers' => 'fichiers', 'fichiers.*' => 'fichier']);

        $docs = collect($r->file('fichiers'))->map(fn ($f) => Document::create([
            'client_id' => $client->id, 'categorie' => $d['categorie'], 'description' => $d['message'] ?? null, 'date' => now(),
            'nom' => $f->getClientOriginalName(), 'chemin' => $f->store('ged/'.CabinetCourant::exiger().'/'.$client->id),
            'taille' => $f->getSize(), 'mime' => $f->getMimeType(), 'origine' => 'client', 'depose_par' => $u->id,
        ]));
        Activite::create(['user_id' => null, 'client_id' => $client->id, 'action' => $docs->count().' document(s) envoyé(s) par '.$u->name.' depuis l’espace client']);
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
