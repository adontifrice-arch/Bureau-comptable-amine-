{{-- Onglet Grand livre : général (avec lettrage du compte), auxiliaire (tiers), lettrages invalides ; transfert de compte à compte. --}}
@php $peutModifier = \App\Services\Ecritures::autorise(auth()->user()); @endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <div class="flex flex-wrap gap-1">
        @foreach(['general' => 'Grand livre général', 'auxiliaire' => 'Grand livre auxiliaire', 'invalides' => 'Lettrage invalide', 'nonlettres' => 'Comptes non lettrés'] as $k => $l)
            <a href="{{ $u('gl', $k === 'general' ? [] : ['vue' => $k]) }}" class="{{ $vueGl === $k ? 'badge-blue' : 'badge-gray' }} !py-1">{{ $l }}@if($k === 'invalides' && $invalides->count()) <span class="badge-red !py-0">{{ $invalides->count() }}</span>@endif</a>
        @endforeach
    </div>
    @if($peutModifier)<button class="btn-secondary" x-data @click="$dispatch('open-modal', 'transfert')"><x-icon name="copy" class="h-4 w-4"/> Transfert de compte à compte</button>@endif
</div>

@if($vueGl === 'auxiliaire')
    @php $pg = array_filter(['annee' => $annee, 'section' => $section, 'compte' => $compte]); @endphp
    <form class="card mb-4 flex flex-wrap items-end gap-3 p-4"><input type="hidden" name="tab" value="gl"><input type="hidden" name="vue" value="auxiliaire"><input type="hidden" name="annee" value="{{ $annee }}">
        <div><label class="label" for="gla-s">Tiers</label><select id="gla-s" name="section" class="input">
            <option value="">Tous (40, 41, 42)</option>@foreach(\App\Services\Balances::AUXILIAIRES as $k => $l)<option value="{{ $k }}" @selected((string) $section === (string) $k)>{{ $k }} — {{ $l }}</option>@endforeach</select></div>
        <div class="w-72"><label class="label" for="gla-c">Compte de tiers (ou début, ex. 411)</label><input id="gla-c" name="compte" value="{{ $compte }}" class="input font-mono" list="cpts-gla" autocomplete="off">
            <datalist id="cpts-gla" data-plan>@foreach($plan as $n => $l)@if(preg_match('/^4[012]/', (string) $n))<option value="{{ $n }}">{{ $l }}</option>@endif @endforeach</datalist></div>
        <button class="btn-primary">Afficher</button>
        <div class="ml-auto flex gap-2">
            <a href="{{ route('compta.grand-livre.csv', $pg) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel (CSV)</a>
            <a href="{{ route('compta.grand-livre.print', $pg) }}" target="_blank" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</a>
        </div>
    </form>
    @include('compta._grand-livre-aux')

@elseif($vueGl === 'nonlettres')
    @php $pn = array_filter(['annee' => $annee, 'date' => $dateNL, 'section' => $section, 'compte' => $compte]); @endphp
    <form class="card mb-4 flex flex-wrap items-end gap-3 p-4"><input type="hidden" name="tab" value="gl"><input type="hidden" name="vue" value="nonlettres"><input type="hidden" name="annee" value="{{ $annee }}">
        <div><label class="label" for="nl-d">Arrêté au</label><input id="nl-d" type="date" name="date" value="{{ $dateNL }}" class="input"></div>
        <div><label class="label" for="nl-s">Tiers</label><select id="nl-s" name="section" class="input">
            <option value="">Tous (40, 41, 42)</option>@foreach(\App\Services\Balances::AUXILIAIRES as $k => $l)<option value="{{ $k }}" @selected((string) $section === (string) $k)>{{ $k }} — {{ $l }}</option>@endforeach</select></div>
        <div class="w-64"><label class="label" for="nl-c">Compte (ou début, ex. 411)</label><input id="nl-c" name="compte" value="{{ $compte }}" class="input font-mono" list="cpts-nl" autocomplete="off">
            <datalist id="cpts-nl" data-plan>@foreach($plan as $n => $l)@if(preg_match('/^4[012]/', (string) $n))<option value="{{ $n }}">{{ $l }}</option>@endif @endforeach</datalist></div>
        <button class="btn-primary">Afficher</button>
        <div class="ml-auto flex gap-2">
            <a href="{{ route('compta.non-lettres.csv', $pn) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel (CSV)</a>
            <a href="{{ route('compta.non-lettres.print', $pn) }}" target="_blank" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</a>
        </div>
    </form>
    @include('compta._non-lettres')

