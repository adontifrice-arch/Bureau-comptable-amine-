{{-- Relevés bancaires importés : contrepartie de chaque opération, puis comptabilisation au journal BQ (chef comptable) --}}
@php $ub = fn ($x = []) => route('compta.index', array_filter(['tab' => 'bq', 'annee' => $annee, 'releve' => $releve?->id, 'statut' => $filtre] , fn ($v) => $v !== null) + $x); @endphp

<div class="grid gap-6 lg:grid-cols-4">
    <div class="space-y-3 lg:col-span-1">
        <div class="card">
            <div class="card-h"><h2 class="card-t">Relevés importés</h2></div>
            <a href="{{ route('compta.index', ['tab' => 'bq', 'annee' => $annee, 'statut' => $filtre]) }}" class="block border-b border-slate-100 px-4 py-2.5 text-sm dark:border-slate-800 {{ $releve ? 'hover:bg-slate-50 dark:hover:bg-slate-800/40' : 'bg-slate-50 font-semibold dark:bg-slate-800/40' }}">Tous les relevés</a>
            @forelse($releves as $rl)
                <div class="flex items-start gap-2 border-b border-slate-100 px-4 py-2.5 text-sm last:border-0 dark:border-slate-800 {{ $releve?->id === $rl->id ? 'bg-slate-50 dark:bg-slate-800/40' : '' }}">
                    <a href="{{ route('compta.index', ['tab' => 'bq', 'annee' => $annee, 'releve' => $rl->id, 'statut' => $filtre]) }}" class="min-w-0 flex-1">
                        <div class="truncate font-medium" title="{{ $rl->nom_fichier }}">{{ $rl->nom_fichier }}</div>
                        <div class="text-xs text-slate-500">{{ $rl->created_at->format('d/m/Y') }} · {{ \App\Models\Releve::METHODES[$rl->methode] ?? $rl->methode }} · {{ $rl->compte_banque }}</div>
                        <div class="text-xs">{{ $rl->lignes_count }} opération(s) @if($rl->a_traiter)<span class="badge-amber">{{ $rl->a_traiter }} à traiter</span>@else<span class="badge-green">traité</span>@endif</div>
                    </a>
                    @if($rl->fichier)<a href="{{ route('compta.releves.fichier', $rl) }}" class="btn-icon" title="Télécharger le fichier d’origine"><x-icon name="download" class="h-4 w-4"/></a>@endif
                    <x-confirm-delete :action="route('compta.releves.destroy', $rl)" message="Supprimer ce relevé et ses lignes non comptabilisées ?"/>
                </div>
            @empty
                <div class="px-4 py-6 text-center text-sm text-slate-500">Aucun relevé importé.</div>
            @endforelse
        </div>
        <div class="card card-b text-xs text-slate-500">
            <p><b>Contrepartie proposée</b> : règle du paramétrage (mot-clé du libellé), sinon fournisseur ou client connu, sinon compte d’attente {{ $saisie['banque']['attente'] }}.</p>
            <p class="mt-2"><b>Écriture</b> : encaissement D {{ $saisie['banque']['compte'] }} / C contrepartie ; décaissement D contrepartie / C {{ $saisie['banque']['compte'] }} (journal BQ).</p>
        </div>
    </div>

    <div class="lg:col-span-3">
        <div class="mb-3 flex flex-wrap items-center gap-1">
            @foreach(['att' => 'À comptabiliser', 'cpt' => 'Comptabilisées', 'ign' => 'Ignorées', 'tout' => 'Toutes'] as $k => $l)
                <a href="{{ $ub(['statut' => $k]) }}" class="{{ $filtre === $k ? 'badge-blue' : 'badge-gray' }} !py-1">{{ $l }}</a>
            @endforeach
        </div>
        <form method="POST" action="{{ route('compta.releves.lignes') }}" class="card overflow-x-auto" x-data="{ tout: false }">@csrf
            {{-- Touche Entrée : enregistrer les contreparties (et non la première action de ligne) --}}
            <button name="action" value="enregistrer" class="sr-only" tabindex="-1" aria-hidden="true">Enregistrer</button>
            <table class="tbl">
                <thead><tr>
                    <th class="w-8"><input type="checkbox" class="rounded border-slate-300" x-model="tout" @change="$root.querySelectorAll('input[name=\'sel[]\']').forEach(c => c.checked = tout)" aria-label="Tout sélectionner"></th>
                    <th>Date</th><th>Libellé</th><th class="text-right">Débit (sortie)</th><th class="text-right">Crédit (entrée)</th><th>Contrepartie</th><th>Statut</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($lignes as $l)
                    <tr class="{{ $l->sens_incertain && $l->statut === 'att' ? 'bg-amber-50/70 dark:bg-amber-900/10' : '' }}">
                        <td><input type="checkbox" name="sel[]" value="{{ $l->id }}" class="rounded border-slate-300" aria-label="Sélectionner"></td>
                        <td class="whitespace-nowrap">{{ dfr($l->date) }}</td>
                        <td class="max-w-xs"><div class="text-sm">{{ $l->libelle }}</div>@if($l->sens_incertain && $l->statut === 'att')<div class="text-xs text-amber-700">⚠ Sens à vérifier (relevé sans solde)</div>@endif</td>
                        <td class="num">{{ n3($l->debit) }}</td>
                        <td class="num">{{ n3($l->credit) }}</td>
                        <td class="min-w-[11rem]">
                            @if($l->statut === 'cpt')
                                <span class="font-mono text-xs">{{ $l->compte }}</span> <span class="text-xs text-slate-500">{{ \App\Services\Compta::libelle($l->compte) }}</span>
                            @else
                                <input name="comptes[{{ $l->id }}]" value="{{ $l->compte }}" list="cpts-bq" class="input !py-1 font-mono text-xs" aria-label="Contrepartie">
                                <div class="mt-0.5 truncate text-[11px] text-slate-400">{{ \App\Services\Compta::libelle((string) $l->compte) }}</div>
                            @endif
                        </td>
                        <td>@if($l->statut === 'cpt')<span class="badge-green">Comptabilisée</span>@elseif($l->statut === 'ign')<span class="badge-gray">Ignorée</span>@else<span class="badge-amber">À traiter</span>@endif</td>
                        <td class="text-right">@if($l->statut !== 'cpt')<button name="action" value="inverser" formaction="{{ route('compta.releves.lignes') }}?ligne={{ $l->id }}" class="btn-icon" title="Inverser débit / crédit">⇄</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-empty icon="bank" title="Aucune opération">Importez un relevé bancaire (Excel, CSV, Word ou PDF) avec le bouton en haut de page.</x-empty></td></tr>
                @endforelse
                </tbody>
            </table>
            <datalist id="cpts-bq">@foreach($plan as $n => $lib)<option value="{{ $n }}">{{ $lib }}</option>@endforeach</datalist>
            @if($lignes->count())
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
                    <span class="mr-auto text-xs text-slate-500">Les contreparties modifiées sont enregistrées avec chaque action.</span>
                    <button name="action" value="enregistrer" class="btn-secondary btn-sm">Enregistrer les contreparties</button>
                    <button name="action" value="confirmer" class="btn-secondary btn-sm">Confirmer le sens (sélection)</button>
                    @if($filtre === 'ign')<button name="action" value="retablir" class="btn-secondary btn-sm">Rétablir la sélection</button>
                    @else<button name="action" value="ignorer" class="btn-secondary btn-sm">Ignorer la sélection</button>@endif
                    @if($chef)
                        <button name="action" value="comptabiliser" class="btn-primary btn-sm"><x-icon name="check" class="h-3.5 w-3.5"/> Comptabiliser la sélection</button>
                        @if($filtre === 'att' && $lignes->total() > 0)<input type="hidden" name="releve" value="{{ $releve?->id }}"><button name="action" value="comptabiliser_tout" class="btn-success btn-sm" onclick="return confirm('Comptabiliser les {{ $lignes->total() }} ligne(s) à traiter {{ $releve ? "de ce relevé" : "du dossier" }} qui ont une contrepartie ?')">Comptabiliser tout {{ $releve ? 'le relevé' : '' }} ({{ $lignes->total() }})</button>@endif
                    @endif
                </div>
            @endif
        </form>
        <div class="mt-4">{{ $lignes->links() }}</div>
    </div>
</div>
