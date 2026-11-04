<x-layouts.app title="Clients">
    <x-page-header title="Clients" subtitle="{{ $clients->total() }} dossier(s) suivi(s) par le cabinet">
        <a href="{{ route('clients.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Nouveau client</a>
    </x-page-header>

    <div class="card">
        <form class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
            <div class="relative min-w-60 flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input name="q" value="{{ $q }}" placeholder="Nom, matricule fiscal, gérant…" class="input pl-9">
            </div>
            <select name="type" class="input w-48" onchange="this.form.submit()">
                <option value="">Tous les types</option>
                <option value="morale" @selected(request('type') === 'morale')>Personnes morales</option>
                <option value="physique" @selected(request('type') === 'physique')>Personnes physiques</option>
            </select>
            <button class="btn-secondary">Filtrer</button>
        </form>
        @if($clients->count())
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Client</th><th>Mission</th><th>Matricule fiscal</th><th class="text-right">Honoraires / mois</th><th>Échéances</th><th></th></tr></thead>
                <tbody>
                @foreach($clients as $c)
                    <tr class="cursor-pointer" onclick="location='{{ route('clients.show', $c) }}'">
                        <td>
                            <div class="flex items-center gap-3">
                                <div class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-50 text-xs font-bold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $c->initiales() }}</div>
                                <div class="min-w-0"><div class="font-medium text-slate-900 dark:text-white">{{ $c->nom }}</div><div class="text-xs text-slate-500">{{ $c->forme ?: '—' }}{{ $c->gerant_nom ? ' · '.$c->gerant_nom : '' }}</div></div>
                            </div>
                        </td>
                        <td class="text-slate-600">{{ $c->mission ?: '—' }}</td>
                        <td class="font-mono text-xs">{{ $c->matricule_fiscal ?: '—' }}</td>
                        <td class="num">{{ $c->honoraires_mensuels > 0 ? tnd($c->honoraires_mensuels, false) : '—' }}</td>
                        <td>
                            @if($c->retard_count)<span class="badge-red">{{ $c->retard_count }} en retard</span>
                            @elseif($c->ouvertes_count)<span class="badge-amber">{{ $c->ouvertes_count }} ouverte(s)</span>
                            @else<span class="badge-green">À jour</span>@endif
                        </td>
                        <td class="text-right"><x-icon name="chevron-right" class="inline h-4 w-4 text-slate-400"/></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $clients->links() }}</div>
        @else
            <x-empty icon="users" title="Aucun client">Ajoutez votre premier dossier client pour commencer.</x-empty>
        @endif
    </div>
</x-layouts.app>
