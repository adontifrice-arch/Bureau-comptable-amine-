@props(['label', 'value', 'icon' => null, 'tone' => 'brand', 'hint' => null])
@php $tones = ['brand' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300', 'red' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300', 'green' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300', 'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300']; @endphp
<div {{ $attributes->merge(['class' => 'card p-4 flex items-start gap-3.5']) }}>
    @if($icon)<div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $tones[$tone] }}"><x-icon :name="$icon" class="h-5 w-5"/></div>@endif
    <div class="min-w-0">
        <div class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</div>
        <div class="mt-0.5 truncate font-mono text-xl font-semibold tabular-nums {{ $tone === 'red' ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">{{ $value }}</div>
        @if($hint)<div class="mt-0.5 text-xs text-slate-400">{{ $hint }}</div>@endif
    </div>
</div>
