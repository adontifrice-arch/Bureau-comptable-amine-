<x-layouts.app title="Dépenses">
    <x-page-header title="Dépenses et retenues à la source" subtitle="Factures fournisseurs du cabinet et certificats de retenue (TEJ)" :back="route('frais.index')">
        <button class="btn-primary" x-data @click="$dispatch('dep-edit', {}); $dispatch('open-modal', 'dep')"><x-icon name="plus" class="h-4 w-4"/> Ajouter une dépense</button>
    </x-page-header>
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Total TTC" :value="tnd($T['ttc'])" icon="receipt"/>
        <x-stat label="Retenues à la source" :value="tnd($T['rs'])" icon="scale" tone="amber"/>
        <x-stat label="Net payé" :value="tnd($T['net'])" icon="check" tone="green"/>
    </div>
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="card xl:col-span-2 overflow-x-auto">
            <table class="tbl"><thead><tr><th>Date</th><th>Fournisseur</th><th class="text-right">TTC</th><th class="text-right">Retenue</th><th class="text-right">Net</th><th></th></tr></thead><tbody>
            @forelse($items as $e)
                @php $c = \App\Http\Controllers\DepenseController::calc($e); @endphp
                <tr><td>{{ dfr($e->date) }}@if($e->date_paiement)<div class="text-xs text-slate-400">payée {{ dfr($e->date_paiement) }}</div>@endif</td>
                    <td><div class="font-medium">{{ $e->fournisseur }}</div><div class="text-xs text-slate-500">{{ $e->reference }} {{ $e->identifiant ? '· '.$e->identifiant : '' }}</div></td>
                    <td class="num">{{ tnd($c['ttc'], false) }}</td><td class="num">{{ $c['rs'] ? tnd($c['rs'], false) : '—' }}</td><td class="num font-semibold">{{ tnd($c['net'], false) }}</td>
                    <td class="whitespace-nowrap text-right">
                        <button class="btn-icon" x-data @click="$dispatch('dep-edit', @js($e->toArray() + ['date' => $e->date->format('Y-m-d'), 'date_paiement' => optional($e->date_paiement)->format('Y-m-d')])); $dispatch('open-modal', 'dep')"><x-icon name="edit" class="h-4 w-4"/></button>
                        <x-confirm-delete :action="route('depenses.destroy', $e)"/></td></tr>
            @empty <tr><td colspan="6"><x-empty icon="receipt" title="Aucune dépense"/></td></tr> @endforelse
            </tbody></table>
        </div>
        <div class="card self-start">
            <div class="card-h"><h2 class="card-t">Dépôt TEJ des retenues</h2></div>
            <form method="GET" action="{{ route('depenses.tej') }}" class="card-b space-y-3">
                <p class="text-sm text-slate-500">Le fichier XML reprend les dépenses avec retenue <b>payées</b> dans le mois choisi. Le certificat doit être déposé au plus tard à la date limite de la déclaration mensuelle.</p>
                <x-field label="Mois de paiement" name="mois" type="month" :value="$mois"/>
                <button class="btn-primary w-full"><x-icon name="download" class="h-4 w-4"/> Générer le fichier TEJ</button>
                <p class="text-xs text-slate-400">Brouillon à valider avec le schéma XSD officiel avant dépôt sur la plateforme TEJ.</p>
            </form>
        </div>
    </div>
    <x-modal name="dep" title="Dépense" size="max-w-3xl">
        <form method="POST" x-data="{ e: {} }" @dep-edit.window="e = Object.assign({type_identifiant:'MatriculeFiscal', tva_taux:19, timbre:1, retenue:false, date:'{{ now()->format('Y-m-d') }}'}, $event.detail); e.retenue = !!+e.retenue"
              :action="e.id ? '{{ url('depenses') }}/' + e.id : '{{ route('depenses.store') }}'" class="grid gap-4 md:grid-cols-3">@csrf
            <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
            <div><label class="label">Date de la facture *</label><input type="date" name="date" class="input" x-model="e.date" required></div>
            <div><label class="label">Date de paiement</label><input type="date" name="date_paiement" class="input" x-model="e.date_paiement"></div>
            <div><label class="label">Référence facture</label><input name="reference" class="input" x-model="e.reference"></div>
            <div class="md:col-span-2"><label class="label">Fournisseur *</label><input name="fournisseur" class="input" x-model="e.fournisseur" required list="fourn"></div>
            <datalist id="fourn">@foreach($fournisseurs as $f)<option value="{{ $f->fournisseur }}">@endforeach</datalist>
            <div><label class="label">Type d’identifiant</label><select name="type_identifiant" class="input" x-model="e.type_identifiant"><option value="MatriculeFiscal">Matricule fiscal</option><option value="CIN">CIN</option><option value="CarteSejour">Carte de séjour</option><option value="NonResident">Non résident</option></select></div>
            <div><label class="label">Identifiant</label><input name="identifiant" class="input" x-model="e.identifiant"></div>
            <div class="md:col-span-2"><label class="label">Adresse</label><input name="adresse" class="input" x-model="e.adresse"></div>
            <div><label class="label">Montant HT *</label><input type="number" step="0.001" name="ht" class="input" x-model="e.ht" required></div>
            <div><label class="label">Taux TVA (%)</label><input type="number" step="0.01" name="tva_taux" class="input" x-model="e.tva_taux"></div>
            <div><label class="label">Droit de timbre</label><input type="number" step="0.001" name="timbre" class="input" x-model="e.timbre"></div>
            <div class="flex items-end"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="retenue" value="1" x-model="e.retenue" class="rounded border-slate-300 text-brand-600"> Retenue à la source</label></div>
            <div x-show="e.retenue"><label class="label">Taux de retenue (%)</label><input type="number" step="0.01" name="retenue_taux" class="input" x-model="e.retenue_taux" placeholder="1,5 – 3 – 10 – 15"></div>
            <div x-show="e.retenue"><label class="label">ou montant forcé</label><input type="number" step="0.001" name="retenue_montant" class="input" x-model="e.retenue_montant"></div>
            <div class="flex justify-end md:col-span-3"><button class="btn-primary">Enregistrer</button></div>
        </form>
    </x-modal>
</x-layouts.app>
