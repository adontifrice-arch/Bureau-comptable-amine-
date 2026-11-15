{{-- Pièces supprimées : écritures retirées du journal, consultables et restaurables --}}
@php $autorise = \App\Services\Ecritures::autorise(auth()->user()); @endphp
<div class="mb-3 text-sm text-slate-500">Les écritures supprimées n’entrent plus dans le journal, la balance, le grand livre ni les déclarations. Elles restent ici et peuvent être restaurées{{ $autorise ? '' : ' par le chef comptable ou un collaborateur autorisé' }}. Double-cliquez sur une ligne pour consulter l’écriture.</div>
<div class="card overflow-x-auto">
    <table class="tbl">
        <thead><tr><th>Supprimée le</th><th>Par</th><th>Motif</th><th>Date</th><th>Jnl</th><th>Libellé</th><th class="text-right">Montant</th><th></th></tr></thead>
        <tbody>
        @forelse($supprimees as $e)
            @php $fiche = route('compta.ecritures.show', [$e, 'retour' => request()->fullUrl()]); @endphp
            <tr class="cursor-pointer hover:bg-brand-50/60 dark:hover:bg-slate-800/40" ondblclick="if (! event.target.closest('a, button, form')) window.location = {{ \Illuminate\Support\Js::from($fiche) }}" title="Double-clic : consulter l’écriture">
                <td class="whitespace-nowrap">{{ $e->deleted_at->format('d/m/Y H:i') }}</td>
                <td class="text-xs">{{ $e->supprimePar?->name ?? '—' }}</td>
                <td class="max-w-[14rem] text-xs text-slate-500">{{ $e->motif_suppression ?: '—' }}</td>
                <td class="whitespace-nowrap">{{ dfr($e->date) }}</td>
                <td><span class="badge-gray">{{ $e->journal }}</span></td>
                <td><a href="{{ $fiche }}" class="hover:underline">{{ $e->libelle }}</a>@if($e->source)<div class="text-xs text-slate-400">{{ $e->origine() }}</div>@endif</td>
                <td class="num">{{ tnd($e->lignes->sum('debit'), false) }}</td>
                <td class="whitespace-nowrap text-right">
                    <a href="{{ $fiche }}" class="btn-icon" title="Consulter"><x-icon name="eye" class="h-4 w-4"/></a>
                    @if($autorise)<form method="POST" action="{{ route('compta.ecritures.restaurer', $e) }}" class="inline">@csrf<button class="btn-success btn-sm">Restaurer</button></form>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><x-empty icon="trash" title="Aucune pièce supprimée">Les écritures supprimées depuis le journal ou le grand livre apparaîtront ici.</x-empty></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $supprimees->links() }}</div>
