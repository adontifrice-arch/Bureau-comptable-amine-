{{-- Éditions des journaux : auxiliaire, centralisé, par lot, centralisation globale. $lien = false à l'impression. --}}
@php
    $lien ??= true;
    $E = $EJ;
    $vide = fn ($t) => '<tbody><tr><td colspan="7" class="py-8 text-center text-sm text-slate-400">'.e($t).'</td></tr></tbody>';
@endphp

@if(in_array($vueJ, ['auxiliaire', 'lots'], true))
    {{-- Écritures détaillées, regroupées par journal (auxiliaire) ou par lot de saisie --}}
    @php
        $groupes = $vueJ === 'lots'
            ? array_map(fn ($l) => ['titre' => 'Lot n° '.$l['numero'].' — '.$l['titre'], 'sous' => count($l['ecritures']).' écriture(s) · journal '.implode(', ', $l['journaux']).' · saisi le '.$l['saisi']?->format('d/m/Y à H:i'), 'ecritures' => $l['ecritures'], 'T' => $l['T']], $E['lots'])
            : array_map(fn ($j) => ['titre' => $j['code'].' — '.$j['libelle'], 'sous' => count($j['ecritures']).' écriture(s)', 'ecritures' => $j['ecritures'], 'T' => $j['T']], $E['journaux']);
    @endphp
    <div class="card overflow-x-auto print-area">
        <table class="tbl min-w-[860px]">
            <thead><tr><th class="w-24">Date</th><th class="w-14">Jnl</th><th class="w-28">Compte</th><th>Libellé</th><th class="w-32 text-right">Débit</th><th class="w-32 text-right">Crédit</th></tr></thead>
            @forelse($groupes as $g)
                <tbody>
                    <tr class="bg-slate-100 dark:bg-slate-800/60"><td colspan="6"><span class="font-semibold">{{ $g['titre'] }}</span> <span class="ml-2 text-xs text-slate-500">{{ $g['sous'] }}</span></td></tr>
                    @foreach($g['ecritures'] as $e)
                        @php $fiche = $lien ? route('compta.ecritures.show', [$e, 'retour' => request()->fullUrl()]) : null; @endphp
                        @foreach($e->lignes as $i => $l)
                            <tr @class(['border-t border-slate-200 dark:border-slate-700' => $i === 0, 'cursor-pointer hover:bg-brand-50/60 dark:hover:bg-slate-800/40' => $lien]) @if($lien) ondblclick="window.location = {{ \Illuminate\Support\Js::from($fiche) }}" @endif>
                                <td>{{ $i === 0 ? dfr($e->date) : '' }}</td><td>{{ $i === 0 ? $e->journal : '' }}</td><td class="font-mono text-xs">{{ $l->compte }}</td>
                                <td class="{{ $l->credit > 0 ? 'pl-8' : '' }}">@if($i === 0)@if($lien)<a href="{{ $fiche }}" class="font-medium hover:underline">{{ $e->libelle }}</a>@else<span class="font-medium">{{ $e->libelle }}</span>@endif<span class="text-slate-400"> · </span>@endif<span class="text-slate-500">{{ \App\Services\Compta::libelle($l->compte) }}</span></td>
                                <td class="num">{{ n3($l->debit) }}</td><td class="num">{{ n3($l->credit) }}</td></tr>
                        @endforeach
                    @endforeach
                    <tr class="border-t-2 border-slate-300 font-semibold"><td colspan="4" class="text-right">Total {{ $vueJ === 'lots' ? 'du lot' : 'du journal' }}</td><td class="num">{{ tnd($g['T']['debit'], false) }}</td><td class="num">{{ tnd($g['T']['credit'], false) }}</td></tr>
                </tbody>
            @empty {!! $vide('Aucune écriture sur la période') !!} @endforelse
            @if($groupes)<tfoot class="border-t-2 border-slate-400"><tr><td colspan="4">Total général</td><td class="num">{{ tnd($E['T']['debit'], false) }}</td><td class="num">{{ tnd($E['T']['credit'], false) }}</td></tr></tfoot>@endif
        </table>
        @if($vueJ === 'lots')<p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Un lot rassemble les écritures passées ensemble : un relevé bancaire importé, une paie, les dotations d’un exercice, la reprise de la balance, les factures validées un même jour, les saisies manuelles d’un même jour par une même personne.</p>@endif
    </div>

@elseif($vueJ === 'centralise')
    <div class="card overflow-x-auto print-area">
        <table class="tbl min-w-[760px]">
            <thead><tr><th class="w-28">Compte</th><th>Libellé</th><th class="w-36 text-right">Débit</th><th class="w-36 text-right">Crédit</th></tr></thead>
            @forelse($E['journaux'] as $j)
                <tbody>
                    <tr class="bg-slate-100 dark:bg-slate-800/60"><td colspan="4" class="font-semibold">{{ $j['code'] }} — {{ $j['libelle'] }}</td></tr>
                    @foreach($j['mois'] as $m)
                        <tr class="bg-slate-50 dark:bg-slate-800/30"><td colspan="4" class="text-sm font-medium">{{ ucfirst(mois_fr($m['mois'])) }}</td></tr>
                        @foreach($m['comptes'] as $c)
                            <tr><td class="font-mono text-xs">{{ $c['compte'] }}</td><td>{{ $c['libelle'] }}</td><td class="num">{{ n3($c['debit']) }}</td><td class="num">{{ n3($c['credit']) }}</td></tr>
                        @endforeach
                        <tr class="font-medium"><td colspan="2" class="text-right">Total {{ mois_fr($m['mois']) }}</td><td class="num">{{ tnd($m['T']['debit'], false) }}</td><td class="num">{{ tnd($m['T']['credit'], false) }}</td></tr>
                    @endforeach
                    <tr class="border-t-2 border-slate-300 font-semibold"><td colspan="2" class="text-right">Total {{ $j['code'] }} — {{ $j['libelle'] }}</td><td class="num">{{ tnd($j['T']['debit'], false) }}</td><td class="num">{{ tnd($j['T']['credit'], false) }}</td></tr>
                </tbody>
            @empty {!! $vide('Aucune écriture sur la période') !!} @endforelse
            @if($E['journaux'])<tfoot class="border-t-2 border-slate-400"><tr><td colspan="2">Total général</td><td class="num">{{ tnd($E['T']['debit'], false) }}</td><td class="num">{{ tnd($E['T']['credit'], false) }}</td></tr></tfoot>@endif
        </table>
        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Chaque journal est centralisé par mois : une ligne par compte, avec le total des débits et des crédits du mois.</p>
    </div>

@elseif($vueJ === 'global')
    <div class="card overflow-x-auto print-area">
        <table class="tbl min-w-[760px]">
            <thead><tr><th>Mois</th><th>Journal</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Cumul débit</th><th class="text-right">Cumul crédit</th></tr></thead>
            @forelse($E['mois'] as $m)
                <tbody>
                    @foreach($m['journaux'] as $code => $t)
                        <tr><td>{{ $loop->first ? ucfirst(mois_fr($m['mois'])) : '' }}</td><td><span class="font-mono text-xs">{{ $code }}</span> {{ $E['noms'][$code] ?? $code }}</td><td class="num">{{ n3($t['debit']) }}</td><td class="num">{{ n3($t['credit']) }}</td><td></td><td></td></tr>
                    @endforeach
                    <tr class="border-b-2 border-slate-200 font-semibold dark:border-slate-700"><td></td><td class="text-right">Total {{ mois_fr($m['mois']) }}</td><td class="num">{{ tnd($m['T']['debit'], false) }}</td><td class="num">{{ tnd($m['T']['credit'], false) }}</td><td class="num">{{ tnd($m['cumul']['debit'], false) }}</td><td class="num">{{ tnd($m['cumul']['credit'], false) }}</td></tr>
                </tbody>
            @empty {!! $vide('Aucune écriture sur la période') !!} @endforelse
            @if($E['mois'])
                <tbody class="border-t-2 border-slate-400">
                    <tr class="bg-slate-50 dark:bg-slate-800/30"><td colspan="6" class="font-semibold">Récapitulation par journal</td></tr>
                    @foreach($E['journaux'] as $code => $t)<tr><td></td><td><span class="font-mono text-xs">{{ $code }}</span> {{ $E['noms'][$code] ?? $code }}</td><td class="num">{{ tnd($t['debit'], false) }}</td><td class="num">{{ tnd($t['credit'], false) }}</td><td></td><td></td></tr>@endforeach
                </tbody>
                <tfoot class="border-t-2 border-slate-400"><tr><td colspan="2">Total général</td><td class="num">{{ tnd($E['T']['debit'], false) }}</td><td class="num">{{ tnd($E['T']['credit'], false) }}</td><td colspan="2"><span class="{{ abs($E['T']['debit'] - $E['T']['credit']) < 0.001 ? 'badge-green' : 'badge-red' }}">{{ abs($E['T']['debit'] - $E['T']['credit']) < 0.001 ? 'Équilibré' : 'Déséquilibré' }}</span></td></tr></tfoot>
            @endif
        </table>
        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Le total général des journaux est égal au total des mouvements de la balance de la même période.</p>
    </div>
@endif
