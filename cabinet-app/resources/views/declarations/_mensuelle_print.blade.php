{{-- Impression de la déclaration mensuelle : rubriques renseignées par section, puis synthèse des impôts dus. --}}
@php $M = \App\Services\DeclarationMensuelle::class; $f = $M::convertir($d->champs ?? []); $C = $M::calcul($f); @endphp
<div class="mb-4 text-sm">Code de la déclaration : {{ $f['acte'] ?? '0' }} – {{ $M::ACTES[$f['acte'] ?? '0'] ?? '' }}</div>
@foreach($M::LIGNES as $s => $L)
    @php $R = collect($L)->map(fn ($def, $k) => $M::valeur($f, $k) + ['lib' => $def[0], 'mode' => $def[2], 'sens' => $def[3] ?? 'I'])->filter(fn ($v) => $v['b'] || $v['m']); $x = $C['sections'][$s]; @endphp
    @if($R->count() || $x['pen'])
        <h3 class="mb-1 mt-5 font-semibold">{{ $M::SECTIONS[$s] }}</h3>
        <table class="w-full text-xs"><thead><tr class="bg-slate-100 text-left"><th class="px-2 py-1">Rubrique</th><th class="px-2 py-1 text-right">Base</th><th class="px-2 py-1 text-right">Taux</th><th class="px-2 py-1 text-right">Montant</th></tr></thead><tbody>
            @foreach($R as $v)<tr class="border-b"><td class="px-2 py-1">{{ $v['lib'] }}{{ in_array($v['sens'], ['II', 'C'], true) ? ' (déduction)' : '' }}</td><td class="px-2 py-1 text-right font-mono">{{ $v['b'] ? tnd($v['b'], false) : '' }}</td>
                <td class="px-2 py-1 text-right">{{ in_array($v['mode'], ['bt', 'tm'], true) ? str_replace('.', ',', (string) $v['t']).' %' : ($v['mode'] === 'q' ? str_replace('.', ',', (string) $v['t']).' D' : '') }}</td><td class="px-2 py-1 text-right font-mono">{{ $v['mode'] === 'b' ? '' : tnd($v['m'], false) }}</td></tr>@endforeach
        </tbody><tfoot><tr class="font-semibold"><td class="px-2 py-1">{{ $s === 'tva' ? ($C['tvap'] >= 0 ? 'TVA à payer' : 'Crédit de TVA à reporter') : 'Montant dû' }}</td><td></td><td></td><td class="px-2 py-1 text-right font-mono">{{ tnd($s === 'tva' ? abs($C['tvap']) : $x['du'], false) }}</td></tr></tfoot></table>
    @endif
@endforeach
<h3 class="mb-1 mt-6 font-semibold">Synthèse des impôts et taxes à payer</h3>
<table class="w-full text-sm"><thead><tr class="bg-slate-100 text-left text-xs uppercase"><th class="px-3 py-2">Impôt ou taxe</th><th class="px-3 py-2 text-right">Principal</th><th class="px-3 py-2 text-right">Pénalités</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody>
    @foreach($C['sections'] as $s => $x)<tr class="border-b"><td class="px-3 py-1.5">{{ $M::SECTIONS[$s] }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($x['du'], false) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($x['pen'], false) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($x['du'] + $x['pen'], false) }}</td></tr>@endforeach
</tbody><tfoot><tr class="font-semibold"><td class="px-3 py-2">Total à payer</td><td></td><td></td><td class="px-3 py-2 text-right font-mono">{{ tnd($C['tot'], false) }}</td></tr></tfoot></table>
