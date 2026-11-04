@props(['label' => null, 'name', 'type' => 'text', 'value' => null, 'span' => null, 'hint' => null])
<div class="{{ $span }}">
    @if($label)<label class="label" for="f-{{ $name }}">{{ $label }}</label>@endif
    @if($type === 'textarea')
        <textarea id="f-{{ $name }}" name="{{ $name }}" {{ $attributes->merge(['class' => 'input', 'rows' => 3]) }}>{{ old($name, $value) }}</textarea>
    @else
        <input id="f-{{ $name }}" type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}" {{ $attributes->merge(['class' => 'input']) }}>
    @endif
    @if($hint)<p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>@endif
    @error($name)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
</div>
