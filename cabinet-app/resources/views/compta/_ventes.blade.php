{{-- Factures de vente du dossier : saisie (avec modèles), validation par le chef comptable → journal VTE --}}
@php
    $pv = $saisie['vente'];
    $mVte = $modeles->where('type', 'vente');
    $produits = collect($plan)->filter(fn ($l, $n) => str_starts_with($n, '7') && strlen($n) >= 3);
@endphp
<div class="mb-4 flex flex-wrap justify-end gap-2">
    <button class="btn-primary" x-data @click="$dispatch('fv-edit', {}); $dispatch('open-modal', 'fv')"><x-icon name="plus" class="h-4 w-4"/> Saisir une facture de vente</button>
</div>
<div class="card overflow-x-auto">
    <table class="tbl"><thead><tr><th>Date</th><th>Client</th><th>Compte</th><th class="text-right">HT</th><th class="text-right">TVA</th><th class="text-right">TTC</th><th>Statut</th><th></th></tr></thead><tbody>
    @forelse($ventes as $f)
        <tr>
            <td>{{ dfr($f->date) }}</td>
            <td><div class="font-medium">{{ $f->tiers }}</div><div class="text-xs text-slate-500">{{ $f->reference }} {{ $f->matricule_fiscal ? '· MF '.$f->matricule_fiscal : '' }} {{ $f->description ? '· '.$f->description : '' }}</div>
                @if($f->statut === 'rej')<div class="text-xs text-rose-600">Motif : {{ $f->motif_rejet }}</div>@endif</td>
            <td class="text-xs">{{ $f->compte ? $f->compte.' '.\App\Services\Compta::libelle($f->compte) : '—' }}</td>
            <td class="num">{{ tnd($f->ht, false) }}</td><td class="num">{{ tnd($f->tva, false) }}</td><td class="num font-semibold">{{ tnd($f->ttc(), false) }}</td>
            <td>@if($f->statut === 'val')<span class="badge-green">Comptabilisée</span><div class="mt-0.5 text-[11px] text-slate-400">{{ $f->validePar?->name }}</div>@elseif($f->statut === 'rej')<span class="badge-red">Rejetée</span>@else<span class="badge-amber">À valider</span>@endif</td>
            <td class="whitespace-nowrap text-right">
                @if($f->fichier)<a href="{{ route('compta.ventes.fichier', $f) }}" target="_blank" class="btn-icon" title="Pièce jointe"><x-icon name="eye" class="h-4 w-4"/></a>@endif
                @if($f->statut !== 'val')
                    @if($chef && $f->statut === 'prop')
                        <form method="POST" action="{{ route('compta.ventes.valider', $f) }}" class="inline">@csrf<button class="btn-success btn-sm">Valider</button></form>
                        <form method="POST" action="{{ route('compta.ventes.rejeter', $f) }}" class="inline" onsubmit="this.motif.value = prompt('Motif du rejet ?') || ''">@csrf<input type="hidden" name="motif"><button class="btn-secondary btn-sm !text-rose-600">Rejeter</button></form>
                    @endif
                    <button class="btn-icon" title="Modifier" x-data @click="$dispatch('fv-edit', @js($f->only(['id', 'tiers', 'matricule_fiscal', 'reference', 'description', 'ht', 'tva_taux', 'tva', 'timbre', 'compte']) + ['date' => $f->date->format('Y-m-d')])); $dispatch('open-modal', 'fv')"><x-icon name="edit" class="h-4 w-4"/></button>
                    <x-confirm-delete :action="route('compta.ventes.destroy', $f)"/>
                @endif
            </td>
        </tr>
    @empty <tr><td colspan="8"><x-empty icon="invoice" title="Aucune facture de vente">Saisissez les factures émises par le client : elles seront comptabilisées au journal des ventes après validation.</x-empty></td></tr> @endforelse
    </tbody>
    @if($ventes->count())
        <tfoot><tr class="font-semibold"><td colspan="3">Total</td><td class="num">{{ tnd($ventes->sum('ht'), false) }}</td><td class="num">{{ tnd($ventes->sum('tva'), false) }}</td><td class="num">{{ tnd($ventes->sum(fn ($f) => $f->ttc()), false) }}</td><td colspan="2"></td></tr></tfoot>
    @endif
    </table>
</div>

