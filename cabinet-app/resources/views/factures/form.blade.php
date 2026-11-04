@php $edit = $f->exists; $devis = $f->type === 'devis'; @endphp
<x-layouts.app :title="($edit ? 'Modifier ' : 'Nouveau ').($devis ? 'devis' : 'facture')">
    <x-page-header :title="($devis ? 'Devis ' : 'Facture ').$f->numero" :subtitle="$edit ? 'Modifier le document' : ($devis ? 'Nouveau devis' : 'Nouvelle facture')" :back="route('factures.index', ['type' => $f->type])"/>
    <form method="POST" action="{{ $edit ? route('factures.update', $f) : route('factures.store') }}"
          x-data="facture({ lignes: @js(old('lignes', $lignes)), tva: {{ (float) old('tva_taux', $f->tva_taux) }}, timbre: {{ (float) old('timbre', $f->timbre) }}, devis: {{ $devis ? 'true' : 'false' }}, services: @js($services) })"
          class="grid gap-6 xl:grid-cols-3">
        @csrf @if($edit) @method('PUT') @endif
        <input type="hidden" name="type" value="{{ $f->type }}">
        <div class="space-y-6 xl:col-span-2">
            <div class="card">
                <div class="card-h"><h2 class="card-t">En-tête</h2></div>
                <div class="card-b grid gap-4 md:grid-cols-2">
                    <x-select label="Client *" name="client_id" :value="$f->client_id" :options="$clients->pluck('nom', 'id')" required span="md:col-span-2"/>
                    <x-field label="Numéro" name="numero" :value="$f->numero" required/>
                    <x-field label="Date" name="date" type="date" :value="optional($f->date)->format('Y-m-d')" required/>
                    <x-field :label="$devis ? 'Valable jusqu’au' : 'Échéance de paiement'" name="echeance" type="date" :value="optional($f->echeance)->format('Y-m-d')"/>
                    <div><label class="label">Taux de TVA (%)</label><input name="tva_taux" type="number" step="0.01" class="input" x-model="tva"></div>
                    @unless($devis)
                        <div><label class="label">Droit de timbre (TND)</label><input name="timbre" type="number" step="0.001" class="input" x-model="timbre"></div>
                        <div><label class="label">Retenue à la source effectuée</label><select name="retenue_source" class="input"><option value="0">Non</option><option value="1" @selected($f->retenue_source)>Oui</option></select></div>
                    @endunless
                </div>
            </div>
            <div class="card">
                <div class="card-h"><h2 class="card-t">Lignes</h2>
                    <div class="flex gap-2">
                        @php $hon = $clients->pluck('honoraires_mensuels', 'id'); @endphp
                        <button type="button" class="btn-secondary btn-sm" @click="const h = @js($hon)[document.getElementById('f-client_id').value]; if (+h > 0) add('Honoraires de tenue comptable – {{ now()->locale('fr')->translatedFormat('F Y') }}', +h)">+ Honoraires mensuels du client</button>
                        <button type="button" class="btn-secondary btn-sm" @click="add()"><x-icon name="plus" class="h-3.5 w-3.5"/> Ligne</button>
                    </div>
                </div>
                <div class="card-b space-y-3">
                    <template x-for="(l, i) in lignes" :key="i">
                        <div class="grid grid-cols-12 items-end gap-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <div class="col-span-12">
                                <select class="input-sm mb-2" @change="pick(i, $event)"><option value="">Choisir un service du cabinet…</option><template x-for="s in services" :key="s.id"><option :value="s.id" x-text="s.nom + (s.prix > 0 ? ' · ' + tnd(s.prix) : '')"></option></template></select>
                                <input class="input" :name="`lignes[${i}][designation]`" x-model="l.designation" placeholder="Désignation">
                            </div>
                            <div class="col-span-3"><label class="label">Qté</label><input type="number" step="0.001" class="input" :name="`lignes[${i}][quantite]`" x-model="l.quantite"></div>
                            <div class="col-span-4"><label class="label">PU HT</label><input type="number" step="0.001" class="input" :name="`lignes[${i}][prix_unitaire]`" x-model="l.prix_unitaire"></div>
                            <div class="col-span-4 pb-2 text-right font-mono text-sm" x-text="tnd((+l.quantite||0) * (+l.prix_unitaire||0))"></div>
                            <div class="col-span-1 pb-1 text-right"><button type="button" class="btn-icon hover:!text-rose-600" @click="remove(i)"><x-icon name="trash" class="h-4 w-4"/></button></div>
                        </div>
                    </template>
                </div>
            </div>
            <div class="card card-b"><x-field label="Notes / conditions (facultatif)" name="notes" type="textarea" :value="$f->notes"/></div>
        </div>
        <div class="xl:sticky xl:top-24 xl:self-start">
            <div class="card">
                <div class="card-h"><h2 class="card-t">Totaux</h2></div>
                <div class="card-b space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Total HT</span><span class="font-mono" x-text="tnd(ht)"></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">TVA (<span x-text="tva"></span> %)</span><span class="font-mono" x-text="tnd(mtva)"></span></div>
                    <div class="flex justify-between" x-show="!devis && +timbre"><span class="text-slate-500">Droit de timbre</span><span class="font-mono" x-text="tnd(timbre)"></span></div>
                    <div class="flex justify-between border-t border-slate-200 pt-3 text-base font-semibold dark:border-slate-700"><span>Total TTC</span><span class="font-mono text-brand-700 dark:text-brand-300" x-text="tnd(ttc)"></span></div>
                </div>
                <div class="border-t border-slate-100 p-5 dark:border-slate-800"><button class="btn-primary w-full"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button></div>
            </div>
        </div>
    </form>
</x-layouts.app>
