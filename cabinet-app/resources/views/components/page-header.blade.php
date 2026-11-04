@props(['title', 'subtitle' => null, 'back' => null])
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @if($back)<a href="{{ $back }}" class="mb-2 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-brand-700"><x-icon name="arrow-left" class="h-3.5 w-3.5"/> Retour</a>@endif
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>
        @if($subtitle)<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>@endif
    </div>
    @if(trim($slot))<div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>@endif
</div>
