<x-layouts.app title="Échanges clients">
    <x-page-header title="Échanges clients" subtitle="Documents envoyés par les clients depuis leur espace et documents que le cabinet met à leur disposition.">
        <a href="{{ route('espace.login') }}" target="_blank" class="btn-secondary"><x-icon name="eye" class="h-4 w-4"/> Page de connexion client</a>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Envois à traiter" :value="$nbATraiter" icon="upload" :tone="$nbATraiter ? 'amber' : 'brand'"/>
        <x-stat label="Partages pas encore ouverts" :value="$nbNonConsultes" icon="eye"/>
        <x-stat label="Accès clients actifs" :value="$nbAcces" icon="users"/>
    </div>

    <form class="mb-4 flex flex-wrap gap-3">
        <select name="client" class="input max-w-xs" onchange="this.form.submit()"><option value="">Tous les clients</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected($client?->id === $c->id)>{{ $c->nom }}</option>@endforeach</select>
        <select name="vue" class="input max-w-xs" onchange="this.form.submit()">
            <option value="a-traiter" @selected($filtre === 'a-traiter')>Envois à traiter</option>
            <option value="tous" @selected($filtre === 'tous')>Tous les envois</option>
        </select>
    </form>

    <div class="grid gap-6 xl:grid-cols-5">
        <div class="card self-start xl:col-span-3">
            <div class="card-h"><h2 class="card-t">Reçus des clients</h2><span class="badge-amber">{{ $recus->total() }}</span></div>
            @forelse($recus as $d)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-[10px] font-bold uppercase text-amber-700 dark:bg-amber-500/10">{{ pathinfo($d->nom, PATHINFO_EXTENSION) ?: 'doc' }}</div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $d->nom }}</div>
                        <div class="text-xs text-slate-500">
                            @if($d->client)<a href="{{ route('clients.show', [$d->client, 'tab' => 'espace']) }}" class="font-medium text-brand-700 hover:underline">{{ $d->client->nom }}</a> · @endif
                            {{ $d->categorie }} · {{ $d->created_at->format('d/m/Y H:i') }}{{ $d->deposant ? ' · '.$d->deposant->name : '' }} · {{ $d->taillePourAffichage() }}
                        </div>
                        @if($d->description)<div class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">« {{ $d->description }} »</div>@endif
                        @if($d->traite_at)<div class="text-[11px] text-emerald-700">Traité le {{ $d->traite_at->format('d/m/Y') }}{{ $d->traitePar ? ' par '.$d->traitePar->name : '' }}</div>@endif
                    </div>
                    <a href="{{ route('ged.download', $d) }}" class="btn-icon" title="Télécharger"><x-icon name="download" class="h-4 w-4"/></a>
                    <form method="POST" action="{{ route('echanges.traiter', $d) }}">@csrf @method('PATCH')
                        @if($d->traite_at)
                            <button class="btn-secondary btn-sm" title="Remettre à traiter">Rouvrir</button>
                        @else
                            <button class="btn-primary btn-sm"><x-icon name="check" class="h-3.5 w-3.5"/> Traité</button>
                        @endif
                    </form>
                </div>
            @empty
                <x-empty icon="upload" :title="$filtre === 'a-traiter' ? 'Aucun envoi à traiter' : 'Aucun envoi'">Les documents envoyés par les clients depuis leur espace arrivent ici et dans la GED de leur dossier.</x-empty>
            @endforelse
            @if($recus->hasPages())<div class="p-4">{{ $recus->links() }}</div>@endif
        </div>

        <div class="card self-start xl:col-span-2">
            <div class="card-h"><h2 class="card-t">Partagés avec les clients</h2></div>
            @forelse($partages as $d)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                    <x-icon name="file-text" class="h-5 w-5 shrink-0 text-brand-600"/>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $d->nom }}</div>
                        <div class="text-xs text-slate-500">{{ $d->client?->nom }} · {{ optional($d->partage_at)->format('d/m/Y') }}</div>
                    </div>
                    @if($d->consulte_at)
                        <span class="badge-green" title="Téléchargé le {{ $d->consulte_at->format('d/m/Y H:i') }}">Ouvert</span>
                    @else
                        <span class="badge-gray">Non ouvert</span>
                    @endif
                </div>
            @empty
                <x-empty icon="folder" title="Aucun document partagé">Partagez un document depuis la GED ou depuis l’onglet « Espace client » d’une fiche client.</x-empty>
            @endforelse
        </div>
    </div>
</x-layouts.app>