<x-modal name="fv" title="Facture de vente" size="max-w-3xl">
    <form method="POST" enctype="multipart/form-data"
          x-data="{ e: {}, modeles: @js($mVte->mapWithKeys(fn ($m) => [$m->id => $m->valeurs()])), taux: @js((object) \App\Services\Saisie::tauxParCompte($pv)), calc() { this.e.tva = (Math.round((+this.e.ht||0) * (+this.e.tva_taux||0) * 10) / 1000).toFixed(3) }, appliquer(id) { if (! this.modeles[id]) return; Object.assign(this.e, this.modeles[id]); this.calc() }, choisirCompte(c) { if (c !== undefined) this.e.compte = c; if (this.taux[this.e.compte] !== undefined) { this.e.tva_taux = this.taux[this.e.compte]; this.calc() } } }"
          @fv-edit.window="e = Object.assign({tva_taux: {{ \App\Services\Saisie::tauxParDefaut($pv) }}, timbre: {{ (float) $pv['timbre'] }}, compte: @js((string) $pv['compte']), date: '{{ now()->format('Y-m-d') }}'}, $event.detail)"
          :action="e.id ? '{{ url('comptabilite/ventes') }}/' + e.id : '{{ route('compta.ventes.store') }}'" class="grid gap-4 md:grid-cols-3">@csrf
        <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
        @if($mVte->count())
            <div class="md:col-span-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40"><label class="label" for="fv-mod">Saisie rapide : partir d’un modèle</label>
                <select id="fv-mod" class="input" @change="appliquer($event.target.value); $event.target.value = ''"><option value="">— choisir un modèle —</option>@foreach($mVte as $m)<option value="{{ $m->id }}">{{ $m->nom }}{{ $m->compte ? ' · '.$m->compte : '' }}</option>@endforeach</select></div>
        @endif
        <div><label class="label" for="fv-date">Date *</label><input id="fv-date" type="date" name="date" class="input" x-model="e.date" required></div>
        <div class="md:col-span-2"><label class="label" for="fv-tiers">Client (acheteur) *</label><input id="fv-tiers" name="tiers" class="input" x-model="e.tiers" required></div>
        <div><label class="label" for="fv-mf">Matricule fiscal</label><input id="fv-mf" name="matricule_fiscal" class="input" x-model="e.matricule_fiscal"></div>
        <div><label class="label" for="fv-ref">N° de facture</label><input id="fv-ref" name="reference" class="input" x-model="e.reference"></div>
        <div><label class="label" for="fv-desc">Description</label><input id="fv-desc" name="description" class="input" x-model="e.description"></div>
        <div><label class="label" for="fv-ht">Montant HT *</label><input id="fv-ht" type="number" step="0.001" name="ht" class="input" x-model="e.ht" @input="calc()" required></div>
        <div><label class="label" for="fv-tx">Taux TVA (%)</label><input id="fv-tx" type="number" step="0.01" name="tva_taux" class="input" x-model="e.tva_taux" @input="calc()"></div>
        <div><label class="label" for="fv-tva">Montant TVA (4367)</label><input id="fv-tva" type="number" step="0.001" name="tva" class="input" x-model="e.tva"></div>
        <div><label class="label" for="fv-tim">Droit de timbre ({{ $pv['compte_timbre'] }})</label><input id="fv-tim" type="number" step="0.001" name="timbre" class="input" x-model="e.timbre"></div>
        <div class="md:col-span-2"><label class="label" for="fv-cpt">Compte de vente</label><input id="fv-cpt" name="compte" class="input font-mono" list="cpts-vte" x-model="e.compte" @change="choisirCompte()" data-compte autocomplete="off" placeholder="ex. 705 — un nouveau numéro peut être créé"><p data-compte-libelle class="mt-1 truncate text-xs text-slate-400"></p>
            @if($pv['comptes'])<div class="mt-1 flex flex-wrap gap-1">@foreach($pv['comptes'] as $c)<button type="button" class="!py-0.5" :class="e.compte === @js($c['compte']) ? 'badge-blue' : 'badge-gray'" @click="choisirCompte(@js($c['compte']))" title="{{ \App\Services\Compta::libelle($c['compte']) }}">{{ $c['compte'] }} · {{ rtrim(rtrim(number_format($c['tva_taux'], 2, ',', ''), '0'), ',') }} %</button>@endforeach</div>@endif
            @php $propV = collect($pv['comptes'])->pluck('compte')->all(); @endphp
            <datalist id="cpts-vte" data-plan>@foreach($pv['comptes'] as $c)<option value="{{ $c['compte'] }}">★ TVA {{ (float) $c['tva_taux'] }} % — {{ \App\Services\Compta::libelle($c['compte']) }}</option>@endforeach @foreach($produits as $n => $l)@continue(in_array((string) $n, $propV, true))<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist></div>
        <div class="md:col-span-2"><label class="label" for="fv-fic">Pièce (PDF / image)</label><input id="fv-fic" type="file" name="fichier" accept=".pdf,image/*" class="input"></div>
        <div class="self-end text-right text-sm">TTC <b class="font-mono" x-text="tnd((+e.ht||0) + (+e.tva||0) + (+e.timbre||0))"></b></div>
        <div class="flex flex-wrap items-center justify-between gap-3 md:col-span-3">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="memoriser" value="1" class="rounded border-slate-300 text-brand-600"> Mémoriser comme modèle pour ce client</label>
            <button class="btn-primary">Enregistrer (en attente de validation)</button>
        </div>
    </form>
</x-modal>
