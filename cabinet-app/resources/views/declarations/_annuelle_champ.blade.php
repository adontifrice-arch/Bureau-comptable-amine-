{{-- Champ d'une rubrique de déclaration annuelle : $k (code), $def [libellé, arabe, nature], $val (valeur), $nom (attribut name), $lock. --}}
@php [$lib, $ar, $nat] = $def; $num = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 3, '.', ''); @endphp
@if($nat === 'x')
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="{{ $nom }}" value="0"><input type="checkbox" name="{{ $nom }}" value="1" @checked(! empty($val)) class="rounded border-slate-300 text-brand-600" @disabled($lock)> {{ $lib }}</label>
@elseif($nat === 'r')
    <div class="flex items-center gap-2" x-data="{ v: '{{ $num($val) }}' }"><label class="flex-1 text-sm">{{ $lib }}</label>
        <input name="{{ $nom }}" x-model="v" class="input !w-40 !py-1 text-right font-mono" inputmode="decimal" @disabled($lock) title="Négatif pour une perte">
        <span class="w-16 text-xs" x-text="v === '' ? '' : (parseFloat(String(v).replace(',', '.')) < 0 ? 'perte' : 'bénéfice')"></span></div>
@elseif($nat === 'c')
    <div class="flex items-center gap-2"><span class="flex-1 text-sm">{{ $lib }}</span><span class="w-40 text-right font-mono text-sm">{{ tnd((float) $val, false) }}</span><span class="w-16 text-xs text-slate-400">calculé</span></div>
@else
    <div class="flex items-center gap-2"><label class="flex-1 text-sm">{{ $lib }}</label>
        <input name="{{ $nom }}" value="{{ $nat === 'm' ? $num($val) : $val }}" @if($nat === 'd') type="date" @endif class="input {{ $nat === 't' ? '!w-64' : '!w-40 text-right font-mono' }} !py-1" @if($nat === 'm') inputmode="decimal" @endif @disabled($lock)>
        <span class="w-16"></span></div>
@endif
