<x-layouts.app :title="'Calendrier · '.$client->nom">
    <x-page-header :title="'Calendrier '.$annee" :subtitle="$client->nom" :back="route('clients.show', [$client, 'tab' => 'echeances'])">
        <a href="?annee={{ $annee - 1 }}" class="btn-secondary"><x-icon name="chevron-left" class="h-4 w-4"/> {{ $annee - 1 }}</a>
        <a href="?annee={{ $annee + 1 }}" class="btn-secondary">{{ $annee + 1 }} <x-icon name="chevron-right" class="h-4 w-4"/></a>
        <button class="btn-secondary" x-data @click="$dispatch('open-modal', 'serie')"><x-icon name="list" class="h-4 w-4"/> Générer une série</button>
        <button class="btn-primary" x-data @click="$dispatch('open-modal', 'ech')"><x-icon name="plus" class="h-4 w-4"/> Échéance</button>
    </x-page-header>
    @php $done = $items->whereNotNull('accomplie_le')->count(); $late = $items->whereNull('accomplie_le')->filter(fn($e) => $e->date_echeance->isPast() && !$e->date_echeance->isToday())->count(); $today = now()->format('Y-m-d'); @endphp
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Accomplies en {{ $annee }}" :value="$done.' / '.$items->count()" icon="check" tone="green"/>
        <x-stat label="En retard" :value="$late" icon="alert" :tone="$late ? 'red' : 'green'"/>
        <x-stat label="Séries d’échéances" :value="$series->count()" icon="list"/>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach(range(1, 12) as $m)
            @php $d0 = \Carbon\Carbon::create($annee, $m, 1); $its = $items->filter(fn($e) => $e->date_echeance->month === $m); $off = ($d0->dayOfWeekIso - 1); @endphp
            <div class="card p-4">
                <div class="mb-3 flex items-center justify-between"><b class="text-sm capitalize">{{ $d0->locale('fr')->translatedFormat('F') }}</b><span class="text-xs text-slate-400">{{ $its->whereNotNull('accomplie_le')->count() }}/{{ $its->count() }}</span></div>
                <div class="grid grid-cols-7 gap-1 text-center text-[11px]">
                    @foreach(['L','M','M','J','V','S','D'] as $w)<div class="font-semibold text-slate-400">{{ $w }}</div>@endforeach
                    @for($k = 0; $k < $off; $k++)<div></div>@endfor
                    @for($dd = 1; $dd <= $d0->daysInMonth; $dd++)
                        @php $ds = $d0->copy()->day($dd)->format('Y-m-d'); $de = $its->filter(fn($e) => $e->date_echeance->format('Y-m-d') === $ds);
                             $cl = $de->isEmpty() ? 'text-slate-600 dark:text-slate-400' : ($de->every(fn($e) => $e->accomplie_le) ? 'bg-emerald-500 text-white' : ($ds < $today ? 'bg-rose-500 text-white' : 'bg-amber-400 text-white')); @endphp
                        <button type="button" x-data @click="document.getElementById('ech-date').value='{{ $ds }}'; $dispatch('open-modal', 'ech')" class="aspect-square rounded-md font-medium hover:ring-2 hover:ring-brand-400 {{ $cl }} {{ $ds === $today ? 'ring-2 ring-brand-600' : '' }}">{{ $dd }}</button>
                    @endfor
                </div>
                @if($its->count())
                    <div class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
                        @foreach($its as $e)
                            <div class="flex items-center gap-2 text-xs">
                                <form method="POST" action="{{ route('echeances.toggle', $e) }}">@csrf @method('PATCH')<input type="checkbox" onchange="this.form.submit()" @checked($e->accomplie_le) class="rounded border-slate-300 text-emerald-600"></form>
                                <span class="w-6 font-mono text-slate-400">{{ $e->date_echeance->format('d') }}</span>
                                <span class="flex-1 truncate {{ $e->accomplie_le ? 'text-slate-400 line-through' : '' }}">{{ $e->intitule }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <x-modal name="ech" title="Nouvelle échéance">@include('echeances._form', ['clientId' => $client->id])</x-modal>
    <x-modal name="serie" title="Générer une série d’échéances">
        <form method="POST" action="{{ route('echeances.serie') }}" class="grid gap-4 md:grid-cols-2">
            @csrf <input type="hidden" name="client_id" value="{{ $client->id }}"><input type="hidden" name="annee" value="{{ $annee }}">
            <div><label class="label">Catégorie</label><select name="categorie" class="input">@foreach(config('cabinet.CATS') as $c)<option>{{ $c }}</option>@endforeach</select></div>
            <div><label class="label">Intitulé</label><input name="intitule" class="input" placeholder="Déclaration mensuelle"></div>
            <div><label class="label">Périodicité</label><select name="periodicite" class="input">@foreach(['Mensuelle','Trimestrielle','Semestrielle','Annuelle'] as $p)<option>{{ $p }}</option>@endforeach</select></div>
            <div><label class="label">Jour du mois</label><input type="number" name="jour" value="28" min="1" max="31" class="input"></div>
            <div><label class="label">À partir du mois</label><select name="mois_debut" class="input">@foreach(range(1,12) as $m)<option value="{{ $m }}">{{ ucfirst(\Carbon\Carbon::create(2000,$m,1)->locale('fr')->translatedFormat('F')) }}</option>@endforeach</select></div>
            <div><label class="label">Nombre d’années (à partir de {{ $annee }})</label><input type="number" name="nb_annees" value="1" min="1" max="10" class="input"></div>
            <div class="flex justify-end md:col-span-2"><button class="btn-primary">Générer</button></div>
        </form>
        @if($series->count())
            <form method="POST" action="{{ route('echeances.serie.destroy') }}" class="mt-6 grid gap-3 border-t border-slate-100 pt-5 md:grid-cols-3 dark:border-slate-800" onsubmit="return confirm('Supprimer ces échéances ?')">
                @csrf <input type="hidden" name="annee" value="{{ $annee }}">
                <div class="md:col-span-3 text-sm font-semibold">Supprimer une série</div>
                <select name="serie" class="input md:col-span-2">@foreach($series as $id => $s)<option value="{{ $id }}">{{ $s['label'] }} ({{ $s['n'] }})</option>@endforeach</select>
                <select name="portee" class="input"><option value="year">Année {{ $annee }}</option><option value="from">À partir de {{ $annee }}</option><option value="all">Toute la série</option></select>
                <div class="md:col-span-3 flex justify-end"><button class="btn-danger btn-sm">Supprimer</button></div>
            </form>
        @endif
    </x-modal>
</x-layouts.app>
