{{-- Grand livre auxiliaire : par compte de tiers, report au 1er janvier, mouvements et solde progressif. $lien = false à l'impression. --}}
@php
    $lien ??= true;
    $sens = fn ($v) => abs($v) < 0.0005 ? '' : ($v > 0 ? ' D' : ' C');
@endphp
<div class="card overflow-x-auto print-area">
    <table class="tbl min-w-[860px]">
        <thead><tr><th class="w-24">Date</th><th class="w-14">Jnl</th><th>Libellé</th><th class="w-32 text-right">Débit</th><th class="w-32 text-right">Crédit</th><th class="w-36 text-right">Solde</th></tr></thead>
        @forelse($GLA['sections'] as $s)
            <tbody>
                <tr class="bg-slate-100 dark:bg-slate-800/60"><td colspan="6" class="font-semibold uppercase">{{ $s['titre'] }}</td></tr>
                @foreach($s['comptes'] as $c)
                    <tr class="bg-slate-50 dark:bg-slate-800/30"><td colspan="6" class="font-semibold"><span class="font-mono">{{ $c['compte'] }}</span> {{ $c['libelle'] }}</td></tr>
                    <tr class="text-slate-500"><td>01/01/{{ $annee }}</td><td></td><td class="italic">Report à nouveau</td><td></td><td></td><td class="num">{{ tnd(abs($c['report']), false) }}{{ $sens($c['report']) }}</td></tr>
                    @foreach($c['mouvements'] as $m)
                        @if($lien)
                            @php $fiche = route('compta.ecritures.show', [$m['ecriture_id'], 'retour' => request()->fullUrl()]); @endphp
                            <tr class="cursor-pointer hover:bg-brand-50/60 dark:hover:bg-slate-800/40" ondblclick="window.location = {{ \Illuminate\Support\Js::from($fiche) }}" title="Double-clic : ouvrir l’écriture">
                                <td>{{ dfr($m['date']) }}</td><td>{{ $m['journal'] }}</td><td><a href="{{ $fiche }}" class="hover:underline">{{ $m['libelle'] }}</a></td>
                        @else
                            <tr><td>{{ dfr($m['date']) }}</td><td>{{ $m['journal'] }}</td><td>{{ $m['libelle'] }}</td>
                        @endif
                            <td class="num">{{ n3($m['debit']) }}</td><td class="num">{{ n3($m['credit']) }}</td><td class="num">{{ tnd(abs($m['solde']), false) }}{{ $sens($m['solde']) }}</td></tr>
                    @endforeach
                    <tr class="border-b-2 border-slate-200 font-medium dark:border-slate-700"><td colspan="3" class="text-right">Total {{ $c['compte'] }}</td><td class="num">{{ tnd($c['debit'], false) }}</td><td class="num">{{ tnd($c['credit'], false) }}</td><td class="num">{{ tnd(abs($c['solde']), false) }}{{ $sens($c['solde']) }}</td></tr>
                @endforeach
                <tr class="font-semibold"><td colspan="3" class="text-right">Total {{ mb_strtolower($s['titre']) }}</td><td class="num">{{ tnd($s['T']['debit'], false) }}</td><td class="num">{{ tnd($s['T']['credit'], false) }}</td><td class="num">{{ tnd(abs($s['T']['solde']), false) }}{{ $sens($s['T']['solde']) }}</td></tr>
            </tbody>
        @empty
            <tbody><tr><td colspan="6"><x-empty icon="users" title="Aucun compte de tiers mouvementé sur {{ $annee }}"/></td></tr></tbody>
        @endforelse
        @if($GLA['sections'])
            <tfoot class="border-t-2 border-slate-300"><tr><td colspan="3">Total général</td><td class="num">{{ tnd($GLA['T']['debit'], false) }}</td><td class="num">{{ tnd($GLA['T']['credit'], false) }}</td><td class="num">{{ tnd(abs($GLA['T']['solde']), false) }}{{ $sens($GLA['T']['solde']) }}</td></tr></tfoot>
        @endif
    </table>
    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Solde : D débiteur, C créditeur. Les mouvements de l’exercice s’ajoutent au report au 1er janvier.</p>
</div>
