<x-layouts.app title="Échéances">
    <x-page-header title="Échéances" subtitle="Obligations fiscales, sociales et juridiques de tous les clients">
        <button class="btn-primary" x-data @click="$dispatch('open-modal', 'ech')"><x-icon name="plus" class="h-4 w-4"/> Ajouter une échéance</button>
    </x-page-header>
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Ouvertes" :value="$counts['open']" icon="calendar"/>
        <x-stat label="En retard" :value="$counts['late']" icon="alert" :tone="$counts['late'] ? 'red' : 'green'"/>
        <x-stat label="Dans les 7 jours" :value="$counts['week']" icon="clock" tone="amber"/>
    </div>
    <div class="card">
        <form class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
            <div class="flex rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                @foreach(['open' => 'Ouvertes', 'late' => 'En retard', 'done' => 'Accomplies', 'all' => 'Toutes'] as $k => $l)
                    <a href="{{ request()->fullUrlWithQuery(['f' => $k, 'page' => null]) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $f === $k ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-500' }}">{{ $l }}</a>
                @endforeach
            </div>
            <input type="hidden" name="f" value="{{ $f }}">
            <select name="client" class="input w-56" onchange="this.form.submit()"><option value="">Tous les clients</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->nom }}</option>@endforeach</select>
            <input type="month" name="mois" value="{{ request('mois') }}" class="input w-44" onchange="this.form.submit()">
        </form>
        @include('echeances._list', ['items' => $items])
        <div class="p-4">{{ $items->links() }}</div>
    </div>
    <x-modal name="ech" title="Nouvelle échéance">@include('echeances._form')</x-modal>
</x-layouts.app>
