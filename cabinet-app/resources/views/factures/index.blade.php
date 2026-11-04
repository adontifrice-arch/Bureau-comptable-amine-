<x-layouts.app title="Facturation">
    <x-page-header title="Facturation" subtitle="Factures, devis et honoraires du cabinet">
        <a href="{{ route('ca.index') }}" class="btn-secondary"><x-icon name="trend" class="h-4 w-4"/> Chiffre d’affaires</a>
        <a href="{{ route('depenses.index') }}" class="btn-secondary"><x-icon name="receipt" class="h-4 w-4"/> Dépenses & TEJ</a>
        <a href="{{ route('factures.create', ['type' => 'devis']) }}" class="btn-secondary"><x-icon name="plus" class="h-4 w-4"/> Devis</a>
        <a href="{{ route('factures.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Facture</a>
    </x-page-header>
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total facturé (TTC)" :value="tnd($stats['emis'], true, 0)" icon="invoice"/>
        <x-stat label="Reste à encaisser" :value="tnd($stats['du'], true, 0)" icon="clock" tone="amber"/>
        <x-stat label="Dont en retard" :value="tnd($stats['retard'], true, 0)" icon="alert" :tone="$stats['retard'] ? 'red' : 'green'"/>
        <x-stat label="Encaissé" :value="tnd($stats['paye'], true, 0)" icon="check" tone="green"/>
    </div>
    <x-tabs :active="$type" :items="['facture' => ['Factures', route('factures.index')], 'devis' => ['Devis', route('factures.index', ['type' => 'devis'])]]"/>
    <div class="grid gap-6 xl:grid-cols-4">
        <div class="card xl:col-span-3">
            <form class="flex flex-wrap gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
                <input type="hidden" name="type" value="{{ $type }}">
                <select name="client" class="input w-56" onchange="this.form.submit()"><option value="">Tous les clients</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->nom }}</option>@endforeach</select>
                <select name="statut" class="input w-44" onchange="this.form.submit()"><option value="">Tous les statuts</option>@foreach($type === 'devis' ? \App\Models\Facture::STATUTS_DEVIS : \App\Models\Facture::STATUTS_FACTURE as $k => [$l])<option value="{{ $k }}" @selected(request('statut') === (string) $k)>{{ $l }}</option>@endforeach</select>
            </form>
            @include('factures._table')
        </div>
        <div class="card self-start">
            <div class="card-h"><h2 class="card-t">Catalogue des services</h2></div>
            <div class="max-h-96 overflow-y-auto">
                @forelse($services as $s)
                    <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2 text-sm dark:border-slate-800">
                        <span class="flex-1">{{ $s->nom }}</span><span class="num text-xs text-slate-500">{{ $s->prix > 0 ? tnd($s->prix, false) : '' }}</span>
                        <x-confirm-delete :action="route('factures.services.destroy', $s)" message="Retirer ce service ?"/>
                    </div>
                @empty <div class="p-4 text-sm text-slate-500">Aucun service.</div> @endforelse
            </div>
            <form method="POST" action="{{ route('factures.services.store') }}" class="space-y-2 p-4">@csrf
                <input name="nom" class="input-sm" placeholder="Nouveau service" required>
                <div class="flex gap-2"><input name="prix" type="number" step="0.001" class="input-sm" placeholder="Prix HT"><button class="btn-primary btn-sm">Ajouter</button></div>
            </form>
        </div>
    </div>
</x-layouts.app>
