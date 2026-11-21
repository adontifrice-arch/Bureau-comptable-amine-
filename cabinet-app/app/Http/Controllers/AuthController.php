<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin() { return view('auth.login'); }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required']);
        $key = 'login|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withInput()->with('err', 'Trop de tentatives : réessayez dans '.RateLimiter::availableIn($key).' secondes.');
        }
        if (! Auth::attempt($data, $r->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            $connu = User::where('email', $data['email'])->first();
            if ($connu?->cabinet_id) \App\Models\JournalOperation::evenement($connu, 'auth.echec', 'Échec de connexion (mot de passe incorrect)', $r->ip());
            return back()->withInput()->with('err', 'Identifiants incorrects.');
        }
        RateLimiter::clear($key);
        $u = Auth::user();
        if ($u->status !== 'active') {
            \App\Models\JournalOperation::evenement($u, 'auth.refus', 'Connexion refusée (compte '.($u->status === 'disabled' ? 'bloqué' : 'en attente').')', $r->ip());
            Auth::logout();
            return back()->with('err', \App\Http\Middleware\EnsureActive::messageInactif($u));
        }
        if (! \App\Support\Droits::reseauAutorise($u, $r->ip())) {
            \App\Models\JournalOperation::evenement($u, 'auth.refus_reseau', 'Connexion refusée hors du réseau du cabinet', $r->ip());
            Auth::logout();
            return back()->with('err', 'Votre compte n’est autorisé que depuis le réseau du cabinet.');
        }
        $r->session()->regenerate();
        $u->forceFill(['derniere_connexion_at' => now(), 'derniere_ip' => $r->ip()])->saveQuietly();
        \App\Models\JournalOperation::evenement($u, 'auth.connexion', 'Connexion', $r->ip());
        return redirect()->intended(route('dashboard'));
    }

    /** Inscription : sans invitation, crée un cabinet dont on devient chef ; avec le lien d'invitation, rejoint le cabinet (validation par un chef). */
    public function showRegister(?string $code = null)
    {
        return view('auth.register', ['invitation' => $code ? $this->invitation($code) : null]);
    }

    public function register(Request $r, ?string $code = null)
    {
        $invitation = $code ? $this->invitation($code) : null;
        $data = $r->validate([
            'cabinet' => $invitation ? 'prohibited' : 'required|min:2|max:255',
            'name' => 'required|min:2|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($invitation) {
            User::create(Arr::except($data, 'cabinet') + ['cabinet_id' => $invitation->id, 'role' => 'collaborateur', 'status' => 'pending']);
            return redirect()->route('login')->with('ok', 'Inscription enregistrée : un chef comptable de '.$invitation->nom.' doit valider votre compte.');
        }

        DB::transaction(function () use ($data) {
            $cabinet = Cabinet::creer($data['cabinet']);
            User::create(Arr::except($data, 'cabinet') + ['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        });
        return redirect()->route('login')->with('ok', 'Cabinet « '.$data['cabinet'].' » créé : connectez-vous en tant que chef comptable.');
    }

    private function invitation(string $code): Cabinet
    {
        $c = strlen($code) >= 20 ? Cabinet::where('code_invitation', $code)->first() : null;
        abort_unless($c, 404, 'Lien d’invitation invalide ou expiré : demandez un nouveau lien au chef comptable.');
        return $c;
    }

    public function logout(Request $r)
    {
        if ($r->user()?->cabinet_id) \App\Models\JournalOperation::evenement($r->user(), 'auth.deconnexion', 'Déconnexion', $r->ip());
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('login');
    }
}
