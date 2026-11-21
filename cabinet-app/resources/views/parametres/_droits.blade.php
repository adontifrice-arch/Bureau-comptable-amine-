{{-- Champs communs à la création et à la modification d'un utilisateur. $x : utilisateur (ou null), $clients, $dossiers (ids) --}}
@php
    $role = old('role', $x->role ?? 'collaborateur');
    $modulesTous = old('modules_tous', $x ? ($x->modules === null ? '1' : '') : '1');
    $modules = old('modules', $x?->modules ?? array_keys(\App\Support\Droits::MODULES));
    $dossiersTous = old('dossiers_tous', $x ? ($x->tous_dossiers ? '1' : '') : '1');
    $dossiersSel = array_map('intval', old('dossiers', $dossiers ?? []));
@endphp
<div x-data="{ role: @js($role), mt: @js((bool) $modulesTous), dt: @js((bool) $dossiersTous), q: '' }" class="space-y-5">
    <div class="grid gap-4 md:grid-cols-2">
        <x-field label="Nom et prénom" name="name" :value="$x->name ?? ''" required/>
        <x-field label="Adresse e-mail (identifiant de connexion)" name="email" type="email" :value="$x->email ?? ''" required autocomplete="off"/>
        <x-field label="Fonction" name="fonction" :value="$x->fonction ?? ''" placeholder="Ex. : comptable, assistant paie, auditeur…"/>
        <div><label class="label" for="f-role">Rôle</label>
            <select id="f-role" name="role" class="input" x-model="role">
                <option value="collaborateur">Collaborateur (droits ci-dessous)</option>
                <option value="chef">Chef comptable (tous les droits, gère les paramètres)</option>
            </select>
        </div>
    </div>

    <template x-if="role === 'chef'">
        <p class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">Un chef comptable voit tous les modules et tous les dossiers, peut travailler à distance et gère les paramètres du cabinet.</p>
    </template>

    <div x-show="role !== 'chef'" class="space-y-5">
        <div>
            <div class="mb-2 flex items-center justify-between"><span class="label !mb-0">Modules visibles</span>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="modules_tous" value="1" x-model="mt" class="rounded border-slate-300 text-brand-600"> Tous les modules</label></div>
            <div class="grid gap-2 sm:grid-cols-3" x-show="!mt">
                @foreach(\App\Support\Droits::MODULES as $cle => [$lib])
                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700"><input type="checkbox" name="modules[]" value="{{ $cle }}" @checked(in_array($cle, $modules, true)) class="rounded border-slate-300 text-brand-600"> {{ $lib }}</label>
                @endforeach
            </div>
            @error('modules')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between"><span class="label !mb-0">Dossiers à saisir</span>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="dossiers_tous" value="1" x-model="dt" class="rounded border-slate-300 text-brand-600"> Tous les dossiers</label></div>
            <div x-show="!dt">
                <input type="search" x-model="q" placeholder="Filtrer les clients…" class="input mb-2">
                <div class="grid max-h-56 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-2 dark:border-slate-700">
                    @forelse($clients as $c)
                        <label class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50 dark:hover:bg-slate-800" x-show="!q || @js(mb_strtolower($c->nom)).includes(q.toLowerCase())">
                            <input type="checkbox" name="dossiers[]" value="{{ $c->id }}" @checked(in_array($c->id, $dossiersSel, true)) class="rounded border-slate-300 text-brand-600"> {{ $c->nom }}</label>
                    @empty <p class="px-2 py-1 text-sm text-slate-500">Aucun client.</p> @endforelse
                </div>
                <p class="mt-1 text-xs text-slate-400">L’utilisateur ne voit et ne saisit que ces dossiers (comptabilité, paie, factures, documents…). Un client qu’il crée lui est ajouté.</p>
            </div>
        </div>

        <div class="grid gap-2 sm:grid-cols-3">
            <label class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                <input type="hidden" name="droit_validation" value="0">
                <input type="checkbox" name="droit_validation" value="1" @checked(old('droit_validation', $x ? (int) ($x->droit_validation ?? 1) : 1)) class="mt-0.5 rounded border-slate-300 text-brand-600">
                <span><span class="font-medium">Saisir et valider les écritures</span><span class="block text-xs text-slate-500">OD, factures reçues et de vente, paies, banque, immobilisations.</span></span></label>
            <label class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                <input type="hidden" name="acces_distant" value="0">
                <input type="checkbox" name="acces_distant" value="1" @checked(old('acces_distant', $x ? (int) $x->acces_distant : 1)) class="mt-0.5 rounded border-slate-300 text-brand-600">
                <span><span class="font-medium">Accès à distance</span><span class="block text-xs text-slate-500">Décoché : connexion seulement depuis le réseau du cabinet.</span></span></label>
            <label class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                <input type="hidden" name="droit_ecritures" value="0">
                <input type="checkbox" name="droit_ecritures" value="1" @checked(old('droit_ecritures', $x ? (int) $x->droit_ecritures : 0)) class="mt-0.5 rounded border-slate-300 text-brand-600">
                <span><span class="font-medium">Modifier / supprimer les écritures</span><span class="block text-xs text-slate-500">Écritures comptables déjà passées.</span></span></label>
        </div>
    </div>
</div>
