<x-layouts.app title="Chiffre d’affaires">
    <x-page-header title="Récapitulatif du chiffre d’affaires" subtitle="Du {{ dfr($from) }} au {{ dfr($to) }}" :back="route('factures.index')">
        <a href="{{ route('ca.csv', request()->query()) }}" class="btn-secondary no-print"><x-icon name="download" class="h-4 w-4"/> Excel (CSV)</a>
        <button onclick="window.print()" class="btn-primary no-print"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button>
    </x-page-header>
    <form class="card no-print mb-6 flex flex-wrap items-end gap-3 p-4">
        <div><label class="label">Du</label><input type="date" name="du" value="{{ $from }}" class="input"></div>
        <div><label class="label">Au</label><input type="date" name="au" value="{{ $to }}" class="input"></div>
        <div><label class="label">Client</label><select name="client" class="input w-56"><option value="">Tous</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->nom }}</option>@endforeach</select></div>
        <button class="btn-primary">Afficher</button>
    </form>
    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <x-stat label="Factures" :value="$rows->count()" icon="invoice"/>
        <x-stat label="Total HT" :value="tnd($s['ht'], true, 0)" icon="trend"/>
        <x-stat label="TVA collectée" :value="tnd($s['tva'], true, 0)" icon="scale"/>
        <x-stat label="Total TTC" :value="tnd($s['ttc'], true, 0)" icon="check" tone="green"/>
    </div>
    <div class="card overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>Date</th><th>Facture n°</th><th>Client</th><th>Matricule fiscal</th><th>Compte</th><th class="text-right">HT</th><th class="text-right">TVA</th><th class="text-right">Timbre</th><th class="text-right">TTC</th><th>RS</th></tr></thead>
            <tbody>
            @forelse($rows as $x)
                <tr><td>{{ dfr($x['f']->date) }}</td><td class="font-mono text-xs">{{ $x['f']->numero }}</td><td>{{ $x['f']->client->nom }}</td><td class="text-xs">{{ $x['f']->client->matricule_fiscal }}</td><td class="text-xs">{{ $x['f']->client->compte_comptable }}</td>
                    <td class="num">{{ tnd($x['ht'], false) }}</td><td class="num">{{ tnd($x['tva'], false) }}</td><td class="num">{{ tnd($x['timbre'], false) }}</td><td class="num font-semibold">{{ tnd($x['ttc'], false) }}</td><td>{{ $x['f']->retenue_source ? 'Oui' : 'Non' }}</td></tr>
            @empty <tr><td colspan="10"><x-empty icon="invoice" title="Aucune facture sur la période"/></td></tr> @endforelse
            </tbody>
            @if($rows->count())<tfoot><tr><td colspan="5">Total</td><td class="num">{{ tnd($s['ht'], false) }}</td><td class="num">{{ tnd($s['tva'], false) }}</td><td class="num">{{ tnd($s['timbre'], false) }}</td><td class="num">{{ tnd($s['ttc'], false) }}</td><td></td></tr></tfoot>@endif
        </table>
    </div>
</x-layouts.app>
