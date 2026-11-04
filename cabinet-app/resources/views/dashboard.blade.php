<x-layouts.app title="Tableau de bord">
    <x-page-header title="Bonjour {{ explode(' ', auth()->user()->name)[0] }}" subtitle="{{ ucfirst(now()->locale('fr')->translatedFormat('l j F Y')) }} — voici l’essentiel du cabinet.">
        <a href="{{ route('factures.create') }}" class="btn-secondary"><x-icon name="invoice" class="h-4 w-4"/> Nouvelle facture</a>
        <a href="{{ route('clients.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Nouveau client</a>
    </x-page-header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Échéances en retard" :value="$retard" icon="alert" :tone="$retard ? 'red' : 'green'" hint="{{ $ouvertes }} échéance(s) ouverte(s)"/>
        <x-stat label="Clients suivis" :value="$nbClients" icon="users"/>
        <x-stat label="Honoraires à encaisser" :value="tnd($aEncaisser, true, 0)" icon="clock" tone="amber"/>
        <x-stat label="Encaissé" :value="tnd($encaisse, true, 0)" icon="check" tone="green"/>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-h"><h2 class="card-t">À traiter en priorité</h2><a href="{{ route('echeances.index') }}" class="text-xs font-medium text-brand-700 hover:underline">Toutes les échéances →</a></div>
            @forelse($prioritaires as $e)
                @php $j = $e->joursRestants(); @endphp
                <div class="flex items-center gap-4 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $j < 0 ? 'bg-rose-50 text-rose-600' : ($j <= 7 ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-500') }} dark:bg-slate-800">
                        <div class="text-center leading-none"><div class="text-sm font-bold">{{ $e->date_echeance->format('d') }}</div><div class="text-[10px] uppercase">{{ $e->date_echeance->locale('fr')->translatedFormat('M') }}</div></div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $e->intitule }}</div>
                        <a href="{{ route('clients.show', $e->client) }}" class="text-xs text-slate-500 hover:text-brand-700">{{ $e->client->nom }}</a>
                    </div>
                    @if($j < 0)<span class="badge-red">{{ -$j }} j de retard</span>@elseif($j <= 7)<span class="badge-amber">Dans {{ $j }} j</span>@else<span class="badge-gray">{{ dfr($e->date_echeance) }}</span>@endif
                    <form method="POST" action="{{ route('echeances.toggle', $e) }}">@csrf @method('PATCH')<button class="btn-icon" title="Marquer accomplie"><x-icon name="check" class="h-4 w-4"/></button></form>
                </div>
            @empty
                <x-empty icon="check" title="Aucune échéance ouverte">Tout est à jour.</x-empty>
            @endforelse
        </div>

        <div class="card">
            <div class="card-h"><h2 class="card-t">Chiffre d’affaires HT</h2><span class="text-xs text-slate-400">12 derniers mois</span></div>
            <div class="card-b">
                @php $max = max(1, $caMois->max('v')); @endphp
                <div class="flex h-44 items-end gap-1.5">
                    @foreach($caMois as $m)
                        <div class="group flex flex-1 flex-col items-center gap-1.5">
                            <div class="relative w-full rounded-t-md bg-brand-500/80 transition group-hover:bg-brand-600" style="height: {{ max(3, $m['v'] / $max * 150) }}px">
                                <span class="pointer-events-none absolute -top-7 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-1.5 py-0.5 text-[10px] text-white group-hover:block">{{ tnd($m['v'], false, 0) }}</span>
                            </div>
                            <span class="text-[10px] text-slate-400">{{ $m['label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('ca.index') }}" class="mt-4 block text-xs font-medium text-brand-700 hover:underline">Récapitulatif du chiffre d’affaires →</a>
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card">
            <div class="card-h"><h2 class="card-t">Factures en retard de paiement</h2></div>
            @forelse($impayees as $f)
                <a href="{{ route('factures.show', $f) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                    <div class="min-w-0"><div class="truncate font-medium">{{ $f->client->nom }}</div><div class="text-xs text-slate-500">{{ $f->numero }} · échue le {{ dfr($f->echeance) }}</div></div>
                    <span class="num text-rose-600">{{ tnd($f->totaux()['ttc'], false) }}</span>
                </a>
            @empty
                <x-empty icon="check" title="Aucun impayé en retard"/>
            @endforelse
        </div>
        <div class="card">
            <div class="card-h"><h2 class="card-t">Factures reçues à valider</h2><a href="{{ route('compta.index') }}" class="text-xs font-medium text-brand-700 hover:underline">Comptabilité →</a></div>
            @forelse($aValider as $f)
                <a href="{{ route('compta.index', ['client' => $f->client_id]) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                    <div class="min-w-0"><div class="truncate font-medium">{{ $f->fournisseur }}</div><div class="text-xs text-slate-500">{{ $f->client->nom }} · {{ dfr($f->date) }}</div></div>
                    <span class="num">{{ tnd($f->ttc(), false) }}</span>
                </a>
            @empty
                <x-empty icon="check" title="Rien à valider"/>
            @endforelse
        </div>
        <div class="card">
            <div class="card-h"><h2 class="card-t">Agenda & rappels</h2><a href="{{ route('interne.index') }}" class="text-xs font-medium text-brand-700 hover:underline">Interne →</a></div>
            @foreach($rdv as $r)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm dark:border-slate-800">
                    <x-icon name="calendar" class="h-4 w-4 text-brand-600"/>
                    <div class="min-w-0 flex-1"><div class="truncate font-medium">{{ $r->objet }}</div><div class="text-xs text-slate-500">{{ dfr($r->date) }} {{ $r->heure ? 'à '.$r->heure : '' }}{{ $r->client ? ' · '.$r->client->nom : '' }}</div></div>
                </div>
            @endforeach
            @foreach($rappels as $n)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                    <x-icon name="clock" class="h-4 w-4 {{ $n->rappel_le->isPast() ? 'text-rose-500' : 'text-amber-500' }}"/>
                    <div class="min-w-0 flex-1"><div class="truncate font-medium">{{ $n->titre }}</div><div class="text-xs text-slate-500">Rappel le {{ dfr($n->rappel_le) }}</div></div>
                </div>
            @endforeach
            @if($rdv->isEmpty() && $rappels->isEmpty())<x-empty icon="calendar" title="Agenda libre"/>@endif
        </div>
    </div>
</x-layouts.app>
