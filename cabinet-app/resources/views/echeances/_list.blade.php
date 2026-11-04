@forelse($items as $e)
    @php $j = $e->joursRestants(); @endphp
    <div class="flex items-center gap-4 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800 {{ $e->accomplie_le ? 'opacity-60' : '' }}">
        <form method="POST" action="{{ route('echeances.toggle', $e) }}">@csrf @method('PATCH')
            <button class="grid h-6 w-6 place-items-center rounded-md border {{ $e->accomplie_le ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 hover:border-brand-500 dark:border-slate-600' }}" title="{{ $e->accomplie_le ? 'Rouvrir' : 'Marquer accomplie' }}">@if($e->accomplie_le)<x-icon name="check" class="h-3.5 w-3.5"/>@endif</button>
        </form>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-medium {{ $e->accomplie_le ? 'line-through' : 'text-slate-800 dark:text-slate-100' }}">{{ $e->intitule }}</div>
            <div class="text-xs text-slate-500">
                @if($showClient ?? true)<a href="{{ route('clients.show', $e->client) }}" class="hover:text-brand-700">{{ $e->client->nom }}</a> · @endif
                {{ $e->categorie ? $e->categorie.' · ' : '' }}{{ $e->accomplie_le ? 'accomplie le '.dfr($e->accomplie_le) : 'à faire avant le '.dfr($e->date_echeance) }}
            </div>
        </div>
        @unless($e->accomplie_le)
            @if($j < 0)<span class="badge-red">{{ -$j }} j de retard</span>@elseif($j <= 7)<span class="badge-amber">{{ $j ? 'Dans '.$j.' j' : 'Aujourd’hui' }}</span>@else<span class="badge-gray">{{ dfr($e->date_echeance) }}</span>@endif
        @else <span class="badge-green">Accomplie</span> @endunless
        <x-confirm-delete :action="route('echeances.destroy', $e)" message="Supprimer cette échéance ?"/>
    </div>
@empty
    <x-empty icon="calendar" title="Aucune échéance"/>
@endforelse
