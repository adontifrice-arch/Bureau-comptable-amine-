<x-layouts.app title="Interne">
    <x-page-header title="Organisation interne" subtitle="Rendez-vous clients, notes, rappels et ressources du cabinet">
        <button class="btn-primary" x-data @click="$dispatch('edit-{{ $tab }}', {}); $dispatch('open-modal', '{{ $tab }}')"><x-icon name="plus" class="h-4 w-4"/> Ajouter</button>
    </x-page-header>
    <x-tabs :active="$tab" :items="['rdv' => ['Rendez-vous clients', '?tab=rdv'], 'nr' => ['Notes et rappels', '?tab=nr'], 'au' => ['Autres', '?tab=au']]"/>

    @if($tab === 'rdv')
        <form class="mb-4"><input type="hidden" name="tab" value="rdv"><select name="client" class="input w-64" onchange="this.form.submit()"><option value="">Tous les clients</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->nom }}</option>@endforeach</select></form>
        <div class="grid gap-4 md:grid-cols-2">
            @forelse($rdv as $r)
                <div class="card p-5">
                    <div class="flex items-start gap-4">
                        <div class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl {{ $r->date->isPast() && !$r->date->isToday() ? 'bg-slate-100 text-slate-500' : 'bg-brand-50 text-brand-700' }} dark:bg-slate-800">
                            <div class="text-center leading-none"><div class="text-lg font-bold">{{ $r->date->format('d') }}</div><div class="text-[10px] uppercase">{{ $r->date->locale('fr')->translatedFormat('M Y') }}</div></div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold">{{ $r->objet }}</div>
                            <div class="text-xs text-slate-500">{{ $r->heure ? $r->heure.' · ' : '' }}{{ $r->lieu }}{{ $r->client ? ' · '.$r->client->nom : '' }}</div>
                            @if($r->participants)<div class="mt-1 text-xs text-slate-500">Participants : {{ $r->participants }}</div>@endif
                        </div>
                        <span class="badge-{{ ['Prévu' => 'blue', 'Tenu' => 'green', 'Annulé' => 'red', 'Reporté' => 'amber'][$r->statut] ?? 'gray' }}">{{ $r->statut }}</span>
                    </div>
                    @if($r->compte_rendu)<div class="mt-3 whitespace-pre-line rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/50"><b class="text-xs text-slate-500">Compte rendu</b><br>{{ $r->compte_rendu }}</div>@endif
                    @if($r->actions)<div class="mt-2 whitespace-pre-line text-sm"><b class="text-xs text-slate-500">Actions à mener</b><br>{{ $r->actions }}</div>@endif
                    <div class="mt-3 flex justify-end gap-1">
                        <button class="btn-icon" x-data @click="$dispatch('edit-rdv', @js($r->toArray() + ['date' => $r->date->format('Y-m-d')])); $dispatch('open-modal', 'rdv')"><x-icon name="edit" class="h-4 w-4"/></button>
                        <x-confirm-delete :action="route('interne.rdv.destroy', $r)"/>
                    </div>
                </div>
            @empty <div class="card md:col-span-2"><x-empty icon="calendar" title="Aucun rendez-vous"/></div> @endforelse
        </div>
    @else
        @php $L = $tab === 'nr' ? $notes : $autres; @endphp
        <div class="card">
            @forelse($L as $n)
                @php $late = !$n->fait && $n->rappel_le && $n->rappel_le->lt(today()); @endphp
                <div class="flex items-start gap-4 border-b border-slate-100 px-5 py-4 last:border-0 dark:border-slate-800" x-data="{ o: false }">
                    @if($tab === 'nr')<form method="POST" action="{{ route('interne.notes.toggle', $n) }}">@csrf @method('PATCH')<input type="checkbox" onchange="this.form.submit()" @checked($n->fait) class="mt-1 rounded border-slate-300 text-emerald-600"></form>@endif
                    <div class="min-w-0 flex-1">
                        <button class="text-left font-medium {{ $n->fait ? 'text-slate-400 line-through' : '' }}" @click="o = !o">{{ $n->titre }}</button>
                        <div class="text-xs text-slate-500">{{ $n->categorie ? $n->categorie.' · ' : '' }}{{ dfr($n->date) }}</div>
                        <div x-show="o" x-cloak class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $n->contenu }}</div>
                    </div>
                    @if($n->rappel_le && !$n->fait)<span class="{{ $late ? 'badge-red' : ($n->rappel_le->isToday() ? 'badge-amber' : 'badge-gray') }}">Rappel {{ dfr($n->rappel_le) }}</span>@endif
                    <button class="btn-icon" x-data @click="$dispatch('edit-{{ $tab }}', @js($n->toArray() + ['date' => optional($n->date)->format('Y-m-d'), 'rappel_le' => optional($n->rappel_le)->format('Y-m-d')])); $dispatch('open-modal', '{{ $tab }}')"><x-icon name="edit" class="h-4 w-4"/></button>
                    <x-confirm-delete :action="route('interne.notes.destroy', $n)"/>
                </div>
            @empty <x-empty icon="note" title="Rien pour le moment"/> @endforelse
        </div>
    @endif

    <x-modal name="rdv" title="Rendez-vous" size="max-w-3xl">
        <form method="POST" x-data="{ e: {} }" @edit-rdv.window="e = Object.assign({statut:'Prévu', date:'{{ now()->format('Y-m-d') }}'}, $event.detail)" :action="e.id ? '{{ url('interne/rdv') }}/' + e.id : '{{ route('interne.rdv.store') }}'" class="grid gap-4 md:grid-cols-3">@csrf
            <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
            <div class="md:col-span-2"><label class="label">Objet *</label><input name="objet" class="input" x-model="e.objet" required></div>
            <div><label class="label">Client</label><select name="client_id" class="input" x-model="e.client_id"><option value="">Aucun client précis</option>@foreach($clients as $c)<option value="{{ $c->id }}">{{ $c->nom }}</option>@endforeach</select></div>
            <div><label class="label">Date *</label><input type="date" name="date" class="input" x-model="e.date" required></div>
            <div><label class="label">Heure</label><input type="time" name="heure" class="input" x-model="e.heure"></div>
            <div><label class="label">Statut</label><select name="statut" class="input" x-model="e.statut">@foreach(['Prévu','Tenu','Reporté','Annulé'] as $s)<option>{{ $s }}</option>@endforeach</select></div>
            <div><label class="label">Lieu</label><input name="lieu" class="input" x-model="e.lieu"></div>
            <div class="md:col-span-2"><label class="label">Participants</label><input name="participants" class="input" x-model="e.participants"></div>
            <div class="md:col-span-3"><label class="label">Compte rendu</label><textarea name="compte_rendu" rows="3" class="input" x-model="e.compte_rendu"></textarea></div>
            <div class="md:col-span-3"><label class="label">Actions à mener</label><textarea name="actions" rows="2" class="input" x-model="e.actions"></textarea></div>
            <div class="flex justify-end md:col-span-3"><button class="btn-primary">Enregistrer</button></div>
        </form>
    </x-modal>
    @foreach(['nr' => 'note', 'au' => 'autre'] as $k => $type)
    <x-modal :name="$k" :title="$type === 'note' ? 'Note ou rappel' : 'Élément'">
        <form method="POST" x-data="{ e: {} }" @edit-{{ $k }}.window="e = Object.assign({date:'{{ now()->format('Y-m-d') }}'}, $event.detail)" :action="e.id ? '{{ url('interne/notes') }}/' + e.id : '{{ route('interne.notes.store') }}'" class="grid gap-4 md:grid-cols-2">@csrf
            <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
            <input type="hidden" name="type" value="{{ $type }}">
            <div class="md:col-span-2"><label class="label">Titre *</label><input name="titre" class="input" x-model="e.titre" required></div>
            @if($type === 'autre')<div><label class="label">Catégorie</label><input name="categorie" class="input" x-model="e.categorie" placeholder="Organisation, modèles, idées…"></div>@endif
            <div><label class="label">Date</label><input type="date" name="date" class="input" x-model="e.date"></div>
            @if($type === 'note')<div><label class="label">Me le rappeler le</label><input type="date" name="rappel_le" class="input" x-model="e.rappel_le"></div>@endif
            <div class="md:col-span-2"><label class="label">Détail</label><textarea name="contenu" rows="5" class="input" x-model="e.contenu"></textarea></div>
            <div class="flex justify-end md:col-span-2"><button class="btn-primary">Enregistrer</button></div>
        </form>
    </x-modal>
    @endforeach
</x-layouts.app>
