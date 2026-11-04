<form method="POST" action="{{ route('echeances.store') }}" class="grid gap-4 md:grid-cols-2">
    @csrf
    @if(!empty($clientId))<input type="hidden" name="client_id" value="{{ $clientId }}">
    @else
        <div class="md:col-span-2"><label class="label">Client *</label><select name="client_id" class="input" required>@foreach($clients as $c)<option value="{{ $c->id }}">{{ $c->nom }}</option>@endforeach</select></div>
    @endif
    <div><label class="label">Catégorie</label><select name="categorie" class="input">@foreach(config('cabinet.CATS') as $c)<option>{{ $c }}</option>@endforeach</select></div>
    <div><label class="label">Date limite *</label><input type="date" name="date_echeance" id="ech-date" class="input" value="{{ now()->addWeek()->format('Y-m-d') }}" required></div>
    <div class="md:col-span-2"><label class="label">Intitulé</label><input name="intitule" class="input" placeholder="TVA de mars, liasse fiscale…"></div>
    <div class="flex justify-end md:col-span-2"><button class="btn-primary">Ajouter l’échéance</button></div>
</form>
