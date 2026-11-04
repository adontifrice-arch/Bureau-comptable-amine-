<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ParametreController extends Controller
{
    public function index(Request $r)
    {
        return view('parametres.index', ['tab' => $r->input('tab', auth()->user()->estChef() ? 'cabinet' : 'compte'), 'c' => Setting::cabinet(),
            'users' => User::orderByRaw("status = 'pending' desc")->orderBy('name')->get()]);
    }

    public function cabinet(Request $r)
    {
        $d = $r->validate(['nom' => 'required|max:255', 'adresse' => 'nullable|max:255', 'mf' => 'nullable|max:50', 'telephone' => 'nullable|max:50', 'email' => 'nullable|email',
            'banque' => 'nullable|max:100', 'titulaire' => 'nullable|max:255', 'rib' => 'nullable|max:50', 'iban' => 'nullable|max:50', 'bic' => 'nullable|max:20',
            'slogan' => 'nullable|max:255', 'logo' => 'nullable|image|max:2048']);
        $old = Setting::cabinet();
        $d['logo'] = $old['logo'];
        if ($r->hasFile('logo')) { if ($old['logo']) Storage::disk('public')->delete($old['logo']); $d['logo'] = $r->file('logo')->store('logo', 'public'); }
        if ($r->boolean('retirer_logo') && $old['logo']) { Storage::disk('public')->delete($old['logo']); $d['logo'] = null; }
        Setting::put('cabinet', $d);
        return back()->with('ok', 'Paramètres du cabinet enregistrés.');
    }

    public function password(Request $r)
    {
        $r->validate(['actuel' => 'required', 'password' => ['required', 'confirmed', Password::min(8)]]);
        if (! Hash::check($r->input('actuel'), $r->user()->password)) return back()->with('err', 'Mot de passe actuel incorrect.');
        $r->user()->update(['password' => $r->input('password')]);
        return back()->with('ok', 'Mot de passe modifié.');
    }

    public function user(Request $r, User $user)
    {
        if ($user->is($r->user())) return back()->with('err', 'Vous ne pouvez pas modifier votre propre rôle ou statut.');
        $a = $r->input('action');
        if ($a === 'activer') $user->update(['status' => 'active']);
        if ($a === 'desactiver') $user->update(['status' => 'disabled']);
        if ($a === 'role') $user->update(['role' => $user->role === 'chef' ? 'collaborateur' : 'chef']);
        return back()->with('ok', 'Utilisateur mis à jour.');
    }

    public function userDestroy(Request $r, User $user)
    {
        if ($user->is($r->user())) return back()->with('err', 'Impossible de supprimer votre propre compte.');
        $user->delete();
        return back()->with('ok', 'Utilisateur supprimé.');
    }
}
