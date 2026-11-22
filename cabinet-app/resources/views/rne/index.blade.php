@use('App\Models\DepotRne')
@use('App\Services\Rne\Formalites')
@php $u = fn ($t, $x = []) => route('rne.index', ['tab' => $t, 'exercice' => $exercice, 'client' => $client->id] + $x); @endphp
<x-layouts.app title="RNE">
    <x-page-header title="Registre National des Entreprises" :subtitle="$client->nom.($client->estPhysique() ? ' · personne physique' : ' · personne morale')">
        <x-client-picker :clients="$clients" :current="$client" :route="route('rne.index')" :params="['tab' => $tab, 'exercice' => $exercice]"/>
    </x-page-header>
    <x-tabs :active="$tab" :items="['depot' => ['Dépôt des états financiers', $u('depot')], 'checklist' => ['Check-list RNE', $u('checklist')]]"/>

    @if($tab === 'depot')
        @php
            $attendues = $depot->piecesAttendues($client);
            $obligatoires = array_values(array_diff($attendues, ['rapport_cac']));
            $fournies = collect($obligatoires)->filter(fn ($k) => $depot->document($k))->count();
            $echeance = $depot->echeance();
            $enRetard = $depot->statut !== 'depose' && now()->startOfDay()->gt($echeance);
        @endphp
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <form class="flex items-end gap-2"><input type="hidden" name="client" value="{{ $client->id }}"><input type="hidden" name="tab" value="depot">
                <div><label class="label">Exercice</label><select name="exercice" class="input w-32" onchange="this.form.submit()">@foreach(range(now()->year, now()->year - 6) as $y)<option @selected($y === $exercice)>{{ $y }}</option>@endforeach</select></div>
            </form>
            <div class="flex flex-wrap gap-3">
                <x-stat label="Pièces prêtes" :value="$fournies.' / '.count($obligatoires)" icon="file-text" :tone="$fournies === count($obligatoires) ? 'green' : 'amber'"/>
                <x-stat label="Délai légal (RNE)" :value="$echeance->format('d/m/Y')" icon="clock" :tone="$enRetard ? 'red' : 'brand'" :hint="$enRetard ? 'Dépassé : pénalité de la moitié de la redevance' : 'Fin du 7e mois après la clôture'"/>
                <x-stat label="Statut" :value="$depot->statut === 'depose' ? 'Déposé' : 'En préparation'" icon="check" :tone="$depot->statut === 'depose' ? 'green' : 'brand'"
                    :hint="$depot->statut === 'depose' ? 'le '.$depot->date_depot?->format('d/m/Y').($depot->reference ? ' · '.$depot->reference : '') : null"/>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-5">
            <div class="card self-start lg:col-span-3">
                <div class="card-h"><h2 class="card-t">Pièces du dépôt — exercice {{ $exercice }}</h2>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('rne.generer', [$client, $exercice]) }}">@csrf<button class="btn-primary btn-sm" title="Bilan, état de résultat, flux, notes et liste des associés en PDF, classés dans la GED"><x-icon name="file-text" class="h-3.5 w-3.5"/> {{ $depot->document('bilan') ? 'Régénérer' : 'Générer' }} les PDF</button></form>
                        <a href="{{ route('rne.zip', [$client, $exercice]) }}" class="btn-secondary btn-sm"><x-icon name="download" class="h-3.5 w-3.5"/> Dossier ZIP</a>
                    </div>
                </div>
                @foreach(DepotRne::PIECES as $k => [$lib, $genere, $condition])
                    @php $doc = $depot->document($k); $attendue = in_array($k, $attendues, true); @endphp
                    @continue($condition === 'morale' && ! $attendue)
                    <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800 {{ $attendue ? '' : 'opacity-60' }}">
                        <x-icon name="{{ $doc ? 'check' : 'file-text' }}" class="h-5 w-5 {{ $doc ? 'text-emerald-600' : 'text-slate-400' }}"/>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $lib }} @if($k === 'rapport_cac')<span class="text-xs font-normal text-slate-400">(s’il y en a)</span>@endif</div>
                            <div class="text-xs text-slate-500">
                                @if($doc) {{ $doc->nom }} · {{ $doc->created_at->format('d/m/Y H:i') }}
                                @elseif(! $attendue) À ajouter une fois l’approbation des états financiers faite.
                                @elseif($genere) Généré d’après {{ $k === 'associes' ? 'la fiche client' : 'la comptabilité (liasse '.$exercice.')' }}.
                                @else À importer (scan). @endif
                            </div>
                        </div>
                        @if($doc)
                            <a href="{{ route('ged.download', $doc) }}" class="btn-icon" title="Télécharger"><x-icon name="download" class="h-4 w-4"/></a>
                            <form method="POST" action="{{ route('rne.retirer', [$client, $exercice, $k]) }}" onsubmit="return confirm('Retirer cette pièce du dépôt ? Elle est supprimée de la GED.')">@csrf @method('DELETE')
                                <button class="btn-icon hover:!text-rose-600" title="Retirer"><x-icon name="trash" class="h-4 w-4"/></button></form>
                        @elseif($attendue)
                            <span class="{{ $k === 'rapport_cac' ? 'badge-gray' : 'badge-amber' }}">{{ $k === 'rapport_cac' ? 'Facultatif' : 'Manquante' }}</span>
                        @endif
                        @if(! $genere && $attendue)
                            <form method="POST" action="{{ route('rne.importer', [$client, $exercice, $k]) }}" enctype="multipart/form-data" class="flex items-center gap-2">@csrf
                                <input type="file" name="fichier" accept=".pdf,.jpg,.jpeg,.png" required class="input-sm w-52">
                                <button class="btn-secondary btn-sm"><x-icon name="upload" class="h-3.5 w-3.5"/> {{ $doc ? 'Remplacer' : 'Importer' }}</button></form>
                        @endif
                        @if($genere && in_array($k, ['bilan', 'resultat', 'flux', 'notes'], true))
                            <a href="{{ route('liasse.etats-financiers', ['client' => $client->id, 'annee' => $exercice, 'partie' => $k]) }}" target="_blank" class="text-xs font-medium text-brand-700 hover:underline">Aperçu</a>
                        @endif
                    </div>
                @endforeach
                <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">
                    Check-list officielle (RNE M 004.09 pour les sociétés) : états financiers, rapport du commissaire aux comptes dans les cas prévus par la loi, copie du PV d’approbation le cas échéant,
                    liste mise à jour des associés avec copies des pièces d’identité. Redevance : 50 TND (20 TND pour une personne physique). Dépôt du PV d’approbation seul : RNE M 004.37, dans les 30 jours du PV.
                </p>
            </div>

            <form method="POST" action="{{ route('rne.enregistrer', [$client, $exercice]) }}" class="card self-start lg:col-span-2"
                  x-data="{ app: @js((bool) old('approbation', $depot->approbation)), st: @js(old('statut', $depot->statut ?: 'preparation')) }">@csrf
                <div class="card-h"><h2 class="card-t">Approbation et dépôt</h2></div>
                <div class="card-b space-y-4">
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="approbation" value="0"><input type="checkbox" name="approbation" value="1" x-model="app" class="rounded border-slate-300 text-brand-600"> Approbation des états financiers faite (AGO tenue)</label>
                    <div x-show="app"><x-field label="Date de l’AGO" name="date_approbation" type="date" :value="$depot->date_approbation?->format('Y-m-d')"/>
                        <p class="mt-1 text-xs text-slate-500">Importez ensuite le PV de l’AGO scanné et, s’il y en a un, le rapport du commissaire aux comptes.</p></div>
                    <div><label class="label">Statut du dépôt</label>
                        <select name="statut" class="input" x-model="st"><option value="preparation">En préparation</option><option value="depose">Déposé au RNE</option></select></div>
                    <div x-show="st === 'depose'" class="grid gap-3 sm:grid-cols-2">
                        <x-field label="Date du dépôt" name="date_depot" type="date" :value="$depot->date_depot?->format('Y-m-d')"/>
                        <x-field label="Référence de la demande" name="reference" :value="$depot->reference"/>
                    </div>
                    <x-field label="Observations" name="observations" type="textarea" :value="$depot->observations"/>
                    <div class="flex justify-end"><button class="btn-primary">Enregistrer</button></div>
                </div>
            </form>
        </div>

    @else
        <form class="card mb-4 grid gap-3 p-4 md:grid-cols-5">
            <input type="hidden" name="client" value="{{ $client->id }}"><input type="hidden" name="tab" value="checklist">
            <div><label class="label">Genre d’entreprise</label><select name="genre" class="input" onchange="this.form.submit()"><option value="">Tous</option>@foreach(['Personne physique', 'Personne Morale'] as $g)<option @selected($genre === $g)>{{ $g }}</option>@endforeach</select></div>
            <div><label class="label">Type de l’opération</label><select name="operation" class="input" onchange="this.form.submit()"><option value="">Toutes</option>@foreach(['Création', 'Inscription', 'Mise à jour'] as $o)<option @selected($operation === $o)>{{ $o }}</option>@endforeach</select></div>
            <div class="md:col-span-2"><label class="label">Rechercher</label><input name="q" value="{{ $q }}" class="input" placeholder="dépôt des états financiers, transfert du siège, RNE M 004…"></div>
            <div class="flex items-end"><button class="btn-primary w-full">Filtrer</button></div>
        </form>
        <p class="mb-3 text-xs text-slate-500">{{ $formalites->count() }} formalité(s) · source : <a href="{{ $source['source'] }}" target="_blank" class="text-brand-700 hover:underline">site du RNE</a>
            @if($source['mis_a_jour']) (mise à jour du {{ \Carbon\Carbon::parse($source['mis_a_jour'])->format('d/m/Y') }}) @endif — documents demandés repris des fiches officielles ; cochez ceux que le client a fournis.</p>

        <div class="space-y-2">
            @forelse($formalites as $f)
                @php $s = $suivis[$f['code']] ?? null; $coches = (array) ($s?->coches ?? []); $nb = count($f['documents']); @endphp
                <details class="card" @if($ouverte === $f['code']) open @endif>
                    <summary class="flex cursor-pointer flex-wrap items-center gap-3 px-5 py-3 text-sm">
                        <span class="font-mono text-xs text-slate-500">{{ $f['code'] }}</span>
                        <span class="min-w-0 flex-1"><span class="font-medium">{{ $f['procedure'] }}</span> <span class="text-slate-500">— {{ $f['type'] }}</span></span>
                        <span class="badge-gray">{{ Formalites::operation($f['code']) }}</span>
                        <span class="badge-blue">{{ $f['redevance'] }}</span>
                        @if($s && $nb)<span class="{{ count($coches) === $nb ? 'badge-green' : 'badge-amber' }}">{{ count($coches) }}/{{ $nb }}</span>@endif
                    </summary>
                    <form method="POST" action="{{ route('rne.cocher', $client) }}" class="space-y-3 border-t border-slate-100 px-5 py-4 text-sm dark:border-slate-800">@csrf
                        <input type="hidden" name="code" value="{{ $f['code'] }}">
                        @foreach(['genre' => $genre, 'operation' => $operation, 'q' => $q] as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                        @if($f['service'] && $f['service'] !== $f['procedure'])<p class="text-slate-600 dark:text-slate-400">{{ $f['service'] }}</p>@endif
                        <p><span class="font-medium">Délais :</span> {{ $f['delais'] ?: '—' }}</p>
                        <div><div class="mb-1 font-medium">Documents demandés</div>
                            @forelse($f['documents'] as $i => $doc)
                                <label class="flex items-start gap-2 py-0.5"><input type="checkbox" name="coches[]" value="{{ $i }}" @checked(in_array($i, $coches, true)) class="mt-0.5 rounded border-slate-300 text-brand-600"> <span>{{ $doc }}</span></label>
                            @empty
                                <p class="text-xs text-slate-500">Liste non lue : consultez la fiche officielle.</p>
                            @endforelse
                        </div>
                        @if($f['observations'])<ul class="list-disc pl-5 text-xs text-slate-500">@foreach($f['observations'] as $o)<li>{{ $o }}</li>@endforeach</ul>@endif
                        <x-field label="Notes" name="notes" :value="$s?->notes"/>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a href="{{ $f['pdf'] }}" target="_blank" class="text-xs font-medium text-brand-700 hover:underline">Fiche officielle (PDF) →</a>
                            <button class="btn-primary btn-sm">Enregistrer la check-list</button>
                        </div>
                    </form>
                </details>
            @empty
                <div class="card"><x-empty icon="list" title="Aucune formalité">
                    @if(! $source['formalites']) Les formalités ne sont pas encore chargées : lancez <span class="font-mono">php artisan rne:formalites</span>. @else Modifiez les filtres. @endif
                </x-empty></div>
            @endforelse
        </div>
    @endif
</x-layouts.app>
