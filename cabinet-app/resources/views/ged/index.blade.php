@php $kb = fn($n) => $n > 1048576 ? number_format($n / 1048576, 1, ',', ' ').' Mo' : max(1, round($n / 1024)).' Ko'; @endphp
<x-layouts.app title="GED">
    <x-page-header title="Gestion électronique des documents" subtitle="Dossiers juridiques, fiscaux et sociaux des clients et du cabinet">
        <button class="btn-primary" x-data @click="$dispatch('open-modal', 'up')"><x-icon name="upload" class="h-4 w-4"/> Ajouter des documents</button>
    </x-page-header>
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Documents" :value="$total" icon="folder"/>
        <x-stat label="Espace utilisé" :value="$kb($taille)" icon="download"/>
        <x-stat label="Dossiers complets (9 pièces de base)" :value="$completude->where('n', 9)->count().' / '.$clients->count()" icon="check" tone="green"/>
    </div>
    <div class="grid gap-6 xl:grid-cols-4">
        <div class="card xl:col-span-3">
            <form class="grid gap-3 border-b border-slate-100 p-4 md:grid-cols-4 dark:border-slate-800">
                <div class="relative md:col-span-4"><x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/><input name="q" value="{{ $q }}" placeholder="Rechercher (nom, description, catégorie)" class="input pl-9"></div>
                <select name="client" class="input" onchange="this.form.submit()"><option value="">Tous les dossiers</option><option value="0" @selected(request('client') === '0')>Cabinet (interne)</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->nom }}</option>@endforeach</select>
                <select name="categorie" class="input md:col-span-2" onchange="this.form.submit()"><option value="">Toutes les catégories</option>@foreach(config('cabinet.GC') as $g)<option @selected(request('categorie') === $g)>{{ $g }}</option>@endforeach</select>
                <input type="number" name="annee" value="{{ request('annee') }}" placeholder="Année" class="input" onchange="this.form.submit()">
            </form>
            @forelse($docs as $d)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-[10px] font-bold uppercase text-brand-700 dark:bg-brand-500/10">{{ pathinfo($d->nom, PATHINFO_EXTENSION) ?: 'doc' }}</div>
                    <div class="min-w-0 flex-1"><div class="truncate text-sm font-medium">{{ $d->nom }}</div>
                        <div class="text-xs text-slate-500">{{ $d->client?->nom ?? 'Cabinet (interne)' }} · {{ $d->categorie }} · {{ dfr($d->date) }} · {{ $kb($d->taille) }}{{ $d->user ? ' · '.$d->user->name : '' }}</div>
                        @if($d->description)<div class="text-xs text-slate-400">{{ $d->description }}</div>@endif</div>
                    <a href="{{ route('ged.download', $d) }}" class="btn-icon" title="Télécharger"><x-icon name="download" class="h-4 w-4"/></a>
                    <button class="btn-icon" x-data @click="$dispatch('ged-edit', @js($d->only(['id','client_id','categorie','description']) + ['date' => optional($d->date)->format('Y-m-d')])); $dispatch('open-modal', 'edit')"><x-icon name="edit" class="h-4 w-4"/></button>
                    <x-confirm-delete :action="route('ged.destroy', $d)" message="Supprimer définitivement ce document ?"/>
                </div>
            @empty <x-empty icon="folder" title="Aucun document"/> @endforelse
            <div class="p-4">{{ $docs->links() }}</div>
        </div>
        <div class="card self-start">
            <div class="card-h"><h2 class="card-t">Complétude des dossiers</h2></div>
            @foreach($completude as $x)
                <a href="{{ route('clients.show', [$x['c'], 'tab' => 'documents']) }}" class="block border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                    <div class="flex justify-between text-sm"><span class="truncate">{{ $x['c']->nom }}</span><span class="font-mono text-xs">{{ $x['n'] }}/9</span></div>
                    <div class="mt-1.5 h-1.5 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-1.5 rounded-full {{ $x['n'] === 9 ? 'bg-emerald-500' : 'bg-brand-500' }}" style="width: {{ $x['n'] / 9 * 100 }}%"></div></div>
                </a>
            @endforeach
        </div>
    </div>
    <x-modal name="up" title="Ajouter des documents">
        <form method="POST" action="{{ route('ged.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-2">@csrf
            <div><label class="label">Dossier</label><select name="client_id" class="input"><option value="">Cabinet (interne)</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->nom }}</option>@endforeach</select></div>
            <div><label class="label">Catégorie</label><select name="categorie" class="input">@foreach(config('cabinet.GC') as $g)<option>{{ $g }}</option>@endforeach</select></div>
            <x-field label="Date du document" name="date" type="date" :value="now()->format('Y-m-d')"/>
            <x-field label="Description" name="description"/>
            <div class="md:col-span-2"><label class="label">Fichiers (20 Mo max. chacun)</label><input type="file" name="fichiers[]" multiple required class="input"></div>
            <div class="flex justify-end md:col-span-2"><button class="btn-primary">Classer</button></div>
        </form>
    </x-modal>
    <x-modal name="edit" title="Modifier les informations">
        <form method="POST" x-data="{ e: {} }" @ged-edit.window="e = $event.detail" :action="'{{ url('ged') }}/' + e.id" class="grid gap-4 md:grid-cols-2">@csrf @method('PUT')
            <div><label class="label">Dossier</label><select name="client_id" class="input" x-model="e.client_id"><option value="">Cabinet (interne)</option>@foreach($clients as $c)<option value="{{ $c->id }}">{{ $c->nom }}</option>@endforeach</select></div>
            <div><label class="label">Catégorie</label><select name="categorie" class="input" x-model="e.categorie">@foreach(config('cabinet.GC') as $g)<option>{{ $g }}</option>@endforeach</select></div>
            <div><label class="label">Date</label><input type="date" name="date" class="input" x-model="e.date"></div>
            <div><label class="label">Description</label><input name="description" class="input" x-model="e.description"></div>
            <div class="flex justify-end md:col-span-2"><button class="btn-primary">Enregistrer</button></div>
        </form>
    </x-modal>
</x-layouts.app>
