@props(['action', 'label' => 'Supprimer', 'message' => 'Confirmer la suppression ?', 'icon' => true])
<form method="POST" action="{{ $action }}" class="inline" onsubmit="return confirm(@js($message))">
    @csrf @method('DELETE')
    @if($icon)
        <button type="submit" class="btn-icon hover:!text-rose-600" title="{{ $label }}"><x-icon name="trash" class="h-4 w-4"/></button>
    @else
        <button type="submit" class="btn-secondary btn-sm !text-rose-600">{{ $label }}</button>
    @endif
</form>
