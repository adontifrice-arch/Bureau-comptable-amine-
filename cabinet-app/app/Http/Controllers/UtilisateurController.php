<?php

namespace App\Http\Controllers;

use App\Models\Activite;
use App\Models\Client;
use App\Models\JournalOperation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Droits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Utilisateurs du cabinet (chef comptable) : création, fonction, rôle, modules visibles, dossiers autorisés,
 * accès hors du réseau du cabinet, blocage, mot de passe, suivi des opérations et des connexions.
 */
class UtilisateurController extends Controller
{
    public function store(Request $r)
    {
        $d = $r->validate($this->regles() + [
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [], $this->noms());
        $u = DB::transaction(function () use ($r, $d) {
            $u = User::create(['cabinet_id' => $r->user()->cabinet_id, 'name' => $d['name'], 'email' => $d['email'], 'password' => $d['password'],
                'role' => $d['role'], 'status' => 'active', 'fonction' => $d['fonction'] ?? null]);
            $this->appliquerDroits($u, $r, $d);
            return $u;
        });
        $this->log('Création de l’utilisateur '.$u->email.' ('.$u->roleLibelle().')');
        return redirect()->route('parametres.utilisateurs.show', $u)->with('ok', 'Utilisateur créé : communiquez-lui son adresse et son mot de passe initial (il pourra le changer dans « Mon compte »).');
    }

    public function show(Request $r, User $user)
    {
        $this->memeCabinet($r, $user);
        $onglet = $r->input('onglet', 'droits');
        $du = $r->date('du') ?? now()->subDays(30)->startOfDay();
        $au = ($r->date('au') ?? now())->copy()->endOfDay();
        $ops = JournalOperation::with('client')->where('user_id', $user->id)->whereBetween('created_at', [$du, $au])
            ->when($r->input('module'), fn ($q, $m) => $m === 'connexions' ? $q->where('action', 'like', 'auth.%') : $q->where('module', $m))
            ->when($r->input('client'), fn ($q, $c) => $q->where('client_id', $c));
        $parModule = (clone $ops)->whereNotNull('module')->selectRaw('module, count(*) as n')->groupBy('module')->pluck('n', 'module');
        return view('parametres.utilisateur', [
            'x' => $user, 'onglet' => $onglet, 'du' => $du, 'au' => $au,
            'clients' => Client::orderBy('nom')->get(['id', 'nom']),
            'dossiers' => $user->dossiers()->pluck('clients.id')->all(),
            'operations' => (clone $ops)->latest('id')->paginate(50)->withQueryString(),
            'parModule' => $parModule,
            'connexions' => JournalOperation::where('user_id', $user->id)->where('action', 'like', 'auth.%')->latest('id')->limit(15)->get(),
            'activites' => Activite::with('client')->where('user_id', $user->id)->whereBetween('created_at', [$du, $au])->latest()->limit(100)->get(),
            'nbEcritures' => DB::table('ecritures')->where('cabinet_id', $user->cabinet_id)->where('valide_par', $user->id)->whereBetween('created_at', [$du, $au])->count(),
            'reseau' => Droits::adressesReseau(),
        ]);
    }

    public function update(Request $r, User $user)
    {
        $this->memeCabinet($r, $user);
        $d = $r->validate($this->regles() + ['email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)]], [], $this->noms());
        if ($user->is($r->user()) && $d['role'] !== 'chef') return back()->with('err', 'Vous ne pouvez pas retirer votre propre rôle de chef comptable.');
        if ($user->estChef() && $d['role'] !== 'chef' && $this->dernierChef($user)) return back()->with('err', 'Le cabinet doit garder au moins un chef comptable actif.');
        DB::transaction(function () use ($r, $user, $d) {
            $user->forceFill(['name' => $d['name'], 'email' => $d['email'], 'role' => $d['role'], 'fonction' => $d['fonction'] ?? null])->save();
            $this->appliquerDroits($user, $r, $d);
        });
        $this->log('Droits de l’utilisateur '.$user->email.' modifiés');
        return back()->with('ok', 'Utilisateur enregistré.');
    }

    public function bloquer(Request $r, User $user)
    {
        $this->memeCabinet($r, $user);
        if ($user->is($r->user())) return back()->with('err', 'Vous ne pouvez pas bloquer votre propre compte.');
        if ($user->estChef() && $this->dernierChef($user)) return back()->with('err', 'Le cabinet doit garder au moins un chef comptable actif.');
        $d = $r->validate(['motif' => 'nullable|max:255']);
        $user->forceFill(['status' => 'disabled', 'motif_blocage' => $d['motif'] ?? null, 'bloque_le' => now()])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();      // déconnexion immédiate
        $this->log('Blocage de l’utilisateur '.$user->email.(! empty($d['motif']) ? ' : '.$d['motif'] : ''));
        return back()->with('ok', $user->name.' est bloqué : il ne peut plus se connecter.');
    }

    public function debloquer(Request $r, User $user)
    {
        $this->memeCabinet($r, $user);
        $user->forceFill(['status' => 'active', 'motif_blocage' => null, 'bloque_le' => null])->save();
        $this->log('Déblocage de l’utilisateur '.$user->email);
        return back()->with('ok', $user->name.' est débloqué.');
    }

    /** Nouveau mot de passe fixé par le chef comptable (oubli) ; l'utilisateur est déconnecté de ses sessions ouvertes. */
    public function motDePasse(Request $r, User $user)
    {
        $this->memeCabinet($r, $user);
        $d = $r->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
        $user->forceFill(['password' => $d['password'], 'remember_token' => null])->save();
        if (! $user->is($r->user())) DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->log('Nouveau mot de passe pour '.$user->email);
        return back()->with('ok', 'Mot de passe modifié : communiquez-le à '.$user->name.'.');
    }

    /** Réseau du cabinet : adresses IP (ou plages CIDR) depuis lesquelles travaillent les utilisateurs sans accès à distance. */
    public function reseau(Request $r)
    {
        $d = $r->validate(['adresses' => 'nullable|max:2000']);
        $liste = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($d['adresses'] ?? '')) ?: [])));
        $invalides = array_filter($liste, fn ($a) => ! Droits::adresseValide($a));
        if ($invalides) return back()->withInput()->with('err', 'Adresse(s) invalide(s) : '.implode(', ', $invalides).'. Exemples valides : 197.15.20.4 ou 192.168.1.0/24.');
        Setting::put('reseau', ['adresses' => implode("\n", array_unique($liste))]);
        $this->log('Réseau du cabinet : '.(count($liste) ? implode(', ', $liste) : 'aucune adresse'));
        return redirect()->route('parametres.index', ['tab' => 'users'])->with('ok', count($liste) ? 'Réseau du cabinet enregistré.' : 'Réseau du cabinet effacé : aucun contrôle d’adresse.');
    }

    // ---------- Outils ----------

    private function regles(): array
    {
        return [
            'name' => 'required|min:2|max:100',
            'fonction' => 'nullable|max:100',
            'role' => 'required|in:chef,collaborateur',
            'modules_tous' => 'nullable|boolean',
            'modules' => 'nullable|array', 'modules.*' => Rule::in(array_keys(Droits::MODULES)),
            'dossiers_tous' => 'nullable|boolean',
            'dossiers' => 'nullable|array', 'dossiers.*' => 'integer|'.existe_cab('clients'),
            'acces_distant' => 'nullable|boolean',
            'droit_ecritures' => 'nullable|boolean',
        ];
    }

    private function noms(): array
    {
        return ['name' => 'nom', 'fonction' => 'fonction', 'role' => 'rôle', 'email' => 'adresse e-mail', 'password' => 'mot de passe', 'modules' => 'modules', 'dossiers' => 'dossiers'];
    }

    /** Modules, dossiers, accès à distance et droit sur les écritures (sans objet pour un chef comptable, qui a tous les droits). */
    private function appliquerDroits(User $u, Request $r, array $d): void
    {
        $chef = $d['role'] === 'chef';
        $u->forceFill([
            'modules' => $chef || $r->boolean('modules_tous') ? null : array_values($d['modules'] ?? []),
            'tous_dossiers' => $chef || $r->boolean('dossiers_tous'),
            'acces_distant' => $chef || $r->boolean('acces_distant'),
            'droit_ecritures' => ! $chef && $r->boolean('droit_ecritures'),
        ])->save();
        $u->dossiers()->sync($u->tous_dossiers ? [] : array_map('intval', $d['dossiers'] ?? []));
        Droits::oublier();
    }

    private function memeCabinet(Request $r, User $user): void
    {
        abort_unless($user->memeCabinet($r->user()), 404);
    }

    private function dernierChef(User $user): bool
    {
        return ! User::where('cabinet_id', $user->cabinet_id)->where('role', 'chef')->where('status', 'active')->whereKeyNot($user->id)->exists();
    }
}
