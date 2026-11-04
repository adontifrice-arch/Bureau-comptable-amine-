@props(['items' => [], 'active' => null])
<div class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
    @foreach($items as $key => $it)
        <a href="{{ $it[1] }}" class="tab {{ $active === $key ? 'tab-active' : '' }}">{{ $it[0] }}</a>
    @endforeach
</div>
