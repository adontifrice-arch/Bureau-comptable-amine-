@php
    use App\Services\Amortissement as A;
    $chef = auth()->user()->estChef();
    $u = fn ($t, $x = []) => route('immobilisations.index', ['tab' => $t, 'exercice' => $exercice] + $x);
    $natures = collect(A::NATURES)->map(fn ($n) => ['libelle' => $n[0], 'compte' => $n[1], 'amort' => $n[2], 'taux' => $n[3]]);
    $aComptabiliser = $etat->filter(fn ($l) => $l['ecart'] > 0.0005 && $l['immo']->amortissable());
    $aRegulariser = $etat->filter(fn ($l) => $l['ecart'] < -0.0005);
@endphp
<x-layouts.app title="Immobilisations">
    <x-page-header title="Immobilisations et amortissements" :subtitle="$client->nom.' · exercice '.$exercice">
        <x-client-picker :clients="$clients" :current="$client" :route="route('immobilisations.index')" :params="['tab' => $tab, 'exercice' => $exercice]"/>
        <form><input type="hidden" name="tab" value="{{ $tab }}"><label class="sr-only" for="exo">Exercice</label><input id="exo" type="number" name="exercice" value="{{ $exercice }}" class="input w-24" onchange="this.form.submit()"></form>
    </x-page-header>
    @unless($chef)<div class="mb-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800"><x-icon name="lock" class="h-4 w-4"/> Vous saisissez en tant que collaborateur : la comptabilisation des dotations et des acquisitions est réservée au chef comptable.</div>@endunless

    <x-tabs :active="$tab" :items="['registre' => ['Registre ('.$biens->count().')', $u('registre')], 'dotations' => ['Dotations '.$exercice, $u('dotations')], 'etats' => ['États imprimables', $u('etats')]]"/>

    <div x-data="{ e: {}, natures: @js($natures), nouveau() { this.e = { nature: 'informatique', methode: 'lineaire', prorata: 'jour', taux: 33.33, date_mise_service: '{{ now()->format('Y-m-d') }}' }; this.$dispatch('open-modal', 'immo') } }">
    @if($tab === 'registre')
        <div class="mb-4 flex flex-wrap justify-end gap-2">
            @if($aImporter->count())<button class="btn-secondary" @click="$dispatch('open-modal', 'import')"><x-icon name="download" class="h-4 w-4"/> Importer des factures reçues ({{ $aImporter->count() }})</button>@endif
            <button class="btn-primary" @click="nouveau()"><x-icon name="plus" class="h-4 w-4"/> Ajouter une immobilisation</button>
        </div>
        <div class="card overflow-x-auto">
            @if($biens->count())
            <table class="tbl">
                <thead><tr><th>Bien</th><th>Nature</th><th>Mise en service</th><th>Méthode</th><th class="text-right">Valeur d’origine</th><th class="text-right">VNC 31/12/{{ $exercice }}</th><th>Acquisition</th><th></th></tr></thead>
                <tbody>
                @foreach($biens as $i)
                    @php $s = A::situation($i, $exercice); @endphp
                    <tr>
                        <td><div class="font-medium">{{ $i->designation }}</div><div class="text-xs text-slate-500">{{ $i->fournisseur }}{{ $i->reference ? ' · '.$i->reference : '' }}{{ $i->date_cession ? ' · cédé le '.dfr($i->date_cession) : '' }}</div></td>
                        <td class="text-xs">{{ $i->libelleNature() }}<div class="text-slate-400">{{ $i->compteImmobilisation() }}{{ $i->compteAmortissement() ? ' / '.$i->compteAmortissement() : '' }}</div></td>
                        <td>{{ dfr($i->date_mise_service) }}</td>
                        <td class="text-xs">@if($i->amortissable()){{ A::METHODES[$i->methode] }} {{ number_format($i->taux, 2, ',', ' ') }} %<div class="text-slate-400">prorata {{ mb_strtolower(A::PRORATAS[$i->prorata]) }}{{ $i->methode === 'degressif' ? ' · coef. '.number_format(A::coefficient($i->taux), 1, ',', '') : '' }}</div>@else<span class="badge-gray">Non amortissable</span>@endif</td>
                        <td class="num">{{ tnd($i->valeur_origine, false) }}</td>
                        <td class="num font-semibold">{{ $i->date_mise_service->year <= $exercice ? tnd($s['vnc'], false) : '—' }}</td>
                        <td class="text-xs">
                            @if($i->facture_recue_id)<span class="badge-green">Facture reçue</span>
                            @elseif($i->acquisition_ecriture_id)<span class="badge-green">Comptabilisée</span>
                            @elseif($chef)<form method="POST" action="{{ route('immobilisations.acquisition', $i) }}" onsubmit="return confirm('Passer l’écriture d’acquisition (D {{ $i->compteImmobilisation() }}{{ $i->tva > 0 ? ' + D 4366' : '' }} / C 401) ?')">@csrf<button class="btn-secondary btn-sm">Comptabiliser</button></form>
                            @else<span class="badge-amber">À comptabiliser</span>@endif
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <button class="btn-icon" title="Modifier" @click="e = @js($i->only(['id', 'nature', 'designation', 'valeur_origine', 'tva', 'fournisseur', 'reference', 'methode', 'taux', 'prorata', 'valeur_residuelle']) + ['date_mise_service' => $i->date_mise_service->format('Y-m-d'), 'date_cession' => $i->date_cession?->format('Y-m-d')]); $dispatch('open-modal', 'immo')"><x-icon name="edit" class="h-4 w-4"/></button>
                            @if($i->dotationsComptabilisees->isEmpty() && ! $i->acquisition_ecriture_id)<x-confirm-delete :action="route('immobilisations.destroy', $i)" message="Supprimer cette immobilisation du registre ?"/>@else<span class="btn-icon cursor-not-allowed opacity-40" title="Écritures comptabilisées : suppression impossible"><x-icon name="lock" class="h-4 w-4"/></span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @else
                <x-empty icon="briefcase" title="Aucune immobilisation">Ajoutez les biens du dossier ou importez-les depuis les factures reçues validées (comptes de classe 2).</x-empty>
            @endif
        </div>

    @elseif($tab === 'dotations')
        @if($aRegulariser->count())
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <b>À régulariser :</b> la dotation recalculée est inférieure au montant déjà comptabilisé pour
                {{ $aRegulariser->map(fn ($l) => $l['immo']->designation.' ('.tnd(-$l['ecart']).')')->implode(', ') }}.
                Annulez les dotations {{ $exercice }} puis comptabilisez à nouveau, ou passez une écriture de régularisation.
            </div>
        @endif
        <div class="card overflow-x-auto">
            <div class="card-h">
                <h2 class="card-t">Dotations de l’exercice {{ $exercice }}</h2>
                @if($chef)
                    <div class="flex gap-2">
                        @if($ecritures->count())<button class="btn-secondary btn-sm !text-rose-600" @click="$dispatch('open-modal', 'annuler')">Annuler les dotations {{ $exercice }}</button>@endif
                        <form method="POST" action="{{ route('immobilisations.comptabiliser') }}">@csrf<input type="hidden" name="exercice" value="{{ $exercice }}">
                            <button class="btn-primary btn-sm" @disabled($aComptabiliser->isEmpty())><x-icon name="check" class="h-3.5 w-3.5"/> {{ $ecritures->count() && $aComptabiliser->count() ? 'Comptabiliser le complément' : 'Comptabiliser' }} ({{ tnd($aComptabiliser->sum('ecart')) }})</button>
                        </form>
                    </div>
                @endif
            </div>
            <table class="tbl">
                <thead><tr><th>Bien</th><th>Compte</th><th class="text-right">Valeur d’origine</th><th class="text-right">Amort. antérieurs</th><th class="text-right">Dotation {{ $exercice }}</th><th class="text-right">Déjà comptabilisé</th><th class="text-right">Reste à passer</th><th class="text-right">VNC fin</th></tr></thead>
                <tbody>
                @forelse($etat as $l)
                    <tr>
                        <td class="font-medium">{{ $l['immo']->designation }}<div class="text-xs font-normal text-slate-500">{{ $l['immo']->libelleNature() }}</div></td>
                        <td class="text-xs">{{ $l['immo']->compteAmortissement() ? '6811 / '.$l['immo']->compteAmortissement() : '—' }}</td>
                        <td class="num">{{ tnd($l['immo']->valeur_origine, false) }}</td>
                        <td class="num">{{ tnd($l['anterieur'], false) }}</td>
                        <td class="num font-semibold">{{ tnd($l['dotation'], false) }}</td>
                        <td class="num">{{ tnd($l['deja'], false) }}</td>
                        <td class="num {{ $l['ecart'] > 0.0005 ? 'text-amber-700 font-semibold' : ($l['ecart'] < -0.0005 ? 'text-rose-600' : 'text-slate-400') }}">{{ tnd($l['ecart'], false) }}</td>
                        <td class="num">{{ tnd($l['vnc'], false) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-sm text-slate-500">Aucune immobilisation en service en {{ $exercice }}.</td></tr>
                @endforelse
                </tbody>
                @if($etat->count())
                <tfoot><tr class="font-semibold"><td colspan="2">Total</td><td class="num">{{ tnd($etat->sum(fn ($l) => $l['immo']->valeur_origine), false) }}</td><td class="num">{{ tnd($etat->sum('anterieur'), false) }}</td><td class="num">{{ tnd($etat->sum('dotation'), false) }}</td><td class="num">{{ tnd($etat->sum('deja'), false) }}</td><td class="num">{{ tnd($etat->sum('ecart'), false) }}</td><td class="num">{{ tnd($etat->sum('vnc'), false) }}</td></tr></tfoot>
                @endif
            </table>
        </div>

        <div class="card mt-6">
            <div class="card-h"><h2 class="card-t">Écritures du journal AMO – {{ $exercice }}</h2></div>
            @forelse($ecritures as $e)
                <div class="border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                    <div class="flex justify-between"><span class="font-medium">{{ dfr($e->date) }} · {{ $e->libelle }}</span><span class="text-xs text-slate-500">{{ $e->validePar?->name }} · {{ $e->created_at->format('d/m/Y H:i') }}</span></div>
                    <table class="mt-1 w-full text-xs">@foreach($e->lignes as $li)<tr><td class="w-24 font-mono">{{ $li->compte }}</td><td>{{ \App\Services\Compta::libelle($li->compte) }}</td><td class="num w-32">{{ $li->debit > 0 ? tnd($li->debit, false) : '' }}</td><td class="num w-32">{{ $li->credit > 0 ? tnd($li->credit, false) : '' }}</td></tr>@endforeach</table>
                </div>
            @empty
                <x-empty icon="book" title="Aucune dotation comptabilisée pour {{ $exercice }}"/>
            @endforelse
        </div>

        @if($chef && $ecritures->count())
        <x-modal name="annuler" title="Annuler les dotations {{ $exercice }}">
            <form method="POST" action="{{ route('immobilisations.annuler') }}" class="space-y-4">@csrf<input type="hidden" name="exercice" value="{{ $exercice }}">
                <p class="text-sm">Les {{ $ecritures->count() }} écriture(s) du journal AMO de {{ $exercice }}, soit <b>{{ tnd($ecritures->sum(fn ($e) => $e->lignes->sum('debit'))) }}</b>, seront supprimées. Les dotations pourront ensuite être comptabilisées à nouveau.</p>
                <div><label class="label" for="conf">Pour confirmer, saisissez l’exercice <b>{{ $exercice }}</b></label><input id="conf" name="confirmation" class="input w-32" autocomplete="off" required></div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="$dispatch('close-modal')">Ne rien faire</button><button class="btn-danger">Annuler les dotations</button></div>
            </form>
        </x-modal>
        @endif

    @else
        <div class="grid gap-6 md:grid-cols-2">
            @foreach(['tableau' => ['Tableau des immobilisations et amortissements', 'Valeurs d’origine, amortissements antérieurs, dotation '.$exercice.', cumul et VNC par nature, avec sous-totaux.'], 'plan' => ['Plan d’amortissement prévisionnel sur 5 ans', 'Dotations '.$exercice.' à '.($exercice + 4).' de chaque bien amortissable et VNC finale.']] as $k => [$t, $d])
                <div class="card card-b">
                    <h2 class="card-t">{{ $t }}</h2><p class="mt-1 text-sm text-slate-500">{{ $d }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a class="btn-secondary btn-sm" target="_blank" href="{{ route('immobilisations.etat', [$k, 'imprimer', 'exercice' => $exercice]) }}"><x-icon name="printer" class="h-3.5 w-3.5"/> Imprimer</a>
                        <a class="btn-secondary btn-sm" target="_blank" href="{{ route('immobilisations.etat', [$k, 'pdf', 'exercice' => $exercice]) }}"><x-icon name="download" class="h-3.5 w-3.5"/> PDF</a>
                        <a class="btn-secondary btn-sm" href="{{ route('immobilisations.etat', [$k, 'xlsx', 'exercice' => $exercice]) }}"><x-icon name="download" class="h-3.5 w-3.5"/> Excel</a>
                        <a class="btn-secondary btn-sm" href="{{ route('immobilisations.etat', [$k, 'docx', 'exercice' => $exercice]) }}"><x-icon name="download" class="h-3.5 w-3.5"/> Word</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

        {{-- Saisie / modification d'une immobilisation --}}
        <x-modal name="immo" title="Immobilisation" size="max-w-3xl">
            <form method="POST" :action="e.id ? '{{ url('immobilisations') }}/' + e.id : '{{ route('immobilisations.store') }}'" class="grid gap-4 md:grid-cols-3">
                @csrf <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
                <div class="md:col-span-2"><label class="label" for="i-des">Désignation *</label><input id="i-des" name="designation" class="input" x-model="e.designation" required></div>
                <div><label class="label" for="i-nat">Nature *</label>
                    <select id="i-nat" name="nature" class="input" x-model="e.nature" @change="e.taux = natures[e.nature].taux">
                        <template x-for="(n, k) in natures" :key="k"><option :value="k" x-text="n.libelle + ' (' + n.compte + ')'" :selected="k === e.nature"></option></template>
                    </select></div>
                <div><label class="label" for="i-date">Date de mise en service *</label><input id="i-date" type="date" name="date_mise_service" class="input" x-model="e.date_mise_service" required></div>
                <div><label class="label" for="i-vo">Valeur d’origine HT (TND) *</label><input id="i-vo" type="number" step="0.001" min="0" name="valeur_origine" class="input" x-model="e.valeur_origine" required></div>
                <div><label class="label" for="i-tva">TVA déductible (TND)</label><input id="i-tva" type="number" step="0.001" min="0" name="tva" class="input" x-model="e.tva"></div>
                <div><label class="label" for="i-four">Fournisseur</label><input id="i-four" name="fournisseur" class="input" x-model="e.fournisseur"></div>
                <div><label class="label" for="i-ref">Référence</label><input id="i-ref" name="reference" class="input" x-model="e.reference"></div>
                <div><label class="label" for="i-ces">Date de cession</label><input id="i-ces" type="date" name="date_cession" class="input" x-model="e.date_cession"></div>
                <template x-if="e.nature !== 'terrain'"><div class="grid gap-4 md:col-span-3 md:grid-cols-4">
                    <div><label class="label" for="i-meth">Méthode</label><select id="i-meth" name="methode" class="input" x-model="e.methode">@foreach(A::METHODES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label class="label" for="i-taux">Taux linéaire (%)</label><input id="i-taux" type="number" step="0.01" min="0" max="100" name="taux" class="input" x-model="e.taux">
                        <p class="mt-1 text-xs text-slate-400" x-show="+e.taux > 0">Durée <span x-text="(100 / e.taux).toFixed(1).replace('.', ',')"></span> ans<span x-show="e.methode === 'degressif'"> · coef. <span x-text="(100 / e.taux) <= 4 ? '1,5' : ((100 / e.taux) <= 6 ? '2' : '2,5')"></span></span></p></div>
                    <div><label class="label" for="i-pro">Prorata temporis</label><select id="i-pro" name="prorata" class="input" x-model="e.prorata">@foreach(A::PRORATAS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label class="label" for="i-res">Valeur résiduelle</label><input id="i-res" type="number" step="0.001" min="0" name="valeur_residuelle" class="input" x-model="e.valeur_residuelle"></div>
                </div></template>
                <template x-if="e.nature === 'terrain'"><div class="md:col-span-3 text-sm text-slate-500"><input type="hidden" name="methode" value="lineaire"><input type="hidden" name="prorata" value="jour">Les terrains ne sont pas amortissables.</div></template>
                <div class="flex justify-end md:col-span-3"><button class="btn-primary">Enregistrer</button></div>
            </form>
        </x-modal>

        {{-- Import depuis les factures reçues validées --}}
        @if($aImporter->count())
        <x-modal name="import" title="Importer des factures reçues validées" size="max-w-3xl">
            <form method="POST" action="{{ route('immobilisations.importer') }}" class="space-y-4">@csrf
                <p class="text-sm text-slate-500">Factures validées imputées en classe 2. L’acquisition est déjà comptabilisée par la facture ; la nature et le taux sont proposés d’après le compte.</p>
                <table class="tbl"><thead><tr><th></th><th>Date</th><th>Fournisseur</th><th>Compte</th><th>Nature proposée</th><th class="text-right">HT</th></tr></thead><tbody>
                @foreach($aImporter as $f)
                    <tr><td><input type="checkbox" name="factures[]" value="{{ $f->id }}" checked class="rounded border-slate-300 text-brand-600" aria-label="Importer la facture {{ $f->reference }}"></td>
                        <td>{{ dfr($f->date) }}</td><td>{{ $f->fournisseur }}<div class="text-xs text-slate-500">{{ $f->description ?: $f->reference }}</div></td>
                        @php $immo = $f->lignesImmobilisations(); @endphp<td>{{ implode(', ', array_unique(array_column($immo, 'compte'))) }}</td><td class="text-xs">{{ A::NATURES[A::natureDuCompte($immo[0]['compte'], $f->description.' '.$f->fournisseur)][0] }}</td><td class="num">{{ tnd(array_sum(array_column($immo, 'ht')), false) }}</td></tr>
                @endforeach
                </tbody></table>
                <div class="flex justify-end"><button class="btn-primary">Importer la sélection</button></div>
            </form>
        </x-modal>
        @endif
    </div>
</x-layouts.app>
