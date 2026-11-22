<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
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
        $cabinet = $r->user()->cabinet;
        // Paramètres réservés au chef comptable : un collaborateur n'a que « Mon compte »
        $tab = $r->user()->estChef() ? $r->input('tab', 'cabinet') : 'compte';
        return view('parametres.index', ['tab' => $tab, 'c' => Setting::cabinet(),
            'users' => User::where('cabinet_id', $r->user()->cabinet_id)->orderByRaw("status = 'pending' desc")->orderBy('name')->get(),
            'invitation' => $cabinet ? route('register.invitation', $cabinet->code_invitation) : null,
            'clients' => $tab === 'users' ? \App\Models\Client::orderBy('nom')->get(['id', 'nom']) : collect(),
            'reseau' => \App\Support\Droits::adressesReseau(), 'ip' => $r->ip(),
            'sauvegarde' => \App\Services\Sauvegardes::reglages(),
            'sauvegardes' => $tab === 'sauvegardes' ? \App\Models\Sauvegarde::with('user')->latest('id')->limit(50)->get() : collect()]);
    }

    public function cabinet(Request $r)
    {
        $d = $r->validate(['nom' => 'required|max:255', 'adresse' => 'nullable|max:255', 'mf' => 'nullable|max:50', 'telephone' => 'nullable|max:50', 'email' => 'nullable|email',
            'banque' => 'nullable|max:100', 'titulaire' => 'nullable|max:255', 'rib' => 'nullable|max:50', 'iban' => 'nullable|max:50', 'bic' => 'nullable|max:20',
            'slogan' => 'nullable|max:255', 'logo' => 'nullable|image|max:2048']);
        $old = Setting::cabinet();
        $d['logo'] = $old['logo'];
        if ($r->hasFile('logo')) { if ($old['logo']) Storage::disk('public')->delete($old['logo']); $d['logo'] = $r->file('logo')->store('logo/'.$r->user()->cabinet_id, 'public'); }
        if ($r->boolean('retirer_logo') && $old['logo']) { Storage::disk('public')->delete($old['logo']); $d['logo'] = null; }
        Setting::put('cabinet', $d);
        $r->user()->cabinet?->update(['nom' => $d['nom']]);
        return back()->with('ok', 'Paramètres du cabinet enregistrés.');
    }

    /** Paramètres fiscaux : taux de la contribution sociale de solidarité (CSS) des personnes physiques. */
    public function fiscal(Request $r)
    {
        $d = $r->validate(['css' => 'required|numeric|min:0|max:100', 'feries' => ['nullable', 'max:2000', 'regex:#^[\d/\-\s,;]*$#']],
            ['css.required' => 'Indiquez le taux de la CSS.', 'feries.regex' => 'Jours fériés : une date par ligne (JJ/MM/AAAA).'], ['css' => 'taux de la CSS']);
        Setting::put('fiscal', ['css' => +$d['css'], 'feries' => trim((string) ($d['feries'] ?? ''))] + Setting::fiscal());
        // Report au jour ouvrable : les échéances non cochées du mois suivent les jours fériés saisis
        \App\Services\EcheancesFiscales::genererCabinet(now()->year, now()->month);
        $this->log('Taux de la CSS fixé à '.+$d['css'].' %');
        return redirect()->route('parametres.index', ['tab' => 'fiscal'])->with('ok', 'Taux de la CSS enregistré : '.str_replace('.', ',', (string) +$d['css']).' %.');
    }

    /** Certificat de signature électronique (ANCE/TunTrust, fichier .p12/.pfx) pour les factures El Fatoora. */
    public function elfatooraCertificat(Request $r)
    {
        $r->validate(['certificat' => 'required|file|max:100', 'certificat_mdp' => 'required|string|max:200'],
            ['certificat.required' => 'Choisissez le fichier du certificat (.p12 ou .pfx).', 'certificat_mdp.required' => 'Indiquez le mot de passe du certificat.']);
        if (! in_array(strtolower($r->file('certificat')->getClientOriginalExtension()), ['p12', 'pfx'], true)) {
            return $this->versElFatoora()->with('err', 'Le certificat doit être un fichier .p12 ou .pfx.');
        }
        try {
            $i = \App\Services\ElFatoora\ElFatoora::enregistrerCertificat($r->file('certificat')->get(), $r->input('certificat_mdp'));
        } catch (\RuntimeException $e) {
            return $this->versElFatoora()->with('err', $e->getMessage());
        }
        $this->log('Certificat de signature El Fatoora importé ('.$i['titulaire'].')');
        return $this->versElFatoora()->with('ok', 'Certificat de '.$i['titulaire'].' enregistré, valable jusqu’au '.dfr($i['fin']).'.');
    }

    public function elfatooraCertificatRetirer()
    {
        \App\Services\ElFatoora\ElFatoora::retirerCertificat();
        $this->log('Certificat de signature El Fatoora retiré');
        return $this->versElFatoora()->with('ok', 'Certificat retiré.');
    }

    public function elfatooraAcces(Request $r)
    {
        $d = $r->validate(['url' => 'nullable|url|max:255', 'login' => 'nullable|max:100', 'mdp' => 'nullable|max:200', 'role' => 'nullable|max:60']);
        \App\Services\ElFatoora\ElFatoora::enregistrerAcces($d);
        $this->log('Accès au web service El Fatoora modifiés');
        return $this->versElFatoora()->with('ok', 'Accès El Fatoora enregistrés.');
    }

    private function versElFatoora()
    {
        return redirect()->route('parametres.index', ['tab' => 'elfatoora']);
    }

    /** Nouveau lien d'invitation : l'ancien cesse de fonctionner. */
    public function invitation(Request $r)
    {
        $r->user()->cabinet->regenererCode();
        $this->log('Nouveau lien d’invitation des collaborateurs');
        return redirect()->route('parametres.index', ['tab' => 'users'])->with('ok', 'Nouveau lien d’invitation créé : l’ancien ne fonctionne plus.');
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
        abort_unless($user->memeCabinet($r->user()), 404);
        if ($user->is($r->user())) return back()->with('err', 'Vous ne pouvez pas modifier votre propre rôle ou statut.');
        $a = $r->input('action');
        if (in_array($a, ['desactiver', 'role'], true) && $user->estChef() && $this->dernierChef($user)) {
            return back()->with('err', 'Le cabinet doit garder au moins un chef comptable actif.');
        }
        if ($a === 'activer') $user->forceFill(['status' => 'active', 'motif_blocage' => null, 'bloque_le' => null])->save();
        if ($a === 'desactiver') $user->forceFill(['status' => 'disabled', 'bloque_le' => now()])->save();
        if ($a === 'role') $user->update(['role' => $user->role === 'chef' ? 'collaborateur' : 'chef']);
        // Droit de modifier et de supprimer les écritures comptables (un chef comptable l'a toujours)
        if ($a === 'droit_ecritures') $user->forceFill(['droit_ecritures' => ! $user->droit_ecritures])->save();
        $this->log('Utilisateur '.$user->email.' : '.$a);
        return back()->with('ok', 'Utilisateur mis à jour.');
    }

    public function userDestroy(Request $r, User $user)
    {
        abort_unless($user->memeCabinet($r->user()), 404);
        if ($user->is($r->user())) return back()->with('err', 'Impossible de supprimer votre propre compte.');
        if ($user->estChef() && $this->dernierChef($user)) return back()->with('err', 'Impossible de supprimer le dernier chef comptable du cabinet.');
        $user->delete();
        $this->log('Suppression de l’utilisateur '.$user->email);
        return back()->with('ok', 'Utilisateur supprimé.');
    }

    /** Vrai si $user est le seul chef comptable actif de son cabinet. */
    private function dernierChef(User $user): bool
    {
        return ! User::where('cabinet_id', $user->cabinet_id)->where('role', 'chef')->where('status', 'active')->whereKeyNot($user->id)->exists();
    }
}
