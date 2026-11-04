<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
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
            return back()->withInput()->with('err', 'Identifiants incorrects.');
        }
        RateLimiter::clear($key);
        if (Auth::user()->status !== 'active') {
            Auth::logout();
            return back()->with('err', 'Votre compte est en attente de validation par le chef comptable.');
        }
        $r->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }

    public function showRegister() { return view('auth.register'); }

    public function register(Request $r)
    {
        $data = $r->validate([
            'name' => 'required|min:2|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $premier = User::count() === 0;
        User::create($data + ['role' => $premier ? 'chef' : 'collaborateur', 'status' => $premier ? 'active' : 'pending']);
        return redirect()->route('login')->with('ok', $premier
            ? 'Compte chef comptable créé : vous pouvez vous connecter.'
            : 'Inscription enregistrée : un chef comptable doit valider votre compte.');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('login');
    }
}
