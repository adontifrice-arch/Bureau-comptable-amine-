@php $chef = auth()->user()->estChef(); @endphp
<x-layouts.app title="Paramètres">
    <x-page-header title="Paramètres"/>
    <x-tabs :active="$tab" :items="array_filter(['cabinet' => $chef ? ['Cabinet', '?tab=cabinet'] : null, 'fiscal' => $chef ? ['Fiscalité', '?tab=fiscal'] : null, 'users' => $chef ? ['Utilisateurs ('.$users->where('status', 'pending')->count().' en attente)', '?tab=users'] : null, 'compte' => ['Mon compte', '?tab=compte']])"/>
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
        <div class="card card-b mb-6" x-data="{ copie: false }">
            <h2 class="card-t">Inviter un collaborateur</h2>
            <p class="mt-1 text-sm text-slate-500">Envoyez ce lien à la personne : elle crée son compte, que vous validez ensuite ci-dessous.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <input class="input flex-1 font-mono text-xs" readonly value="{{ $invitation }}" x-ref="lien" @focus="$el.select()" aria-label="Lien d’invitation">
                <button type="button" class="btn-secondary" @click="navigator.clipboard.writeText($refs.lien.value); copie = true; setTimeout(() => copie = false, 2000)"><x-icon name="copy" class="h-4 w-4"/> <span x-text="copie ? 'Copié' : 'Copier'"></span></button>
                <form method="POST" action="{{ route('parametres.invitation') }}" onsubmit="return confirm('Créer un nouveau lien ? L’ancien lien ne fonctionnera plus.')">@csrf
                    <button class="btn-secondary" title="Désactive l’ancien lien">Nouveau lien</button>
                </form>
            </div>
        </div>
        <div class="card">@foreach($users as $x)
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                <span class="grid h-9 w-9 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ $x->initiales() }}</span>
                <div class="min-w-0 flex-1"><div class="font-medium">{{ $x->name }} @if($x->is(auth()->user()))<span class="text-xs text-slate-400">(vous)</span>@endif</div><div class="text-xs text-slate-500">{{ $x->email }} · inscrit {{ $x->created_at->diffForHumans() }}</div></div>
                <span class="{{ $x->estChef() ? 'badge-blue' : 'badge-gray' }}">{{ $x->roleLibelle() }}</span>
                @if(! $x->estChef() && $x->droit_ecritures)<span class="badge-amber" title="Autorisé par le chef comptable">Modifie / supprime les écritures</span>@endif
                <span class="badge-{{ ['active' => 'green', 'pending' => 'amber', 'disabled' => 'red'][$x->status] }}">{{ ['active' => 'Actif', 'pending' => 'En attente', 'disabled' => 'Désactivé'][$x->status] }}</span>
                @unless($x->is(auth()->user()))
                    <form method="POST" action="{{ route('parametres.user', $x) }}" class="flex gap-1">@csrf @method('PATCH')
                        @if($x->status !== 'active')<button name="action" value="activer" class="btn-success btn-sm">{{ $x->status === 'pending' ? 'Valider' : 'Réactiver' }}</button>@else<button name="action" value="desactiver" class="btn-secondary btn-sm">Désactiver</button>@endif
                        <button name="action" value="role" class="btn-secondary btn-sm">{{ $x->estChef() ? 'Passer collaborateur' : 'Nommer chef comptable' }}</button>
                        @unless($x->estChef())<button name="action" value="droit_ecritures" class="btn-secondary btn-sm" title="Modifier et supprimer les écritures comptables">{{ $x->droit_ecritures ? 'Retirer le droit sur les écritures' : 'Autoriser à modifier / supprimer les écritures' }}</button>@endunless
                    </form>
                    <x-confirm-delete :action="route('parametres.user.destroy', $x)"/>
                @endunless
            </div>
        @endforeach</div>
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
