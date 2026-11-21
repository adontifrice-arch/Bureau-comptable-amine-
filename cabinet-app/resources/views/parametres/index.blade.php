@php $chef = auth()->user()->estChef(); @endphp
<x-layouts.app title="Paramètres">
    <x-page-header title="Paramètres"/>
    <x-tabs :active="$tab" :items="array_filter(['cabinet' => $chef ? ['Cabinet', '?tab=cabinet'] : null, 'fiscal' => $chef ? ['Fiscalité', '?tab=fiscal'] : null, 'users' => $chef ? ['Utilisateurs'.(($p = $users->where('status', 'pending')->count()) ? ' ('.$p.' en attente)' : ''), '?tab=users'] : null, 'sauvegardes' => $chef ? ['Sauvegardes', '?tab=sauvegardes'] : null, 'compte' => ['Mon compte', '?tab=compte']])"/>
    @if($tab === 'cabinet' && $chef)
        <form method="POST" action="{{ route('parametres.cabinet') }}" enctype="multipart/form-data" class="grid gap-6 lg:grid-cols-3">@csrf
            <div class="card lg:col-span-2"><div class="card-h"><h2 class="card-t">Coordonnées (en-tête des factures et documents)</h2></div>
                <div class="card-b grid gap-4 md:grid-cols-2">
                    <x-field label="Nom du cabinet" name="nom" :value="$c['nom']" required span="md:col-span-2"/>
                    <x-field label="Adresse" name="adresse" :value="$c['adresse']" span="md:col-span-2"/>
                    <x-field label="Matricule fiscal" name="mf" :value="$c['mf']" hint="7 chiffres + lettre : requis pour les fichiers TEIF et TEJ"/>
                    <x-field label="Téléphone" name="telephone" :value="$c['telephone']"/>
                    <x-field label="E-mail" name="email" type="email" :value="$c['email']"/>
                    <x-field label="Slogan (pied de page)" name="slogan" :value="$c['slogan']"/>
                </div>
                <div class="card-h border-t"><h2 class="card-t">Coordonnées bancaires</h2></div>
                <div class="card-b grid gap-4 md:grid-cols-2">
                    <x-field label="Banque" name="banque" :value="$c['banque']"/><x-field label="Titulaire" name="titulaire" :value="$c['titulaire']"/>
                    <x-field label="RIB" name="rib" :value="$c['rib']"/><x-field label="IBAN" name="iban" :value="$c['iban']"/><x-field label="BIC" name="bic" :value="$c['bic']"/>
                </div>
            </div>
            <div class="space-y-6">
                <div class="card card-b space-y-4"><h2 class="card-t">Logo</h2>
                    @if($c['logo'])<img src="{{ asset('storage/'.$c['logo']) }}" class="max-h-24 rounded-lg border border-slate-200 bg-white p-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="retirer_logo" value="1"> Retirer le logo</label>@endif
                    <input type="file" name="logo" accept="image/*" class="input">
                </div>
                <button class="btn-primary w-full">Enregistrer</button>
            </div>
        </form>
    @elseif($tab === 'fiscal' && $chef)
        @php $fisc = \App\Models\Setting::fiscal(); @endphp
        <form method="POST" action="{{ route('parametres.fiscal') }}" class="card max-w-xl">@csrf
            <div class="card-h"><h2 class="card-t">Contribution sociale de solidarité (CSS)</h2></div>
            <div class="card-b space-y-4">
                <div><label class="label" for="f-css">Taux de la CSS des personnes physiques (%)</label>
                    <input id="f-css" type="number" name="css" step="0.01" min="0" max="100" value="{{ old('css', $fisc['css']) }}" class="input max-w-[10rem]" required>
                    <p class="mt-1 text-xs text-slate-400">CSS = bénéfice net imposable × ce taux (déclaration annuelle de l’impôt sur le revenu).</p>
                    @error('css')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="f-feries">Jours fériés à ajouter (une date par ligne, JJ/MM/AAAA)</label>
                    <textarea id="f-feries" name="feries" rows="4" class="input font-mono" placeholder="20/03/2026">{{ old('feries', $fisc['feries'] ?? '') }}</textarea>
                    <p class="mt-1 text-xs text-slate-400">Les échéances tombant un samedi, un dimanche ou un jour férié sont reportées au jour ouvrable suivant. Jours fériés fixes pris en compte :
                        1er janvier, 20 mars, 9 avril, 1er mai, 25 juillet, 13 août, 15 octobre, 17 décembre ; fêtes religieuses (Aïd el-Fitr, Aïd el-Idha, Ras El Am El Hijri, Mouled) à dates prévisionnelles jusqu’en 2028 :
                        saisissez ici les dates officielles annoncées.</p>
                    @error('feries')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <button class="btn-primary">Enregistrer</button>
            </div>
        </form>
    @elseif($tab === 'users' && $chef)
        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            <div class="card card-b" x-data="{ copie: false }">
                <div class="flex items-start justify-between gap-3">
                    <div><h2 class="card-t">Utilisateurs du cabinet</h2>
                        <p class="mt-1 text-sm text-slate-500">Créez un compte avec ses fonctions, ses modules et ses dossiers, ou envoyez le lien d’inscription (compte à valider).</p></div>
                    <button type="button" class="btn-primary shrink-0" x-data @click="$dispatch('open-modal', 'nouvel-utilisateur')"><x-icon name="plus" class="h-4 w-4"/> Ajouter un utilisateur</button>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <input class="input flex-1 font-mono text-xs" readonly value="{{ $invitation }}" x-ref="lien" @focus="$el.select()" aria-label="Lien d’invitation">
                    <button type="button" class="btn-secondary" @click="navigator.clipboard.writeText($refs.lien.value); copie = true; setTimeout(() => copie = false, 2000)"><x-icon name="copy" class="h-4 w-4"/> <span x-text="copie ? 'Copié' : 'Copier le lien'"></span></button>
                    <form method="POST" action="{{ route('parametres.invitation') }}" onsubmit="return confirm('Créer un nouveau lien ? L’ancien lien ne fonctionnera plus.')">@csrf
                        <button class="btn-secondary" title="Désactive l’ancien lien">Nouveau lien</button>
                    </form>
                </div>
            </div>
            <form method="POST" action="{{ route('parametres.reseau') }}" class="card card-b space-y-3">@csrf
                <h2 class="card-t">Réseau du cabinet (accès à distance)</h2>
                <p class="text-sm text-slate-500">Adresses IP publiques du cabinet, une par ligne (ou plage, ex. 192.168.1.0/24). Les utilisateurs sans « accès à distance » ne peuvent se connecter que depuis ces adresses.</p>
                <textarea name="adresses" rows="3" class="input font-mono text-sm" placeholder="197.15.20.4">{{ old('adresses', implode("\n", $reseau)) }}</textarea>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs text-slate-500">Votre adresse actuelle : <span class="font-mono">{{ $ip }}</span>
                        <button type="button" class="ml-1 font-medium text-brand-700 hover:underline" onclick="const t=this.closest('form').adresses; t.value=(t.value.trim()?t.value.trim()+'\n':'')+@js($ip)">ajouter</button></span>
                    <button class="btn-secondary btn-sm">Enregistrer</button>
                </div>
                @unless($reseau)<p class="text-xs text-amber-700">Aucune adresse : le contrôle d’accès à distance n’est pas actif.</p>@endunless
            </form>
        </div>

        <div class="card">@foreach($users as $x)
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                <span class="grid h-9 w-9 place-items-center rounded-full {{ $x->estBloque() ? 'bg-slate-400' : 'bg-brand-600' }} text-xs font-semibold text-white">{{ $x->initiales() }}</span>
                <a href="{{ route('parametres.utilisateurs.show', $x) }}" class="min-w-0 flex-1 hover:text-brand-700">
                    <div class="font-medium">{{ $x->name }} @if($x->is(auth()->user()))<span class="text-xs text-slate-400">(vous)</span>@endif</div>
                    <div class="text-xs text-slate-500">{{ $x->fonction ? $x->fonction.' · ' : '' }}{{ $x->email }} · {{ $x->derniere_connexion_at ? 'connecté '.$x->derniere_connexion_at->diffForHumans() : 'jamais connecté' }}</div>
                </a>
                <span class="{{ $x->estChef() ? 'badge-blue' : 'badge-gray' }}">{{ $x->roleLibelle() }}</span>
                @unless($x->estChef())
                    @if($x->modules !== null)<span class="badge-gray" title="{{ collect($x->modulesAutorises())->map(fn ($m) => \App\Support\Droits::libelleModule($m))->implode(', ') }}">{{ count($x->modulesAutorises()) }} module(s)</span>@endif
                    @unless($x->tous_dossiers)<span class="badge-gray">Dossiers limités</span>@endunless
                    @unless($x->acces_distant)<span class="badge-gray">Réseau du cabinet</span>@endunless
                    @if($x->droit_ecritures)<span class="badge-amber" title="Autorisé par le chef comptable">Modifie / supprime les écritures</span>@endif
                @endunless
                <span class="badge-{{ ['active' => 'green', 'pending' => 'amber', 'disabled' => 'red'][$x->status] }}" @if($x->motif_blocage) title="{{ $x->motif_blocage }}" @endif>{{ ['active' => 'Actif', 'pending' => 'En attente', 'disabled' => 'Bloqué'][$x->status] }}</span>
                @if($x->status === 'pending')
                    <form method="POST" action="{{ route('parametres.user', $x) }}">@csrf @method('PATCH')<button name="action" value="activer" class="btn-success btn-sm">Valider</button></form>
                @endif
                <a href="{{ route('parametres.utilisateurs.show', $x) }}" class="btn-secondary btn-sm">Droits & suivi</a>
                @unless($x->is(auth()->user()))<x-confirm-delete :action="route('parametres.user.destroy', $x)" message="Supprimer définitivement cet utilisateur ? Son suivi reste dans le journal."/>@endunless
            </div>
        @endforeach</div>

        <x-modal name="nouvel-utilisateur" title="Ajouter un utilisateur" size="max-w-3xl">
            <form method="POST" action="{{ route('parametres.utilisateurs.store') }}" class="space-y-5">@csrf
                @include('parametres._droits', ['x' => null, 'dossiers' => []])
                <div class="grid gap-4 md:grid-cols-2">
                    <x-field label="Mot de passe initial (8 caractères min.)" name="password" type="password" required autocomplete="new-password"/>
                    <x-field label="Confirmation" name="password_confirmation" type="password" required autocomplete="new-password"/>
                </div>
                <div class="flex justify-end"><button class="btn-primary">Créer l’utilisateur</button></div>
            </form>
        </x-modal>
        @if($errors->any() && old('name') !== null)  {{-- erreur dans le formulaire de création : on le rouvre --}}
            <div x-data x-init="$nextTick(() => $dispatch('open-modal', 'nouvel-utilisateur'))"></div>
        @endif
    @elseif($tab === 'sauvegardes' && $chef)
        @include('parametres._sauvegardes')
    @else
        <form method="POST" action="{{ route('parametres.password') }}" class="card card-b max-w-md space-y-4">@csrf
            <h2 class="card-t">Changer mon mot de passe</h2>
            <x-field label="Mot de passe actuel" name="actuel" type="password" required/>
            <x-field label="Nouveau mot de passe" name="password" type="password" required/>
            <x-field label="Confirmer" name="password_confirmation" type="password" required/>
            <button class="btn-primary">Modifier</button>
        </form>
    @endif
</x-layouts.app>
