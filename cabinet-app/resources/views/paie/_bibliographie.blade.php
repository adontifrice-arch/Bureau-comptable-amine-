{{--
    Paie → Bibliographie : réglementations et publications du site paie-tunisie.com (config/bibliographie.php).
    Les liens ouvrent les textes sur le site, dans un nouvel onglet ; la recherche porte sur toutes les rubriques.
--}}
@php
    $B = config('bibliographie');
    $site = rtrim($B['site'], '/');
    $sections = collect($B['groupes'])->flatMap(fn ($g, $gk) => collect($g['sections'])->map(fn ($s) => $s + ['groupe' => $g['titre']]));
    $cle = $sections->has(request('section')) ? request('section') : $sections->keys()->first();
    $tous = $sections->flatMap(fn ($s, $k) => collect($s['items'])->map(function ($l) use ($s, $k) { [$t, $u] = array_pad(explode('|', $l, 2), 2, ''); return ['t' => $t, 'u' => $u, 's' => $k, 'st' => $s['titre']]; }))->values();
    $lien = fn ($u) => str_starts_with($u, 'http') ? $u : $site.$u;
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Bibliographie — réglementation et publications de paie</h2>
        <p class="text-xs text-slate-500">Source : <a href="{{ $site }}" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">paie-tunisie.com</a> · relevé du {{ \Carbon\Carbon::parse($B['releve'])->format('d/m/Y') }} ·
            les textes s’ouvrent sur le site ; vérifiez toujours la version officielle (JORT) avant application.</p>
    </div>
</div>

<div x-data="{ q: '', items: @js($tous), lien: @js($site), get trouves() { const q = this.q.trim().toLowerCase(); return q.length < 2 ? [] : this.items.filter(i => (i.t + ' ' + i.st).toLowerCase().includes(q)).slice(0, 200) } }"
     class="grid gap-4 lg:grid-cols-[18rem_1fr]">
    <aside class="card card-b space-y-4 self-start text-sm">
        <input type="search" x-model="q" placeholder="Rechercher un texte…" class="input-sm w-full">
        @foreach($B['groupes'] as $gk => $g)
            <div>
                <a href="{{ $site.$g['url'] }}" target="_blank" rel="noopener noreferrer" class="mb-1 block text-xs font-semibold uppercase text-slate-400 hover:text-brand-600">{{ $g['titre'] }}</a>
                @foreach($g['sections'] as $sk => $s)
                    <a href="{{ route('paie.index', ['tab' => 'biblio', 'section' => $sk, 'mois' => $mois]) }}"
                       class="flex items-center justify-between rounded-md px-2 py-1 {{ $sk === $cle ? 'bg-brand-600 text-white' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800' }}">
                        <span>{{ $s['titre'] }}</span><span class="text-xs opacity-70">{{ count($s['items']) }}</span></a>
                @endforeach
            </div>
        @endforeach
    </aside>

    <div class="card">
        {{-- Résultats de recherche --}}
        <template x-if="q.trim().length >= 2">
            <div>
                <div class="card-h"><h3 class="card-t">Résultats pour « <span x-text="q"></span> »</h3><span class="text-xs text-slate-500" x-text="trouves.length + ' texte(s)'"></span></div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <template x-for="i in trouves" :key="i.s + i.u">
                        <li class="flex items-start justify-between gap-3 px-5 py-2 text-sm">
                            <a :href="i.u.startsWith('http') ? i.u : lien + i.u" target="_blank" rel="noopener noreferrer" class="text-slate-800 hover:text-brand-700 dark:text-slate-100" x-text="i.t"></a>
                            <span class="shrink-0 text-xs text-slate-400" x-text="i.st"></span>
                        </li>
                    </template>
                    <li x-show="!trouves.length" class="px-5 py-6 text-center text-sm text-slate-400">Aucun texte trouvé.</li>
                </ul>
            </div>
        </template>
        {{-- Rubrique choisie --}}
        @php $s = $sections[$cle]; @endphp
        <div x-show="q.trim().length < 2">
            <div class="card-h"><h3 class="card-t">{{ $s['groupe'] }} — {{ $s['titre'] }}</h3>
                <a href="{{ $site.$s['url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 hover:underline">Voir la rubrique sur le site ↗</a></div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach($s['items'] as $l)
                    @php [$t, $u] = array_pad(explode('|', $l, 2), 2, ''); @endphp
                    <li class="flex items-start gap-3 px-5 py-2 text-sm">
                        <x-icon :name="str_ends_with($u, '.pdf') ? 'download' : 'file-text'" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600"/>
                        <a href="{{ $lien($u) }}" target="_blank" rel="noopener noreferrer" class="text-slate-800 hover:text-brand-700 dark:text-slate-100">{{ $t }}</a>
                        @if(str_ends_with($u, '.pdf'))<span class="badge-gray ml-auto shrink-0">PDF</span>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
