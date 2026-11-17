{{-- Déclaration mensuelle des impôts (modèle DGI) : rubriques avec base, taux et montant ; synthèse des impôts dus. --}}
@php
    $M = \App\Services\DeclarationMensuelle::class;
    $f = $M::convertir($d->champs ?? []); $C = $M::calcul($f);
    $courantes = ['rs1', 'rs3', 'rs4a', 'rs4b', 'rs5', 'rs6', 'rs17a', 'rs17b', 'rs17d', 'rs18', 'tfp1', 'tfp2', 'tfp_d', 'fop', 'tva7', 'tva13', 'tva19', 'tvd_imm', 'tvd_eql', 'tvd_eqi', 'tvd_al', 'tvd_ai', 'tvd_r25', 'tva_prec', 'inf_exp', 'inf_vsusp', 'inf_caexo', 'tim4', 'tcl_loc'];
    $num = fn ($v) => $v ? number_format((float) $v, 3, '.', '') : '';
@endphp
<form method="POST" action="{{ route('declarations.save') }}" x-data="{ imp: @js($M::impots($f)) }">@include('declarations._hidden')<input type="hidden" name="imp_saisie" value="1">
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card card-b space-y-3 text-sm">
            <div><label class="label">Code de la déclaration</label><select name="acte" class="input max-w-xs" @disabled($lock)>@foreach($M::ACTES as $k => $l)<option value="{{ $k }}" @selected((string) ($f['acte'] ?? '0') === (string) $k)>{{ $k }} – {{ $l }}</option>@endforeach</select></div>
            <div><div class="label">Impôts et taxes concernés par cette déclaration</div>
                <div class="grid gap-x-4 gap-y-1.5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($M::IMPOTS as $k => [$fr, $ar])<label class="flex items-center gap-2"><input type="checkbox" name="imp[]" value="{{ $k }}" x-model="imp" class="rounded border-slate-300 text-brand-600" @disabled($lock)> {{ $fr }} <span class="text-xs text-slate-400" dir="rtl">{{ $ar }}</span></label>@endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500">Seules les sections cochées sont affichées ; leurs montants restent enregistrés si vous décochez une case.</p></div>
        </div>
        @foreach($M::LIGNES as $s => $L)
            @php $x = $C['sections'][$s]; @endphp
            <div class="card overflow-hidden" x-data="{ tout: false }" x-show="{{ $s === 'tcl' ? "imp.some(k => k.startsWith('tcl'))" : "imp.includes('$s')" }}" @if(! in_array($s, collect($M::impots($f))->map(fn ($k) => $M::IMPOTS[$k][2])->all(), true)) x-cloak @endif>
                <div class="card-h"><h2 class="card-t">{{ $M::SECTIONS[$s] }}</h2>
                    <div class="flex items-center gap-3 text-xs"><span class="font-mono">{{ $s === 'tva' ? ($C['tvap'] >= 0 ? 'à payer '.tnd($C['tvap']) : 'crédit '.tnd(-$C['tvap'])) : tnd($x['du']) }}</span>
                        <button type="button" class="text-brand-700 hover:underline" @click="tout = ! tout" x-text="tout ? 'Rubriques renseignées' : 'Toutes les rubriques'"></button></div></div>
                <table class="tbl text-xs"><thead><tr><th>Rubrique</th><th class="w-36 text-right">Base</th><th class="w-24 text-right">Taux</th><th class="w-36 text-right">Montant</th></tr></thead><tbody>
                @foreach($L as $k => $def)
                    @php [$lib, $t0, $mode] = $def; $sens = $def[3] ?? 'I'; $v = $M::valeur($f, $k); $saisi = isset($f[$k]['m']);
                         $rempli = $v['b'] || $v['m'] || $saisi; @endphp
                    <tr x-data="{ b: '{{ $num($f[$k]['b'] ?? '') }}', t: '{{ $f[$k]['t'] ?? $t0 }}' }" @unless($rempli || in_array($k, $courantes, true)) x-show="tout" x-cloak @endunless class="{{ in_array($sens, ['II', 'C'], true) ? 'bg-slate-50/60 dark:bg-slate-800/30' : '' }}">
                        <td>{{ $lib }}@if(in_array($sens, ['II', 'C'], true)) <span class="badge-gray">déduction</span>@endif</td>
                        <td class="text-right">@if($mode !== 'm')<input name="f[{{ $k }}][b]" x-model="b" class="input !py-1 text-right font-mono" inputmode="decimal" @disabled($lock) placeholder="{{ $mode === 'q' ? 'quantité' : '' }}">@endif</td>
                        <td class="text-right">@if(in_array($mode, ['bt', 'tm', 'q'], true))<input name="f[{{ $k }}][t]" x-model="t" class="input !py-1 text-right font-mono" inputmode="decimal" @disabled($lock)>@endif</td>
                        <td class="text-right">@if($mode !== 'b')<input name="f[{{ $k }}][m]" value="{{ $saisi ? $num($f[$k]['m']) : '' }}" class="input !py-1 text-right font-mono {{ $saisi ? 'border-amber-300' : '' }}" inputmode="decimal" @disabled($lock)
                            @if(in_array($mode, ['bt', 'tm', 'q'], true)) :placeholder="((parseFloat(String(b).replace(',', '.')) || 0) * (parseFloat(String(t).replace(',', '.')) || 0) / {{ $mode === 'q' ? 1 : 100 }}).toFixed(3)" @endif
                            title="Laissez vide pour le calcul base × taux">@endif</td></tr>
                @endforeach
                </tbody>
                <tfoot>
                    @if($s === 'tva')
                        <tr><td>Taxe due (I) / déductions (II)</td><td></td><td></td><td class="num">{{ tnd($C['tva_I'], false) }} / {{ tnd($C['tva_II'], false) }}</td></tr>
                        <tr><td>{{ $C['tvap'] >= 0 ? 'TVA à payer' : 'Crédit de TVA à reporter' }}</td><td></td><td></td><td class="num">{{ tnd(abs($C['tvap']), false) }}</td></tr>
                    @elseif($x['II'])
                        <tr><td>Montant dû (I) − déductions (II)</td><td></td><td></td><td class="num">{{ tnd($x['I'], false) }} − {{ tnd($x['II'], false) }} = {{ tnd($x['du'], false) }}</td></tr>
                    @endif
                    <tr><td>Pénalités de retard</td><td></td><td></td><td class="text-right"><input name="pen[{{ $s }}]" value="{{ $num($x['pen']) }}" class="input !py-1 text-right font-mono" inputmode="decimal" @disabled($lock)></td></tr>
                </tfoot></table>
            </div>
        @endforeach
    </div>
    <div class="space-y-4 self-start lg:sticky lg:top-4">
        <x-stat label="TVA {{ $C['tvap'] >= 0 ? 'à payer' : 'crédit à reporter' }}" :value="tnd(abs($C['tvap']))" icon="scale" :tone="$C['tvap'] >= 0 ? 'amber' : 'green'"/>
        <x-stat label="Total à payer" :value="tnd($C['tot'])" icon="wallet"/>
        <a href="{{ route('declarations.arabe', $q) }}" class="btn-secondary w-full justify-center" title="Formulaire officiel DGI en arabe, rempli avec les montants de la déclaration"><x-icon name="printer" class="h-4 w-4"/> Formulaire officiel (arabe)</a>
        <div class="card overflow-hidden"><div class="card-h"><h2 class="card-t">Synthèse des impôts dus</h2></div>
            <table class="tbl text-xs"><thead><tr><th>Impôt</th><th class="text-right">Principal</th><th class="text-right">Pénalités</th><th class="text-right">Total</th></tr></thead><tbody>
                @foreach($C['sections'] as $s => $x)<tr class="{{ $x['du'] + $x['pen'] ? '' : 'text-slate-400' }}"><td>{{ $M::SECTIONS[$s] }}</td><td class="num">{{ tnd($x['du'], false) }}</td><td class="num">{{ tnd($x['pen'], false) }}</td><td class="num">{{ tnd($x['du'] + $x['pen'], false) }}</td></tr>@endforeach
            </tbody><tfoot><tr><td>Total</td><td></td><td></td><td class="num">{{ tnd($C['tot'], false) }}</td></tr></tfoot></table></div>
        @unless($lock)<button class="btn-primary w-full justify-center">Enregistrer</button>@endunless
        <div class="card card-b text-xs text-slate-500">Le montant d’une rubrique laissé vide est calculé (base × taux) ; un montant saisi (cadre orange) est conservé tel quel.
            « Pré-remplir » reprend les factures de vente et d’achat validées, les retenues opérées, la paie clôturée et le crédit de TVA du mois précédent.
            <a href="{{ route('declarations.recap', ['annee' => substr($periode, 0, 4)]) }}" class="text-brand-700 hover:underline">Récapitulatif fiscal de l’année</a>.</div>
    </div>
</div>
</form>
