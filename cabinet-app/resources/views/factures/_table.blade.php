@if($items->count())
<div class="overflow-x-auto"><table class="tbl">
    <thead><tr><th>Numéro</th><th>Client</th><th>Date</th><th>{{ ($items->first()->type ?? '') === 'devis' ? 'Validité' : 'Échéance' }}</th><th class="text-right">Total TTC</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    @foreach($items as $f)
        @php [$sl, $sc] = $f->statutLibelle(); @endphp
        <tr>
            <td><a href="{{ route('factures.show', $f) }}" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $f->numero }}</a></td>
            <td class="font-medium">{{ $f->client->nom }}</td>
            <td>{{ dfr($f->date) }}</td>
            <td>@if($f->enRetard())<span class="badge-red">{{ dfr($f->echeance) }}</span>@else{{ dfr($f->echeance) }}@endif</td>
            <td class="num font-semibold">{{ tnd($f->totaux()['ttc'], false) }}</td>
            <td>
                <form method="POST" action="{{ route('factures.statut', $f) }}">@csrf @method('PATCH')
                    <button class="badge-{{ $sc }} cursor-pointer hover:opacity-80" title="Cliquer pour changer le statut">{{ $sl }}</button>
                </form>
            </td>
            <td class="whitespace-nowrap text-right">
                <a href="{{ route('factures.show', $f) }}" class="btn-icon" title="Voir / imprimer"><x-icon name="eye" class="h-4 w-4"/></a>
                <a href="{{ route('factures.edit', $f) }}" class="btn-icon" title="Modifier"><x-icon name="edit" class="h-4 w-4"/></a>
                <x-confirm-delete :action="route('factures.destroy', $f)"/>
            </td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@else
    <x-empty icon="invoice" title="Aucun document"/>
@endif