@elseif($vueGl === 'invalides')
    <div class="card overflow-x-auto">
        <div class="card-h"><h2 class="card-t">Lettrages invalides</h2><span class="text-xs text-slate-400">Lettres dont les lignes ne sont plus soldées : ligne modifiée ou transférée, écriture supprimée, lettre restée seule</span></div>
        <table class="tbl">
            <thead><tr><th>Compte</th><th>Lettre</th><th>Lettré le</th><th class="text-right">Lignes</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Écart</th><th>Motif</th><th></th></tr></thead>
            <tbody>
            @forelse($invalides as $g)
                <tr>
                    <td class="font-mono text-xs"><a href="{{ $u('gl', ['compte' => $g['compte'], 'exact' => 1, 'lettrage' => 'oui']) }}" class="hover:underline">{{ $g['compte'] }}</a> <span class="text-slate-500">{{ \App\Services\Compta::libelle($g['compte']) }}</span></td>
                    <td><span class="badge-amber font-mono">{{ $g['lettre'] }}</span></td><td>{{ $g['lettre_le'] ? dfr($g['lettre_le']) : '' }}</td>
                    <td class="num">{{ $g['lignes'] }}</td><td class="num">{{ n3($g['debit']) }}</td><td class="num">{{ n3($g['credit']) }}</td><td class="num text-rose-700">{{ n3(abs($g['ecart'])) }}</td>
                    <td class="text-xs text-rose-700">{{ implode(' · ', $g['motifs']) }}</td>
                    <td class="text-right">@if($peutModifier)<form method="POST" action="{{ route('compta.lettrage.retirer') }}">@csrf<input type="hidden" name="compte" value="{{ $g['compte'] }}"><input type="hidden" name="lettre" value="{{ $g['lettre'] }}"><button class="btn-ghost btn-sm">Délettrer</button></form>@endif</td>
                </tr>
            @empty <tr><td colspan="9"><x-empty icon="check" title="Aucun lettrage invalide">Tous les lettrages du dossier sont soldés.</x-empty></td></tr> @endforelse
            </tbody>
        </table>
    </div>

@else
    <form class="card mb-4 flex flex-wrap items-end gap-3 p-4"><input type="hidden" name="tab" value="gl"><input type="hidden" name="annee" value="{{ $annee }}">
        <div class="w-72"><label class="label">Compte (ou racine, ex. 6)</label><input name="compte" value="{{ $compte }}" class="input" list="cpts2"><datalist id="cpts2" data-plan>@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist></div>
        @if($exact)<input type="hidden" name="exact" value="1">@endif
        <div><label class="label" for="gl-lt">Lignes</label><select id="gl-lt" name="lettrage" class="input"><option value="tous">Toutes</option><option value="non" @selected($filtreLettrage === 'non')>Non lettrées</option><option value="oui" @selected($filtreLettrage === 'oui')>Lettrées</option></select></div>
        <button class="btn-primary">Afficher</button>
        @if($compte)<p class="text-xs text-slate-500">{{ $exact ? 'Compte '.$compte.' seul' : 'Comptes commençant par '.$compte }} · double-cliquez sur une ligne pour ouvrir l’écriture (consulter, modifier, supprimer).@if($exact) <a href="{{ $u('gl', ['compte' => $compte]) }}" class="underline">Voir aussi ses sous-comptes</a>@else <a href="{{ $u('gl', ['compte' => $compte, 'exact' => 1]) }}" class="underline">Compte {{ $compte }} seul, pour le lettrer</a>@endif</p>@endif
    </form>
    @if($compte)
        @php $s = 0; $lettrable = $exact && $peutModifier; @endphp
        <form method="POST" action="{{ route('compta.lettrage') }}" x-data="{ sel: {}, get d() { return Object.values(this.sel).reduce((t, x) => t + x[0], 0) }, get c() { return Object.values(this.sel).reduce((t, x) => t + x[1], 0) }, get n() { return Object.keys(this.sel).length },
                basculer(id, d, c, on) { if (on) this.sel[id] = [d, c]; else delete this.sel[id] } }">@csrf
            @if($lettrable)
                <div class="mb-2 flex flex-wrap items-center gap-3 text-sm" x-show="n > 0" x-cloak>
                    <span><b x-text="n"></b> ligne(s) · débit <b class="font-mono" x-text="tnd(d)"></b> · crédit <b class="font-mono" x-text="tnd(c)"></b></span>
                    <span x-show="Math.abs(d - c) > 0.0005" class="badge-red">Écart <span x-text="tnd(Math.abs(d - c))"></span></span>
                    <button class="btn-primary btn-sm" :disabled="n < 2 || Math.abs(d - c) > 0.0005"><x-icon name="check" class="h-4 w-4"/> Lettrer la sélection</button>
                </div>
            @endif
            <div class="card overflow-x-auto"><table class="tbl"><thead><tr>@if($lettrable)<th class="w-8"></th>@endif<th>Date</th><th>Jnl</th><th>Compte</th><th>Libellé</th><th>Lettre</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Solde</th></tr></thead><tbody>
                @forelse($mouvements as $m) @php $s += $m->debit - $m->credit; $fiche = route('compta.ecritures.show', [$m->ecriture_id, 'retour' => request()->fullUrl()]); @endphp
                    <tr class="cursor-pointer hover:bg-brand-50/60 dark:hover:bg-slate-800/40" ondblclick="if (! event.target.closest('input, button, a')) window.location = {{ \Illuminate\Support\Js::from($fiche) }}" title="Double-clic : ouvrir l’écriture">
                        @if($lettrable)<td>@unless($m->lettre)<input type="checkbox" name="lignes[]" value="{{ $m->id }}" class="rounded border-slate-300 text-brand-600" @change="basculer({{ $m->id }}, {{ (float) $m->debit }}, {{ (float) $m->credit }}, $event.target.checked)" aria-label="Lettrer cette ligne">@endunless</td>@endif
                        <td>{{ dfr($m->date) }}</td><td>{{ $m->journal }}</td><td class="font-mono text-xs">{{ $m->compte }}</td><td><a href="{{ $fiche }}" class="hover:underline">{{ $m->libelle }}</a></td>
                        <td>@if($m->lettre)<span class="font-mono {{ $invalides->contains(fn ($g) => $g['compte'] === (string) $m->compte && $g['lettre'] === $m->lettre) ? 'badge-red' : 'badge-green' }}">{{ $m->lettre }}</span>
                            @if($exact && $peutModifier)<button type="submit" form="delettrer-{{ $m->lettre }}" class="ml-1 text-xs text-slate-400 hover:text-rose-600" title="Délettrer « {{ $m->lettre }} »">✕</button>@endif @endif</td>
                        <td class="num">{{ n3($m->debit) }}</td><td class="num">{{ n3($m->credit) }}</td><td class="num">{{ tnd($s, false) }}</td></tr>
                @empty <tr><td colspan="9"><x-empty title="Aucun mouvement"/></td></tr> @endforelse
            </tbody>
            @if($mouvements->count())<tfoot><tr><td colspan="{{ $lettrable ? 6 : 5 }}">Totaux</td><td class="num">{{ tnd($mouvements->sum('debit'), false) }}</td><td class="num">{{ tnd($mouvements->sum('credit'), false) }}</td><td class="num">{{ tnd($s, false) }}</td></tr></tfoot>@endif
            </table></div>
            @if($lettrable)<p class="mt-2 text-xs text-slate-500">Lettrage : cochez des lignes non lettrées dont les débits égalent les crédits (une facture et son règlement), puis « Lettrer la sélection ». ✕ retire une lettre.</p>@endif
        </form>
        @if($exact && $peutModifier)
            @foreach($mouvements->pluck('lettre')->filter()->unique() as $l)
                <form id="delettrer-{{ $l }}" method="POST" action="{{ route('compta.lettrage.retirer') }}" onsubmit="return confirm('Délettrer « {{ $l }} » sur le compte {{ $compte }} ?')">@csrf<input type="hidden" name="compte" value="{{ $compte }}"><input type="hidden" name="lettre" value="{{ $l }}"></form>
            @endforeach
        @endif
    @endif
