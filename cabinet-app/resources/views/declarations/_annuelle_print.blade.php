{{-- Impression française de la déclaration annuelle (IS ou IR) : rubriques renseignées par section, tableaux, synthèse. --}}
@php
    $A = \App\Services\DeclarationAnnuelle::class;
    $mod = $A::modele($client); $f = $A::champs($d->champs ?? [], $client, (int) $periode);
    [$DEF, $TAB] = $A::definitions($mod); $C = $mod === 'is' ? $A::calculIs($f) : $A::calculIr($f);
    $val = function ($k, $nat) use ($f, $C) {
        $v = $k === 'ded_acp' ? ($C['acomptes'] ?? 0) : ($f[$k] ?? null);
        return match ($nat) { 'x' => ! empty($v) ? 'oui' : '', 'm', 'c' => (float) $v ? tnd((float) $v, false) : '', 'r' => $v === null || $v === '' ? '' : tnd(abs((float) $v), false).((float) $v < 0 ? ' (perte)' : ' (bénéfice)'),
            'd' => $v ? dfr($v) : '', default => (string) $v };
    };
@endphp
<div class="mb-4 text-sm">{{ $mod === 'is' ? 'Déclaration de l’impôt sur les sociétés' : 'Déclaration de l’impôt sur le revenu des personnes physiques' }} · code {{ $f['acte'] ?? '0' }} – {{ $A::ACTES[$f['acte'] ?? '0'] ?? '' }}
    · exercice du {{ ! empty($f['ouv']) ? dfr($f['ouv']) : '' }} au {{ ! empty($f['clo']) ? dfr($f['clo']) : '' }}</div>
@foreach($DEF as $s => [$titre, , $L])
    @php $R = collect($L)->map(fn ($def, $k) => ['lib' => $def[0], 'v' => $val($k, $def[2])])->filter(fn ($x) => $x['v'] !== ''); @endphp
    @if($R->count())
        <h3 class="mb-1 mt-5 font-semibold">{{ $titre }}</h3>
        <table class="w-full text-xs"><tbody>@foreach($R as $x)<tr class="border-b"><td class="px-2 py-1">{{ $x['lib'] }}</td><td class="px-2 py-1 text-right font-mono">{{ $x['v'] }}</td></tr>@endforeach</tbody></table>
    @endif
    @foreach($TAB as $t => [$tt, , $cols]) @if(($A::PLACE[$mod][$t] ?? null) === $s && ! empty($f[$t]))
        <div class="mb-1 mt-3 text-xs font-semibold">{{ $tt }}</div>
        <table class="w-full text-xs"><thead><tr class="bg-slate-100 text-left">@foreach($cols as [$cl])<th class="px-2 py-1">{{ $cl }}</th>@endforeach</tr></thead><tbody>
            @foreach($f[$t] as $l)<tr class="border-b">@foreach($cols as $c => [, , $cn])<td class="px-2 py-1 {{ in_array($cn, ['m', 'r'], true) ? 'text-right font-mono' : '' }}">{{ in_array($cn, ['m', 'r'], true) ? ((float) ($l[$c] ?? 0) ? tnd((float) $l[$c], false) : '') : ($cn === 'd' && ! empty($l[$c]) ? dfr($l[$c]) : ($l[$c] ?? '')) }}</td>@endforeach</tr>@endforeach
        </tbody></table>
    @endif @endforeach
@endforeach
<h3 class="mb-1 mt-6 font-semibold">Synthèse</h3>
<table class="w-full text-sm"><thead><tr class="bg-slate-100 text-left text-xs uppercase"><th class="px-3 py-2">Impôt ou taxe</th><th class="px-3 py-2 text-right">Dû (ou excédent)</th><th class="px-3 py-2 text-right">Pénalités</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody>
    @foreach($C['synthese'] as $k => $x)<tr class="border-b"><td class="px-3 py-1.5">{{ $A::LIB_SYNTHESE[$k] }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($x['III'], false) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($x['pen'], false) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($x['tot'], false) }}</td></tr>@endforeach
</tbody><tfoot><tr class="font-semibold"><td class="px-3 py-2">Total à payer</td><td></td><td></td><td class="px-3 py-2 text-right font-mono">{{ tnd($C['tot'], false) }}</td></tr></tfoot></table>
