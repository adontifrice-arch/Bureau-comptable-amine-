@php $T = config('cabinet.'.($type === 'men' ? 'MEN' : 'ANN')); $lock = $d->verrouillee(); @endphp
<table class="w-full text-sm">
@foreach($T as $row)
    @if($row[0] === 'h')
        <tr><th colspan="2" class="bg-slate-50 px-4 pb-2 pt-4 text-left text-xs font-semibold uppercase tracking-wide text-brand-700 dark:bg-slate-800/40">{{ $row[1] }}</th></tr>
    @elseif(($row[2] ?? '') === 'c')
        <tr class="border-b border-slate-100 bg-brand-50/40 font-semibold dark:border-slate-800 dark:bg-brand-500/5"><td class="px-4 py-2.5">{{ $row[1] }}</td><td class="px-4 py-2.5 text-right font-mono">{{ tnd($calc[$row[0]] ?? 0, false) }}</td></tr>
    @else
        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="px-4 py-2">{{ $row[1] }}</td>
            <td class="w-48 px-4 py-1.5 text-right">@if(!empty($print) || $lock)<span class="font-mono">{{ tnd($d->champs[$row[0]] ?? 0, false) }}</span>@else<input type="number" step="any" name="f[{{ $row[0] }}]" value="{{ $d->champs[$row[0]] ?? '' }}" class="input-sm text-right font-mono">@endif</td></tr>
    @endif
@endforeach
</table>
