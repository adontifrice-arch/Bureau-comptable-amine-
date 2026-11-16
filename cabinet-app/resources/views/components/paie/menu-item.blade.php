@props(['it'])
{{-- Entrée d'un menu de la barre Paie. Clés : l (libellé), i (icône), href | modal | post | sub, confirm, badge, blank, off (grisé : texte de l'infobulle). --}}
@php $base = 'flex w-full items-center gap-2.5 px-3 py-2 text-left text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800'; @endphp
@if(!empty($it['sep']))
    <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
@elseif(!empty($it['sub']))
    <div class="relative" x-data="{ s: false }" @mouseenter="s = true" @mouseleave="s = false">
        <button type="button" @click="s = !s" class="{{ $base }}"><x-icon :name="$it['i'] ?? 'chevron-right'" class="h-4 w-4 text-brand-600"/><span class="flex-1">{{ $it['l'] }}</span><x-icon name="chevron-right" class="h-3.5 w-3.5 text-slate-400"/></button>
        <div x-show="s" x-cloak class="absolute left-full top-0 z-40 -ml-1 min-w-64 rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
            @foreach($it['sub'] as $sub)<x-paie.menu-item :it="$sub"/>@endforeach
        </div>
    </div>
@elseif(!empty($it['off']) || (empty($it['href']) && empty($it['modal']) && empty($it['post'])))
    <span class="flex cursor-not-allowed items-center gap-2.5 px-3 py-2 text-slate-400" title="{{ is_string($it['off'] ?? null) ? $it['off'] : '' }}"><x-icon :name="$it['i'] ?? 'x'" class="h-4 w-4"/><span class="flex-1">{{ $it['l'] }}</span>@if(empty($it['off']))<span class="badge-gray">bientôt</span>@endif</span>
@elseif(!empty($it['modal']))
    <button type="button" @click="o = false; $dispatch('open-modal', '{{ $it['modal'] }}')" class="{{ $base }}"><x-icon :name="$it['i'] ?? 'file-text'" class="h-4 w-4 text-brand-600"/><span class="flex-1">{{ $it['l'] }}</span>@if(!empty($it['badge']))<span class="rounded-full bg-amber-500 px-1.5 text-[10px] font-bold text-white">{{ $it['badge'] }}</span>@endif</button>
@elseif(!empty($it['post']))
    <form method="POST" action="{{ $it['post'] }}" @if(!empty($it['confirm'])) onsubmit="return confirm(@js($it['confirm']))" @endif>@csrf
        <button class="{{ $base }}"><x-icon :name="$it['i'] ?? 'check'" class="h-4 w-4 text-brand-600"/><span class="flex-1">{{ $it['l'] }}</span></button>
    </form>
@else
    <a href="{{ $it['href'] }}" @if(!empty($it['blank'])) target="_blank" @endif class="{{ $base }}"><x-icon :name="$it['i'] ?? 'file-text'" class="h-4 w-4 text-brand-600"/><span class="flex-1">{{ $it['l'] }}</span></a>
@endif
