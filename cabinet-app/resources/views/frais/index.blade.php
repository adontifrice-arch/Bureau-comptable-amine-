<x-layouts.app title="Notes de frais">
    <x-page-header title="Notes de frais" subtitle="Frais engagés par le cabinet et refacturation aux clients">
        <a href="{{ route('depenses.index') }}" class="btn-secondary"><x-icon name="receipt" class="h-4 w-4"/> Dépenses & retenues (TEJ)</a>
        <button class="btn-secondary" x-data @click="$dispatch('open-modal', 'doc')"><x-icon name="invoice" class="h-4 w-4"/> Note de frais client</button>
        <button class="btn-primary" x-data @click="$dispatch('open-modal', 'nf')"><x-icon name="plus" class="h-4 w-4"/> Ajouter un frais</button>
    </x-page-header>
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Total des frais" :value="tnd($total)" icon="receipt"/>
        <x-stat label="À rembourser" :value="tnd($aRembourser)" icon="clock" tone="amber"/>
        <x-stat label="Notes adressées aux clients" :value="$docs->count()" icon="invoice" tone="green"/>
    </div>

    <form method="POST" action="{{ route('frais.documents.store') }}" x-data="{ sel: [] }" class="card">
        @csrf
        <div class="card-h">
            <div class="flex rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                @foreach(['all' => 'Tous', '0' => 'À rembourser', '1' => 'Remboursés'] as $k => $l)
                    <a href="?f={{ $k }}" class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $f === (string) $k ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-500' }}">{{ $l }}</a>
                @endforeach
            </div>
            <div x-show="sel.length" x-cloak class="flex items-center gap-2">
                <span class="text-xs text-slate-500" x-text="sel.length + ' frais sélectionné(s)'"></span>
                <select name="client_id" class="input-sm w-48">@foreach($clients as $c)<option value="{{ $c->id }}">{{ $c->nom }}</option>@endforeach</select>
                <input type="hidden" name="date" value="{{ now()->format('Y-m-d') }}">
                <button class="btn-primary btn-sm">Créer la note client</button>
            </div>
        </div>
        @if($items->count())
        <div class="overflow-x-auto"><table class="tbl">
            <thead><tr><th class="w-8"></th><th>Date</th><th>Description</th><th>Catégorie</th><th>Client</th><th class="text-right">Montant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            @foreach($items as $n)
                <tr>
                    <td>@if($n->client_id && !$n->document_id)<input type="checkbox" name="ids[]" value="{{ $n->id }}" x-model="sel" class="rounded border-slate-300 text-brand-600">@endif</td>
                    <td>{{ dfr($n->date) }}</td>
                    <td class="font-medium">{{ $n->description }} @if($n->justificatif)<a href="{{ route('frais.justificatif', $n) }}" target="_blank" class="ml-1 text-xs text-brand-700 hover:underline">justificatif</a>@endif</td>
                    <td>{{ $n->categorie }}</td>
                    <td>{{ $n->client?->nom ?? '—' }} @if($n->document)<a href="{{ route('frais.documents.show', $n->document) }}" class="badge-blue ml-1">{{ $n->document->numero }}</a>@endif</td>
                    <td class="num">{{ tnd($n->montant, false) }}</td>
                    <td><button form="t{{ $n->id }}" class="{{ $n->rembourse ? 'badge-green' : 'badge-amber' }} cursor-pointer">{{ $n->rembourse ? 'Remboursé' : 'À rembourser' }}</button></td>
                    <td class="text-right"><button type="button" form="d{{ $n->id }}" class="btn-icon hover:!text-rose-600" onclick="if(confirm('Supprimer ce frais ?')) document.getElementById('d{{ $n->id }}').submit()"><x-icon name="trash" class="h-4 w-4"/></button></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @else <x-empty icon="receipt" title="Aucune note de frais"/> @endif
    </form>
    @foreach($items as $n)
        <form id="t{{ $n->id }}" method="POST" action="{{ route('frais.toggle', $n) }}" class="hidden">@csrf @method('PATCH')</form>
        <form id="d{{ $n->id }}" method="POST" action="{{ route('frais.destroy', $n) }}" class="hidden">@csrf @method('DELETE')</form>
    @endforeach

    @if($docs->count())
    <div class="card mt-6">
        <div class="card-h"><h2 class="card-t">Notes de frais adressées aux clients</h2></div>
        @foreach($docs as $d)
            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                <a href="{{ route('frais.documents.show', $d) }}" class="font-mono text-xs font-semibold text-brand-700">{{ $d->numero }}</a>
                <span class="flex-1">{{ $d->client->nom }} · {{ dfr($d->date) }}</span>
                <span class="num">{{ tnd($d->total()) }}</span>
                <x-confirm-delete :action="route('frais.documents.destroy', $d)"/>
            </div>
        @endforeach
    </div>
    @endif

    <x-modal name="nf" title="Ajouter une note de frais">
        <form method="POST" action="{{ route('frais.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-2">@csrf
            <x-field label="Date *" name="date" type="date" :value="now()->format('Y-m-d')" required/>
            <x-field label="Montant (TND) *" name="montant" type="number" step="0.001" required/>
            <x-field label="Description *" name="description" required span="md:col-span-2" placeholder="Déplacement chez le client"/>
            <x-select label="Catégorie" name="categorie" :options="array_combine(config('cabinet.NFC'), config('cabinet.NFC'))"/>
            <x-select label="Client concerné" name="client_id" placeholder="Frais interne au cabinet" :options="$clients->pluck('nom', 'id')"/>
            <x-field label="Justificatif (PDF ou image)" name="justificatif" type="file" span="md:col-span-2" accept=".pdf,image/*"/>
            <div class="flex justify-end md:col-span-2"><button class="btn-primary">Ajouter</button></div>
        </form>
    </x-modal>
    <x-modal name="doc" title="Nouvelle note de frais à adresser à un client" size="max-w-3xl">
        <form method="POST" action="{{ route('frais.documents.store') }}" x-data="{ l: [{date:'{{ now()->format('Y-m-d') }}', designation:'', montant:''}] }" class="space-y-4">@csrf
            <div class="grid gap-4 md:grid-cols-2">
                <x-select label="Client *" name="client_id" :options="$clients->pluck('nom', 'id')"/>
                <x-field label="Date" name="date" type="date" :value="now()->format('Y-m-d')"/>
            </div>
            <template x-for="(x, i) in l" :key="i"><div class="grid grid-cols-12 gap-2">
                <input type="date" class="input col-span-3" :name="`lignes[${i}][date]`" x-model="x.date">
                <input class="input col-span-6" placeholder="Désignation du frais" :name="`lignes[${i}][designation]`" x-model="x.designation">
                <input type="number" step="0.001" class="input col-span-2" placeholder="Montant" :name="`lignes[${i}][montant]`" x-model="x.montant">
                <button type="button" class="btn-icon col-span-1" @click="l.splice(i,1)"><x-icon name="trash" class="h-4 w-4"/></button>
            </div></template>
            <button type="button" class="btn-secondary btn-sm" @click="l.push({date:'{{ now()->format('Y-m-d') }}', designation:'', montant:''})">+ Ligne</button>
            <x-field label="Observations" name="observations" type="textarea"/>
            <div class="flex justify-end"><button class="btn-primary">Créer</button></div>
        </form>
    </x-modal>
</x-layouts.app>
