{{-- Échéancier clients et fournisseurs : une ligne par tiers, montants par tranche d'échéance ; pièces détaillées en option. $lien = false à l'impression. --}}
@php
    $lien ??= true;
    $tr = \App\Services\Echeancier::TRANCHES;
    $nb = count($tr) + 4;
@endphp
<div class="card overflow-x-auto print-area">
    <table class="tbl min-w-[1000px] text-sm">
        <thead><tr><th>Tiers</th>@if($detail)<th>Échéance</th>@endif @foreach($tr as $k => $l)<th class="text-right {{ str_starts_with($k, 'echu') ? 'text-rose-700' : '' }}">{{ $l }}</th>@endforeach<th class="text-right">Non affecté</th><th class="text-right">Total</th></tr></thead>
        @forelse($ECH['sections'] as $s)
            <tbody>
                <tr class="bg-slate-100 dark:bg-slate-800/60"><td colspan="{{ $nb + ($detail ? 1 : 0) }}" class="font-semibold uppercase">{{ $s['titre'] }} <span class="text-xs font-normal normal-case text-slate-500">· délai de paiement {{ $s['delai'] }} j</span></td></tr>
                @foreach($s['tiers'] as $t)
                    @if($detail)
                        <tr class="bg-slate-50 dark:bg-slate-800/30"><td colspan="{{ $nb + 1 }}" class="font-semibold"><span class="font-mono">{{ $t['compte'] }}</span> {{ $t['libelle'] }}</td></tr>
                        @foreach($t['pieces'] as $p)
                            <tr><td class="pl-6">{{ dfr($p['date']) }} · @if($lien)<a href="{{ route('compta.ecritures.show', [$p['ecriture_id'], 'retour' => request()->fullUrl()]) }}" class="hover:underline">{{ $p['libelle'] }}</a>@else{{ $p['libelle'] }}@endif</td>
                                <td>{{ $p['echeance'] ? dfr($p['echeance']) : '' }}@if($p['retard'])<span class="ml-1 text-xs text-rose-700">+{{ $p['retard'] }} j</span>@endif</td>
                                @foreach(array_keys($tr) as $k)<td class="num">{{ $p['tranche'] === $k ? n3($p['montant']) : '' }}</td>@endforeach
                                <td class="num">{{ $p['tranche'] === 'non_affecte' ? n3($p['montant']) : '' }}</td><td class="num">{{ n3($p['montant']) }}</td></tr>
                        @endforeach
                    @endif
                    <tr class="{{ $detail ? 'border-b-2 border-slate-200 font-medium dark:border-slate-700' : '' }}">
                        <td>@if($detail)<span class="pl-6">Total {{ $t['compte'] }}</span>@else<span class="font-mono text-xs">{{ $t['compte'] }}</span> {{ $t['libelle'] }}@endif</td>@if($detail)<td></td>@endif
                        @foreach(array_keys($tr) as $k)<td class="num {{ str_starts_with($k, 'echu') && $t[$k] > 0 ? 'text-rose-700' : '' }}">{{ n3($t[$k]) }}</td>@endforeach
                        <td class="num">{{ n3($t['non_affecte']) }}</td><td class="num font-semibold">{{ n3($t['total']) }}</td></tr>
                @endforeach
                <tr class="border-t-2 border-slate-300 font-semibold"><td>Total {{ mb_strtolower($s['titre']) }}</td>@if($detail)<td></td>@endif
                    @foreach(array_keys($tr) as $k)<td class="num">{{ tnd($s['T'][$k], false) }}</td>@endforeach<td class="num">{{ tnd($s['T']['non_affecte'], false) }}</td><td class="num">{{ tnd($s['T']['total'], false) }}</td></tr>
            </tbody>
        @empty
            <tbody><tr><td colspan="{{ $nb + 1 }}"><x-empty icon="check" title="Aucune pièce en attente de règlement au {{ dfr($dateEch) }}">Les pièces des comptes de tiers sont toutes lettrées.</x-empty></td></tr></tbody>
        @endforelse
    </table>
    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Pièces non lettrées au {{ dfr($dateEch) }}, dans le sens du compte : ce que doivent les clients, ce qui est dû aux fournisseurs et au personnel. « Non affecté » : règlements, avances ou avoirs non encore lettrés avec une pièce.</p>
</div>
