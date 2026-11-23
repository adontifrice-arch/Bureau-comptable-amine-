{{-- Paie → Saisie Paie → Variable : rubriques variables du mois (montants, heures, jours, notes) par employé. --}}
@php
    $aSaisir = $rubriques->filter->aSaisir();
    $parId = $salaries->keyBy('id');
    $unite = fn ($rb) => $rb->note ? 'qté' : ($rb->ticket_restaurant ? 'tickets' : ($rb->heures_sup || $rb->heures_nuit ? 'h' : ($rb->jours_sup ? 'j' : ($rb->a_deduire ? 'retenue' : ($rb->valeur_net ? 'net' : 'TND')))));
    $infos = $aSaisir->mapWithKeys(fn ($rb) => [$rb->id => ['code' => $rb->code, 'nom' => $rb->nom, 'unite' => $unite($rb), 'q' => $rb->enQuantite()]]);
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Saisie des éléments variables — <span class="capitalize">{{ mois_fr($mois) }}</span></h2>
    <div class="flex gap-2">
        <a href="{{ route('paie.rubriques', ['mois' => $mois]) }}" class="btn-secondary btn-sm"><x-icon name="list" class="h-4 w-4"/> Gérer les rubriques</a>
        <a href="{{ route('paie.index', ['tab' => 'calc', 'mois' => $mois]) }}" class="btn-secondary btn-sm"><x-icon name="wallet" class="h-4 w-4"/> Saisie Paie</a>
    </div>
</div>
@if(! count($R))
    <div class="card"><x-empty icon="users" title="Aucun salarié à payer ce mois-ci"/></div>
@elseif($aSaisir->isEmpty())
    <div class="card"><x-empty icon="list" title="Aucune rubrique à saisir">Créez des rubriques en saisie manuelle (ou en heures, jours, notes) dans Rubrique → Liste des rubriques.</x-empty></div>
@else
<form method="POST" action="{{ route('paie.calcul') }}" id="rubriques" class="card overflow-x-auto">@csrf
    <input type="hidden" name="mois" value="{{ $mois }}"><input type="hidden" name="parties[]" value="variable"><input type="hidden" name="tab" value="variable">
    <p class="px-4 pt-4 text-xs text-slate-400">Choisissez pour chaque employé les rubriques du mois et leur montant (ou la quantité pour les heures, jours et notes). Une rubrique laissée vide n’est pas payée.</p>
    <div class="p-4 pt-2"><table class="tbl">
        <thead><tr><th class="w-56">Salarié</th><th>Rubriques du mois</th></tr></thead>
        <tbody>
        @foreach($R as $x)
            @php
                $sal = $parId[$x['salarie_id']] ?? null;
                $saisi = (array) (($paie?->rubriques ?? [])[$x['salarie_id']] ?? []);
                $possibles = $sal ? $aSaisir->filter(fn ($rb) => $rb->concerne($sal)) : collect();
                $lignesSaisies = collect($saisi)->filter(fn ($val, $rid) => $possibles->contains('id', (int) $rid))->map(fn ($val, $rid) => ['id' => (int) $rid, 'v' => $val])->values();
            @endphp
            <tr><td class="align-top font-medium">{{ $x['nom'] }}<div class="font-mono text-xs text-slate-400">{{ $x['matricule'] ?? '' }}</div></td>
                <td>
                    @if($lock)
                        @forelse($lignesSaisies as $l)<span class="mr-3 inline-block text-xs"><span class="font-mono text-slate-400">{{ $infos[$l['id']]['code'] }}</span> {{ $infos[$l['id']]['nom'] }} : <b class="font-mono">{{ n3($l['v']) }}</b></span>@empty<span class="text-slate-400">—</span>@endforelse
                    @else
                        <div x-data="{ items: @js($lignesSaisies), infos: @js($infos), ajout(id) { id = +id; if (id && !this.items.some(i => i.id === id)) this.items.push({ id, v: '' }); } }" class="flex flex-wrap items-center gap-2">
                            <template x-for="it in items" :key="it.id">
                                <span class="flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800">
                                    <span class="font-mono text-slate-400" x-text="infos[it.id].code"></span><span x-text="infos[it.id].nom"></span>
                                    <input type="number" :step="infos[it.id].q ? '0.25' : '0.001'" :name="'rubriques[{{ $x['salarie_id'] }}][' + it.id + ']'" x-model="it.v" class="input-sm w-24 text-right">
                                    <span class="text-slate-400" x-text="infos[it.id].unite"></span>
                                    <button type="button" @click="items = items.filter(i => i.id !== it.id)" class="text-slate-400 hover:text-rose-600" title="Retirer">✕</button>
                                </span>
                            </template>
                            @if($possibles->isNotEmpty())
                                <select @change="ajout($event.target.value); $event.target.value = ''" class="input-sm w-56 text-xs">
                                    <option value="">+ Ajouter une rubrique…</option>
                                    @foreach($possibles->groupBy('categorie') as $cat => $grp)
                                        <optgroup label="{{ \App\Models\PaieRubrique::CATEGORIES[$cat] ?? $cat }}">@foreach($grp as $rb)<option value="{{ $rb->id }}">{{ $rb->code }} {{ $rb->nom }}</option>@endforeach</optgroup>
                                    @endforeach
                                </select>
                            @else<span class="text-xs text-slate-400">Aucune rubrique pour ce profil</span>@endif
                        </div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @unless($lock)<div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer et recalculer</button></div>@endunless
</form>
@endif
