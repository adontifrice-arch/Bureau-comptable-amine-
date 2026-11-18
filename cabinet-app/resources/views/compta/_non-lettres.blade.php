{{-- État des comptes non lettrés à une date : lignes non lettrées de chaque compte de tiers, totaux et solde non lettré. $lien = false à l'impression. --}}
@php
    $lien ??= true;
    $sens = fn ($v) => tnd(abs($v), false).(abs($v) < 0.0005 ? '' : ($v > 0 ? ' D' : ' C'));
@endphp
<div class="card overflow-x-auto print-area">
    <table class="tbl min-w-[820px]">
        <thead><tr><th class="w-24">Date</th><th class="w-14">Jnl</th><th>Libellé</th><th class="w-32 text-right">Débit</th><th class="w-32 text-right">Crédit</th><th class="w-36 text-right">Solde non lettré</th></tr></thead>
        @forelse($NL['sections'] as $s)
            <tbody>
                <tr class="bg-slate-100 dark:bg-slate-800/60"><td colspan="6" class="font-semibold uppercase">{{ $s['titre'] }}</td></tr>
                @foreach($s['comptes'] as $c)
                    <tr class="bg-slate-50 dark:bg-slate-800/30"><td colspan="6" class="font-semibold">
                        @if($lien)<a href="{{ route('compta.index', ['tab' => 'gl', 'annee' => substr($dateNL, 0, 4), 'compte' => $c['compte'], 'exact' => 1, 'lettrage' => 'non']) }}" class="hover:underline" title="Lettrer ce compte"><span class="font-mono">{{ $c['compte'] }}</span> {{ $c['libelle'] }}</a>
                        @else<span class="font-mono">{{ $c['compte'] }}</span> {{ $c['libelle'] }}@endif</td></tr>
                    @foreach($c['lignes'] as $l)
                        <tr><td>{{ dfr($l['date']) }}</td><td>{{ $l['journal'] }}</td>
                            <td>@if($lien)<a href="{{ route('compta.ecritures.show', [$l['ecriture_id'], 'retour' => request()->fullUrl()]) }}" class="hover:underline">{{ $l['libelle'] }}</a>@else{{ $l['libelle'] }}@endif</td>
                            <td class="num">{{ n3($l['debit']) }}</td><td class="num">{{ n3($l['credit']) }}</td><td></td></tr>
                    @endforeach
                    <tr class="border-b-2 border-slate-200 font-medium dark:border-slate-700"><td colspan="3" class="text-right">Total {{ $c['compte'] }}</td><td class="num">{{ tnd($c['debit'], false) }}</td><td class="num">{{ tnd($c['credit'], false) }}</td><td class="num">{{ $sens($c['solde']) }}</td></tr>
                @endforeach
                <tr class="font-semibold"><td colspan="3" class="text-right">Total {{ mb_strtolower($s['titre']) }}</td><td class="num">{{ tnd($s['T']['debit'], false) }}</td><td class="num">{{ tnd($s['T']['credit'], false) }}</td><td class="num">{{ $sens($s['T']['solde']) }}</td></tr>
            </tbody>
        @empty
            <tbody><tr><td colspan="6"><x-empty icon="check" title="Aucune ligne non lettrée au {{ dfr($dateNL) }}">Tous les comptes de tiers sont lettrés.</x-empty></td></tr></tbody>
        @endforelse
        @if($NL['sections'])
            <tfoot class="border-t-2 border-slate-300"><tr><td colspan="3">Total général</td><td class="num">{{ tnd($NL['T']['debit'], false) }}</td><td class="num">{{ tnd($NL['T']['credit'], false) }}</td><td class="num">{{ $sens($NL['T']['solde']) }}</td></tr></tfoot>
        @endif
    </table>
    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Lignes des comptes de tiers non lettrées au {{ dfr($dateNL) }} (une ligne lettrée après cette date y figure). Solde : D débiteur, C créditeur.</p>
</div>
