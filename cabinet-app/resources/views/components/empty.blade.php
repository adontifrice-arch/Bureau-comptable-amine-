@props(['icon' => 'folder', 'title' => 'Rien pour le moment'])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 px-6 py-12 text-center']) }}>
    <div class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800"><x-icon :name="$icon" class="h-6 w-6"/></div>
    <div class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $title }}</div>
    @if(trim($slot))<div class="max-w-sm text-sm text-slate-500">{{ $slot }}</div>@endif
</div>