@endif

@if($peutModifier)
    <x-modal name="transfert" title="Transfert de compte à compte">
        <form method="POST" action="{{ route('compta.transfert') }}" class="space-y-4" onsubmit="return confirm('Transférer les lignes du compte ' + this.source.value + ' vers le compte ' + this.destination.value + ' ?')">@csrf
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="label" for="tr-s">Du compte</label><input id="tr-s" name="source" value="{{ old('source', $vueGl === 'general' && ($exact ?? false) ? $compte : '') }}" class="input font-mono" list="cpts-tr" data-compte autocomplete="off" required><p data-compte-libelle class="mt-1 truncate text-xs text-slate-400"></p></div>
                <div><label class="label" for="tr-d">Vers le compte</label><input id="tr-d" name="destination" value="{{ old('destination') }}" class="input font-mono" list="cpts-tr" data-compte autocomplete="off" required><p data-compte-libelle class="mt-1 truncate text-xs text-slate-400"></p></div>
                <div><label class="label" for="tr-du">Du</label><input id="tr-du" type="date" name="du" value="{{ old('du', $annee.'-01-01') }}" class="input" required></div>
                <div><label class="label" for="tr-au">Au</label><input id="tr-au" type="date" name="au" value="{{ old('au', $annee.'-12-31') }}" class="input" required></div>
                <div class="md:col-span-2"><label class="label" for="tr-j">Journal</label><select id="tr-j" name="journal" class="input"><option value="">Tous les journaux</option>@foreach(\App\Models\Ecriture::journaux($client->id) as $k => $l)<option value="{{ $k }}">{{ $k }} — {{ $l }}</option>@endforeach</select></div>
            </div>
            <datalist id="cpts-tr" data-plan>@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>
            <p class="text-xs text-slate-500">Toutes les lignes du compte source sur la période (et le journal choisi) passent sur le compte de destination ; les écritures restent équilibrées. Les lignes transférées perdent leur lettre.</p>
            <div class="flex justify-end"><button class="btn-primary"><x-icon name="copy" class="h-4 w-4"/> Transférer</button></div>
        </form>
    </x-modal>
@endif
