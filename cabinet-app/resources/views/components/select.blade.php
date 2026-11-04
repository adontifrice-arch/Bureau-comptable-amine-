@props(['label' => null, 'name', 'options' => [], 'value' => null, 'span' => null, 'placeholder' => null])
<div class="{{ $span }}">
    @if($label)<label class="label" for="f-{{ $name }}">{{ $label }}</label>@endif
    <select id="f-{{ $name }}" name="{{ $name }}" {{ $attributes->merge(['class' => 'input']) }}>
        @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $k => $v)
            <option value="{{ $k }}" @selected((string) old($name, $value) === (string) $k)>{{ $v }}</option>
        @endforeach
    </select>
    @error($name)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
</div>
