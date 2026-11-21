@use('App\Support\Droits')
<x-layouts.app :title="$x->name">
    <x-page-header :title="$x->name" :subtitle="($x->fonction ? $x->fonction.' · ' : '').$x->roleLibelle().' · '.$x->email" :back="route('parametres.index', ['tab' => 'users'])">
        <span class="badge-{{ ['active' => 'green', 'pending' => 'amber', 'disabled' => 'red'][$x->status] }}">{{ ['active' => 'Actif', 'pending' => 'En attente', 'disabled' => 'Bloqué'][$x->status] }}</span>
    </x-page-header>

    @php $u = fn ($o) => route('parametres.utilisateurs.show', [$x, 'onglet' => $o]); @endphp
    <x-tabs :active="$onglet" :items="['droits' => ['Profil et droits', $u('droits')], 'suivi' => ['Suivi des opérations', $u('suivi')]]"/>

    @if($onglet === 'droits')
        <div class="grid gap-6 lg:grid-cols-3">
            <form method="POST" action="{{ route('parametres.utilisateurs.update', $x) }}" class="card lg:col-span-2">@csrf @method('PUT')
                <div class="card-h"><h2 class="card-t">Profil, fonctions et droits</h2></div>
                <div class="card-b space-y-5">
                    @include('parametres._droits', ['x' => $x])
                    <div class="flex justify-end"><button class="btn-primary">Enregistrer</button></div>
                </div>
            </form>

            <div class="space-y-6">
                <div class="card card-b space-y-3">
                    <h2 class="card-t">Accès</h2>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Dernière connexion</dt><dd>{{ $x->derniere_connexion_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Depuis l’adresse</dt><dd class="font-mono">{{ $x->derniere_ip ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Travail à distance</dt><dd>{{ $x->estChef() || $x->acces_distant ? 'Autorisé' : ($reseau ? 'Réseau du cabinet seulement' : 'Limité, mais réseau non déclaré') }}</dd></div>
                    </dl>
                    @if($x->is(auth()->user()))
                        <p class="text-xs text-slate-500">C’est votre compte : vous ne pouvez pas le bloquer.</p>
                    @elseif($x->estBloque())
                        <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">Bloqué{{ $x->bloque_le ? ' le '.$x->bloque_le->format('d/m/Y à H:i') : '' }}{{ $x->motif_blocage ? ' : '.$x->motif_blocage : '' }}</div>
                        <form method="POST" action="{{ route('parametres.utilisateurs.debloquer', $x) }}">@csrf<button class="btn-success w-full">Débloquer</button></form>
                    @else
                        <form method="POST" action="{{ route('parametres.utilisateurs.bloquer', $x) }}" class="space-y-2" onsubmit="return confirm('Bloquer {{ addslashes($x->name) }} ? Il sera déconnecté immédiatement.')">@csrf
                            <input name="motif" class="input" placeholder="Motif (facultatif, affiché à l’utilisateur)">
                            <button class="btn-secondary w-full !text-rose-600"><x-icon name="lock" class="h-4 w-4"/> Bloquer l’utilisateur</button>
                        </form>
                    @endif
                </div>

                <form method="POST" action="{{ route('parametres.utilisateurs.mot-de-passe', $x) }}" class="card card-b space-y-3">@csrf
                    <h2 class="card-t">Nouveau mot de passe</h2>
                    <p class="text-xs text-slate-500">En cas d’oubli : fixez un mot de passe et communiquez-le à l’utilisateur. Ses sessions ouvertes sont fermées.</p>
                    <input type="password" name="password" class="input" placeholder="Nouveau mot de passe" autocomplete="new-password" required>
                    <input type="password" name="password_confirmation" class="input" placeholder="Confirmation" autocomplete="new-password" required>
                    <button class="btn-secondary w-full">Modifier le mot de passe</button>
                </form>
            </div>
        </div>

    @else
        <form class="card mb-6 grid gap-3 p-4 md:grid-cols-5">
            <input type="hidden" name="onglet" value="suivi">
            <div><label class="label">Du</label><input type="date" name="du" value="{{ $du->format('Y-m-d') }}" class="input"></div>
            <div><label class="label">Au</label><input type="date" name="au" value="{{ $au->format('Y-m-d') }}" class="input"></div>
            <div><label class="label">Module</label><select name="module" class="input"><option value="">Tous</option><option value="connexions" @selected(request('module') === 'connexions')>Connexions</option>@foreach(Droits::MODULES as $k => [$lib])<option value="{{ $k }}" @selected(request('module') === $k)>{{ $lib }}</option>@endforeach</select></div>
            <div><label class="label">Dossier</label><select name="client" class="input"><option value="">Tous</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->nom }}</option>@endforeach</select></div>
            <div class="flex items-end"><button class="btn-primary w-full">Filtrer</button></div>
        </form>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat label="Opérations sur la période" :value="$operations->total()" icon="list"/>
            <x-stat label="Écritures comptables passées" :value="$nbEcritures" icon="book"/>
            <x-stat label="Dossiers touchés" :value="$operations->getCollection()->pluck('client_id')->filter()->unique()->count().($operations->hasPages() ? '+' : '')" icon="users"/>
            <x-stat label="Dernière connexion" :value="$x->derniere_connexion_at?->format('d/m H:i') ?? '—'" icon="clock"/>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card self-start xl:col-span-2">
                <div class="card-h"><h2 class="card-t">Journal des opérations</h2><span class="text-xs text-slate-400">ajouts, modifications, suppressions, validations, exports, connexions</span></div>
                <div class="overflow-x-auto"><table class="tbl">
                    <thead><tr><th>Date</th><th>Opération</th><th>Dossier</th><th>Adresse IP</th></tr></thead>
                    <tbody>
                    @forelse($operations as $o)
                        <tr>
                            <td class="whitespace-nowrap text-xs">{{ $o->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="text-sm">{{ $o->libelle }} @if($o->statut && $o->statut >= 400)<span class="badge-red" title="Code {{ $o->statut }}">refusée</span>@endif
                                @if(str_starts_with($o->action, 'auth.') && $o->action !== 'auth.connexion' && $o->action !== 'auth.deconnexion')<span class="badge-amber">alerte</span>@endif</td>
                            <td class="text-xs">{{ $o->client?->nom ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ $o->ip }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty icon="list" title="Aucune opération sur la période"/></td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                @if($operations->hasPages())<div class="p-4">{{ $operations->links() }}</div>@endif
            </div>

            <div class="space-y-6">
                <div class="card">
                    <div class="card-h"><h2 class="card-t">Par module</h2></div>
                    @forelse($parModule->sortDesc() as $m => $n)
                        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-2 text-sm last:border-0 dark:border-slate-800"><span>{{ Droits::libelleModule($m) }}</span><span class="font-mono">{{ $n }}</span></div>
                    @empty <p class="px-5 py-4 text-sm text-slate-500">Aucune opération.</p> @endforelse
                </div>
                <div class="card">
                    <div class="card-h"><h2 class="card-t">Détail des actions</h2></div>
                    @forelse($activites as $a)
                        <div class="border-b border-slate-100 px-5 py-2 text-sm last:border-0 dark:border-slate-800">{{ $a->action }}
                            <div class="text-xs text-slate-500">{{ $a->created_at->format('d/m/Y H:i') }}{{ $a->client ? ' · '.$a->client->nom : '' }}</div></div>
                    @empty <p class="px-5 py-4 text-sm text-slate-500">Aucune action détaillée sur la période.</p> @endforelse
                </div>
            </div>
        </div>
    @endif
</x-layouts.app>
