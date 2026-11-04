@props(['name', 'title', 'size' => 'max-w-2xl'])
<div x-data="{ open: false }" x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true" x-on:close-modal.window="open = false" x-on:keydown.escape.window="open = false">
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 pt-[8vh] backdrop-blur-sm" x-transition.opacity>
            <div @click.outside="open = false" class="card w-full {{ $size }}" x-transition>
                <div class="card-h"><h3 class="card-t">{{ $title }}</h3><button type="button" class="btn-icon" @click="open = false"><x-icon name="x" class="h-4 w-4"/></button></div>
                <div class="card-b">{{ $slot }}</div>
            </div>
        </div>
    </template>
</div>
