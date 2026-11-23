{{-- Menu Profil : profils du dossier (à gauche) et fiche profil avec ses rubriques par catégorie (à droite), comme l'écran « Profil ». --}}
@php
    $lock = $paie && $paie->statut !== 'brouillon';
    $F = $profil;
    $form = old('_form') === 'profil';
    $v = fn ($c) => old($c, $F->$c);
    $coche = fn ($c) => $form ? (bool) old($c) : (bool) $F->$c;
    $rubCochee = fn ($rb) => $form ? in_array((string) $rb->id, (array) old('rubriques', []), true)
        : ($F->exists ? $rb->pourProfil($F->nom) : $rb->profils === null);
    $nombre = fn ($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float) $x, 3, '.', ''), '0'), '.');
    $onglets = ['prime' => 'Primes', 'indemnite' => 'Indemnités', 'autre' => 'Autres', 'avantage' => 'Avantages en nature'];
    $taux = collect([$P['cnssP'], 17.07, 16.57, 8.5, 1, 0.5, 0, ...$profils->pluck('cnss_patronale')->filter(fn ($x) => $x !== null)->all()])->map(fn ($x) => $nombre($x))->unique()->values();
@endphp
<x-layouts.app title="Paie — Profils">
    <x-page-header title="Paie" :subtitle="$client->nom.' · profils de paie'">
        <x-client-picker :clients="$clients" :current="$client" :route="route('paie.profils')" :params="['mois' => $mois]"/>
    </x-page-header>
    @include('paie._barre')

    <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
        {{-- Profils du dossier --}}
        <div class="card self-start overflow-hidden">
            <div class="card-h"><h2 class="card-t">Profils</h2>
                <a href="{{ route('paie.profils', ['mois' => $mois]) }}" class="btn-ghost btn-sm"><x-icon name="plus" class="h-4 w-4"/> Nouveau</a></div>
            @forelse($profils as $p)
                @php $nb = $effectifs[mb_strtolower(trim($p->nom))] ?? 0; @endphp
                <a href="{{ route('paie.profils', ['p' => $p->id, 'mois' => $mois]) }}"
                   class="block border-t border-slate-100 px-4 py-2.5 text-sm dark:border-slate-800 {{ $p->id === $F->id ? 'bg-brand-50 dark:bg-brand-900/30' : 'hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                    <div class="flex items-center justify-between"><span class="font-medium {{ $p->id === $F->id ? 'text-brand-700 dark:text-brand-300' : '' }}">{{ $p->nom }}</span>
                        <span class="badge-gray" title="Employés ayant ce profil">{{ $nb }}</span></div>
                    <div class="text-xs text-slate-400">CNSS patronale {{ $p->cnss_patronale === null ? $nombre($P['cnssP']).' % (dossier)' : $nombre($p->cnss_patronale).' %' }} · code {{ $p->code_exploitant ?: '—' }}</div>
                </a>
            @empty <div class="border-t border-slate-100 p-4 text-sm text-slate-400 dark:border-slate-800">Aucun profil.</div> @endforelse
        </div>

        {{-- Fiche profil --}}
        <form method="POST" action="{{ $F->exists ? route('paie.profils.update', $F) : route('paie.profils.store') }}" class="card" x-data="{ t: 'prime', q: '' }">
            @csrf @if($F->exists) @method('PUT') @endif
            <input type="hidden" name="_form" value="profil"><input type="hidden" name="mois" value="{{ $mois }}">
            <div class="card-h"><h2 class="card-t">{{ $F->exists ? 'Profil '.$F->nom : 'Nouveau profil' }}</h2><span class="text-xs text-rose-600">* Mention obligatoire</span></div>
            <div class="card-b space-y-5">
                <div class="grid gap-5 md:grid-cols-[1fr_auto]">
                    <div class="grid gap-4 sm:grid-cols-[1fr_10rem_9rem]">
                        <x-field label="Nom du profil *" name="nom" :value="$F->nom" required maxlength="60"/>
                        <div><label class="label" for="f-cnss_patronale">CNSS Patronale *</label>
                            <div class="flex items-center gap-2"><input id="f-cnss_patronale" type="number" step="0.001" min="0" max="100" name="cnss_patronale" list="taux-cnss-p" value="{{ $nombre($v('cnss_patronale')) }}" placeholder="{{ $nombre($P['cnssP']) }}" class="input text-right"> %</div>
                            <datalist id="taux-cnss-p">@foreach($taux as $t)<option value="{{ $t }}">@endforeach</datalist>
                            <p class="mt-1 text-xs text-slate-400">Vide : taux du dossier ({{ $nombre($P['cnssP']) }} %)</p>
                            @error('cnss_patronale')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                        <x-field label="Code Exploitant" name="code_exploitant" :value="$F->code_exploitant" maxlength="10" class="input font-mono"/>
                    </div>
                    <div class="grid grid-cols-1 gap-x-6 gap-y-2 rounded-lg border border-slate-200 p-3 text-sm sm:grid-cols-2 dark:border-slate-700">
                        @foreach(['soumis_tfp_fop', 'contrat_dignite', 'decl_cnss', 'contrat_exo2ans', 'decl_employeur', 'regul_karama', 'base_calcul', 'exonere_irpp'] as $c)
                            <label class="flex items-center gap-2"><input type="checkbox" name="{{ $c }}" value="1" @checked($coche($c)) class="rounded border-slate-300">
                                {{ \App\Models\PaieProfil::OPTIONS[$c] }}@if($c === 'soumis_tfp_fop')<span class="text-rose-600">*</span>@endif</label>
                        @endforeach
                    </div>
                </div>

                {{-- Rubriques du profil --}}
                <fieldset class="rounded-lg border border-slate-200 dark:border-slate-700">
                    <legend class="ml-3 px-1 text-xs font-semibold text-slate-500">Rubriques</legend>
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 pb-2 dark:border-slate-700">
                        <div class="flex gap-1 text-sm">
                            @foreach($onglets as $c => $l)
                                <button type="button" @click="t = '{{ $c }}'" :class="t === '{{ $c }}' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'" class="rounded-md px-3 py-1">
                                    {{ $l }} <span class="text-xs opacity-70">({{ $rubriques->where('categorie', $c)->count() }})</span></button>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="search" x-model="q" placeholder="Rechercher une rubrique…" class="input-sm w-56">
                            <button type="button" class="btn-ghost btn-sm" @click="$root.querySelectorAll(`[data-cat='${t}'] label:not([hidden]) input`).forEach(c => { if (c.offsetParent) c.checked = true })">Tout cocher</button>
                            <button type="button" class="btn-ghost btn-sm" @click="$root.querySelectorAll(`[data-cat='${t}'] label input`).forEach(c => { if (c.offsetParent) c.checked = false })">Tout décocher</button>
                        </div>
                    </div>
                    @foreach($onglets as $c => $l)
                        <div x-show="t === '{{ $c }}'" @if($c !== 'prime') x-cloak @endif data-cat="{{ $c }}" class="grid max-h-72 gap-x-6 gap-y-1 overflow-y-auto p-3 text-sm sm:grid-cols-2">
                            @forelse($rubriques->where('categorie', $c) as $rb)
                                <label class="flex items-center gap-2" x-show="!q || @js(mb_strtolower($rb->nom.' '.$rb->code)).includes(q.toLowerCase())">
                                    <input type="checkbox" name="rubriques[]" value="{{ $rb->id }}" @checked($rubCochee($rb)) class="rounded border-slate-300">
                                    <span class="font-mono text-xs text-slate-400">{{ $rb->code }}</span> {{ $rb->nom }}</label>
                            @empty <p class="text-slate-400">Aucune rubrique dans cette catégorie.</p> @endforelse
                        </div>
                    @endforeach
                    <p class="border-t border-slate-100 px-3 py-2 text-xs text-slate-400 dark:border-slate-800">Les employés du profil reçoivent uniquement les rubriques cochées. Une nouvelle rubrique s’applique d’office à tous les profils.</p>
                </fieldset>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 p-4 dark:border-slate-800">
                <a href="{{ route('paie.rubriques', ['mois' => $mois]) }}" class="btn-secondary"><x-icon name="plus" class="h-4 w-4"/> Rubrique</a>
                <div class="flex gap-2">
                    @if($F->exists)<button type="submit" form="suppr-profil" class="btn-secondary text-rose-600"><x-icon name="x" class="h-4 w-4"/> Supprimer</button>@endif
                    <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
                </div>
            </div>
        </form>
        @if($F->exists)
            <form id="suppr-profil" method="POST" action="{{ route('paie.profils.destroy', $F) }}" onsubmit="return confirm(@js('Supprimer le profil « '.$F->nom.' » ?'))" class="hidden">@csrf @method('DELETE')<input type="hidden" name="mois" value="{{ $mois }}"></form>
        @endif
    </div>
</x-layouts.app>
