<div class="card overflow-x-auto print-area">
    <table class="tbl min-w-[900px]">
        <thead>
            <tr><th rowspan="2">Compte</th><th rowspan="2">Libellé</th><th colspan="2" class="!text-center">Report à nouveau</th><th colspan="2" class="!text-center">Mouvements</th><th colspan="2" class="!text-center">Soldes</th></tr>
            <tr><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Débiteur</th><th class="text-right">Créditeur</th></tr>
        </thead>
        <tbody>
        @forelse($B as $b)
            <tr><td class="font-mono text-xs">{{ $b['compte'] }}</td><td>{{ $b['libelle'] }}</td>@foreach(['rd','rc','md','mc','sd','sc'] as $k)<td class="num">{{ n3($b[$k]) }}</td>@endforeach</tr>
        @empty <tr><td colspan="8"><x-empty icon="book" title="Aucune écriture comptabilisée sur {{ $annee }}"/></td></tr> @endforelse
        </tbody>
        @if(count($B))
        <tfoot class="border-t-2 border-slate-300"><tr><td colspan="2">Totaux</td>@foreach(['rd','rc','md','mc','sd','sc'] as $k)<td class="num">{{ tnd($T[$k], false) }}</td>@endforeach</tr></tfoot>
        @endif
    </table>
    @if(count($B))
        @php $pr = collect($B)->filter(fn($b) => $b['compte'][0] === '7')->sum(fn($b) => $b['mc'] - $b['md']); $ch = collect($B)->filter(fn($b) => $b['compte'][0] === '6')->sum(fn($b) => $b['md'] - $b['mc']);
             $ok = abs($T['md'] - $T['mc']) < .001 && abs($T['sd'] - $T['sc']) < .001; @endphp
        <div class="flex flex-wrap items-center gap-6 border-t border-slate-100 px-5 py-4 text-sm dark:border-slate-800">
            <span>Produits (classe 7) : <b class="font-mono">{{ tnd($pr) }}</b></span><span>Charges (classe 6) : <b class="font-mono">{{ tnd($ch) }}</b></span>
            <span>Résultat : <b class="font-mono {{ $pr - $ch >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ tnd($pr - $ch) }}</b> ({{ $pr - $ch >= 0 ? 'bénéfice' : 'perte' }})</span>
            <span class="ml-auto {{ $ok ? 'badge-green' : 'badge-red' }}">{{ $ok ? 'Balance équilibrée' : 'Balance déséquilibrée' }}</span>
        </div>
    @endif
</div>
