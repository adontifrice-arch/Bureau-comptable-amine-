{{-- Déclaration de l'acompte provisionnel (modèle DGI) : saisie à l'écran, ou impression si $print. --}}
@php
    $P = \App\Services\DeclarationAcompte::class;
    $f = $d->champs ?? []; $C = $P::calcul($f); $print = ! empty($print); $ro = $print || $lock;
    $num = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 3, '.', '');
    $groupes = [['Calcul de l’acompte', ['base', 'pv', 'mv', 'base_th', 'impot_th', 'I']], ['Retenues, acomptes, excédents et avances déductibles (II)', $P::DEDUCTIONS], ['Pénalités', ['penalites']]];
@endphp
<form method="POST" action="{{ route('declarations.save') }}">@include('declarations._hidden')
<div class="grid gap-6 {{ $print ? '' : 'lg:grid-cols-3' }}">
    <div class="space-y-6 lg:col-span-2">
        <div class="card card-b grid gap-3 text-sm md:grid-cols-3">
            <div><label class="label">Code de la déclaration</label>@if($ro)<div>{{ $f['acte'] ?? '0' }} – {{ $P::ACTES[$f['acte'] ?? '0'] }}</div>@else<select name="acte" class="input">@foreach($P::ACTES as $k => $l)<option value="{{ $k }}" @selected((string) ($f['acte'] ?? '0') === (string) $k)>{{ $k }} – {{ $l }}</option>@endforeach</select>@endif</div>
            <div><label class="label">N° du registre de commerce</label>@if($ro)<div>{{ $f['rc'] ?? '—' }}</div>@else<input name="rc" value="{{ $f['rc'] ?? '' }}" class="input">@endif</div>
            <div><label class="label">Activité</label>@if($ro)<div>{{ $f['activite'] ?? $client->paie_activite }}</div>@else<input name="activite" value="{{ $f['activite'] ?? $client->paie_activite }}" class="input">@endif</div>
        </div>
        @foreach($groupes as [$titre, $codes])
            <div class="card overflow-hidden"><div class="card-h"><h2 class="card-t">{{ $titre }}</h2></div>
                <table class="tbl text-sm"><tbody>
                @foreach($codes as $k)
                    @php [$lib, , $nat] = $P::LIGNES[$k]; @endphp
                    <tr class="{{ $nat === 'c' ? 'bg-brand-50/40 font-semibold dark:bg-brand-500/5' : '' }}"><td>{{ $lib }}</td>
                        <td class="w-52 text-right">@if($nat === 'c' || $ro)<span class="font-mono">{{ tnd($nat === 'c' || $k === 'impot_th' ? $C[$k] : (float) ($f[$k] ?? 0), false) }}</span>@if($nat === 'r' && ($f[$k] ?? 0) < 0) <span class="text-xs text-slate-500">perte</span>@endif
                            @else<input name="f[{{ $k }}]" value="{{ $num($f[$k] ?? null) }}" class="input !py-1 text-right font-mono" inputmode="decimal" @if($nat === 'r') title="Négatif pour une perte" @endif @if($k === 'impot_th') placeholder="{{ $num($C['impot_calc']) }}" title="Laissez vide pour le calcul automatique" @endif>
                                @if($k === 'impot_th' && ! empty($f['regime']))<div class="mt-0.5 text-[11px] text-slate-500">calcul : {{ $f['regime'] === 'is' ? ($f['taux'] ?? 15).' %' : 'barème de l’IR' }}, minimum {{ tnd((float) ($f['min'] ?? 0), false) }}</div>@endif
                            @endif</td></tr>
                @endforeach
                @if($titre !== 'Pénalités' && $codes === $P::DEDUCTIONS)<tr class="font-semibold"><td>Total des déductions (II)</td><td class="num">{{ tnd($C['II'], false) }}</td></tr>@endif
                </tbody></table></div>
        @endforeach
    </div>
    <div class="space-y-4 self-start {{ $print ? '' : 'lg:sticky lg:top-4' }}">
        <div class="card card-b space-y-1 text-sm">
            <div class="flex justify-between"><span>Acompte (I) : 30 % de l’impôt théorique</span><span class="font-mono">{{ tnd($C['I'], false) }}</span></div>
            <div class="flex justify-between"><span>Déductions (II)</span><span class="font-mono">− {{ tnd($C['II'], false) }}</span></div>
            <div class="flex justify-between border-t pt-1 font-semibold"><span>Reste (III = I − II) : {{ $C['III'] >= 0 ? 'dû' : 'excédent' }}</span><span class="font-mono">{{ tnd(abs($C['III']), false) }}</span></div>
            <div class="flex justify-between"><span>Pénalités</span><span class="font-mono">{{ tnd($C['penalites'], false) }}</span></div>
            <div class="flex justify-between border-t pt-1 text-base font-bold"><span>Total à payer</span><span class="font-mono">{{ tnd($C['tot'], false) }}</span></div>
            @if($C['excedent'])<p class="pt-1 text-xs text-emerald-700">L’excédent de {{ tnd($C['excedent']) }} est reporté sur l’acompte suivant.</p>@endif
        </div>
        @unless($ro)<button class="btn-primary w-full justify-center">Enregistrer</button>
            <a href="{{ route('declarations.arabe', $q) }}" class="btn-secondary w-full justify-center"><x-icon name="printer" class="h-4 w-4"/> Formulaire officiel (arabe)</a>
            <div class="card card-b text-xs text-slate-500">L’impôt théorique est celui de l’année précédente, recalculé sans les plus-values et en réintégrant les moins-values de cession ; il ne peut être inférieur au minimum d’impôt.
                « Pré-remplir » reprend la déclaration annuelle de l’année précédente et, pour les 2e et 3e acomptes, l’excédent de l’acompte précédent.</div>@endunless
    </div>
</div>
</form>
