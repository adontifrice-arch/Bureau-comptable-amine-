@props(['clients', 'current', 'route', 'params' => []])
<form method="GET" action="{{ $route }}" class="flex items-center gap-2">
    @foreach($params as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <label class="text-xs font-medium text-slate-500">Dossier</label>
    <select name="client" class="input w-64" onchange="this.form.submit()">
        @foreach($clients as $c)<option value="{{ $c->id }}" @selected($current?->id === $c->id)>{{ $c->nom }}</option>@endforeach
    </select>
</form>
