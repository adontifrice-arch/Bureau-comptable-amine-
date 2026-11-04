@php $chef = auth()->user()->estChef(); $u = fn($t, $x = []) => route('compta.index', ['tab' => $t, 'annee' => $annee] + $x); @endphp
<x-layouts.app title="Comptabilité">
    <x-page-header title="Comptabilité" :subtitle="$client->nom.' · exercice '.$annee">
        <x-client-picker :clients="$clients" :current="$client" :route="route('compta.index')" :params="['tab' => $tab, 'annee' => $annee]"/>
        <form><input type="hidden" name="tab" value="{{ $tab }}"><input type="number" name="annee" value="{{ $annee }}" class="input w-24" onchange="this.form.submit()"></form>
    </x-page-header>
    @unless($chef)<div class="mb-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800"><x-icon name="lock" class="h-4 w-4"/> Vous saisissez en tant que collaborateur : seule la validation du chef comptable comptabilise les pièces.</div>@endunless
    <x-tabs :active="$tab" :items="['ach' => ['Factures reçues', $u('ach')], 'pai' => ['Paie à comptabiliser', $u('pai')], 'jnl' => ['Journal & écritures', $u('jnl')], 'bal' => ['Balance générale', $u('bal')], 'gl' => ['Grand livre', $u('gl')], 'plan' => ['Plan comptable', $u('plan')]]"/>

    @if($tab === 'ach')
        <div class="mb-4 flex flex-wrap justify-end gap-2">
            @if($ia)<button class="btn-secondary" x-data @click="$dispatch('open-modal', 'ia')"><x-icon name="sparkles" class="h-4 w-4"/> Lire une facture avec l’IA</button>@endif
            <button class="btn-primary" x-data @click="$dispatch('fr-edit', {}); $dispatch('open-modal', 'fr')"><x-icon name="plus" class="h-4 w-4"/> Saisir une facture reçue</button>
        </div>
        <div class="card overflow-x-auto">
            <table class="tbl"><thead><tr><th>Date</th><th>Fournisseur</th><th>Compte</th><th class="text-right">HT</th><th class="text-right">TVA</th><th class="text-right">TTC</th><th>Statut</th><th></th></tr></thead><tbody>
            @forelse($factures as $f)
                <tr>
                    <td>{{ dfr($f->date) }}</td>
                    <td><div class="font-medium">{{ $f->fournisseur }}</div><div class="text-xs text-slate-500">{{ $f->reference }} {{ $f->matricule_fiscal ? '· MF '.$f->matricule_fiscal : '' }} {{ $f->retenue_taux > 0 ? '· RS '.(float)$f->retenue_taux.' %' : '' }}</div>
                        @foreach((array) $f->anomalies as $a)<div class="text-xs text-amber-700">⚠ {{ $a }}</div>@endforeach
                        @if($f->statut === 'rej')<div class="text-xs text-rose-600">Motif : {{ $f->motif_rejet }}</div>@endif</td>
                    <td class="text-xs">{{ $f->compte ? $f->compte.' '.\App\Services\Compta::libelle($f->compte) : '—' }}</td>
                    <td class="num">{{ tnd($f->ht, false) }}</td><td class="num">{{ tnd($f->tva, false) }}</td><td class="num font-semibold">{{ tnd($f->ttc(), false) }}</td>
                    <td>@if($f->statut === 'val')<span class="badge-green">Comptabilisée</span><div class="mt-0.5 text-[11px] text-slate-400">{{ $f->validePar?->name }}</div>@elseif($f->statut === 'rej')<span class="badge-red">Rejetée</span>@else<span class="badge-amber">À valider</span>@endif</td>
                    <td class="whitespace-nowrap text-right">
                        @if($f->fichier)<a href="{{ route('compta.factures.fichier', $f) }}" target="_blank" class="btn-icon" title="Pièce jointe"><x-icon name="eye" class="h-4 w-4"/></a>@endif
                        @if($f->statut !== 'val')
                            @if($chef && $f->statut === 'prop')
                                <form method="POST" action="{{ route('compta.factures.valider', $f) }}" class="inline">@csrf<button class="btn-success btn-sm">Valider</button></form>
                                <form method="POST" action="{{ route('compta.factures.rejeter', $f) }}" class="inline" onsubmit="this.motif.value = prompt('Motif du rejet ?') || ''">@csrf<input type="hidden" name="motif"><button class="btn-secondary btn-sm !text-rose-600">Rejeter</button></form>
                            @endif
                            <button class="btn-icon" x-data @click="$dispatch('fr-edit', @js($f->only(['id','fournisseur','matricule_fiscal','reference','description','ht','tva_taux','tva','timbre','compte','retenue_taux']) + ['date' => $f->date->format('Y-m-d'), 'tva_deductible' => $f->tva_deductible ? '1' : '0'])); $dispatch('open-modal', 'fr')"><x-icon name="edit" class="h-4 w-4"/></button>
                            <x-confirm-delete :action="route('compta.factures.destroy', $f)"/>
                        @endif
                    </td>
                </tr>
            @empty <tr><td colspan="8"><x-empty icon="receipt" title="Aucune facture reçue">Saisissez les factures fournisseurs du client : elles seront comptabilisées après validation.</x-empty></td></tr> @endforelse
            </tbody></table>
        </div>
        @php $imp = collect($plan)->filter(fn($l, $n) => preg_match('/^[26]/', $n) && !str_starts_with($n, '228')); @endphp
        <x-modal name="fr" title="Facture fournisseur" size="max-w-3xl">
            <form method="POST" enctype="multipart/form-data" x-data="{ e: {} }" @fr-edit.window="e = Object.assign({tva_taux: 19, timbre: 1, tva_deductible: '1', date: '{{ now()->format('Y-m-d') }}', retenue_taux: 0}, $event.detail)"
                  :action="e.id ? '{{ url('comptabilite/factures') }}/' + e.id : '{{ route('compta.factures.store') }}'" class="grid gap-4 md:grid-cols-3">@csrf
                <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
                <div><label class="label">Date *</label><input type="date" name="date" class="input" x-model="e.date" required></div>
                <div class="md:col-span-2"><label class="label">Fournisseur *</label><input name="fournisseur" class="input" x-model="e.fournisseur" required></div>
                <div><label class="label">Matricule fiscal</label><input name="matricule_fiscal" class="input" x-model="e.matricule_fiscal"></div>
                <div><label class="label">N° de facture</label><input name="reference" class="input" x-model="e.reference"></div>
                <div><label class="label">Description</label><input name="description" class="input" x-model="e.description"></div>
                <div><label class="label">Montant HT *</label><input type="number" step="0.001" name="ht" class="input" x-model="e.ht" @input="e.tva = (Math.round((+e.ht||0) * (+e.tva_taux||0) * 10) / 1000).toFixed(3)" required></div>
                <div><label class="label">Taux TVA (%)</label><input type="number" step="0.01" name="tva_taux" class="input" x-model="e.tva_taux" @input="e.tva = (Math.round((+e.ht||0) * (+e.tva_taux||0) * 10) / 1000).toFixed(3)"></div>
                <div><label class="label">Montant TVA</label><input type="number" step="0.001" name="tva" class="input" x-model="e.tva"></div>
                <div><label class="label">Droit de timbre</label><input type="number" step="0.001" name="timbre" class="input" x-model="e.timbre"></div>
                <div class="md:col-span-2"><label class="label">Compte de charge / immobilisation</label><select name="compte" class="input" x-model="e.compte"><option value="">— à choisir —</option>@foreach($imp as $n => $l)<option value="{{ $n }}">{{ $n }} {{ $l }}</option>@endforeach</select></div>
                <div><label class="label">TVA récupérable</label><select name="tva_deductible" class="input" x-model="e.tva_deductible"><option value="1">Oui (4367)</option><option value="0">Non (incluse en charge)</option></select></div>
                <div><label class="label">Retenue à la source (%)</label><input type="number" step="0.01" name="retenue_taux" class="input" x-model="e.retenue_taux"></div>
                <div><label class="label">Pièce (PDF / image)</label><input type="file" name="fichier" accept=".pdf,image/*" class="input"></div>
                <div class="flex justify-end md:col-span-3"><button class="btn-primary">Enregistrer (en attente de validation)</button></div>
            </form>
        </x-modal>
        @if($ia)
        <x-modal name="ia" title="Lecture d’une facture par l’IA">
            <form method="POST" action="{{ route('compta.factures.store') }}" enctype="multipart/form-data" class="space-y-4">@csrf <input type="hidden" name="ia" value="1">
                <p class="text-sm text-slate-500">Les propositions de l’IA sont des suggestions : seule la validation du chef comptable comptabilise la facture.</p>
                <div><label class="label">Photo ou PDF de la facture</label><input type="file" name="fichier" accept=".pdf,image/*" class="input"></div>
                <div><label class="label">ou texte de la facture</label><textarea name="texte" rows="5" class="input"></textarea></div>
                <div class="flex justify-end"><button class="btn-primary"><x-icon name="sparkles" class="h-4 w-4"/> Analyser</button></div>
            </form>
        </x-modal>
        @endif

    @elseif($tab === 'pai')
        <div class="card">
            @forelse($paies as $p)
                <div class="flex flex-wrap items-center gap-4 border-b border-slate-100 px-5 py-4 last:border-0 dark:border-slate-800">
                    <div class="min-w-0 flex-1"><div class="font-medium capitalize">Paie de {{ mois_fr($p->mois) }}</div><div class="text-xs text-slate-500">{{ count($p->resultats ?? []) }} salarié(s) · brut {{ tnd($p->totaux['brut'] ?? 0) }} · net {{ tnd($p->totaux['net'] ?? 0) }}</div></div>
                    @if($p->statut === 'val')<span class="badge-green">Comptabilisée</span>
                    @else <span class="badge-amber">Transmise</span>
                        @if($chef)<form method="POST" action="{{ route('compta.paies.valider', $p) }}">@csrf<button class="btn-success btn-sm">Valider et comptabiliser</button></form>@endif
                    @endif
                </div>
            @empty <x-empty icon="wallet" title="Aucune paie transmise">Clôturez une paie dans l’onglet Paie pour la transmettre à la comptabilité.</x-empty> @endforelse
        </div>

    @elseif($tab === 'jnl')
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1">@foreach(['' => 'Tous', 'ACH' => 'Achats', 'VTE' => 'Ventes', 'PAI' => 'Paie', 'BQ' => 'Banque', 'OD' => 'OD'] as $k => $l)<a href="{{ $u('jnl', ['journal' => $k]) }}" class="{{ request('journal', '') === $k ? 'badge-blue' : 'badge-gray' }} !py-1">{{ $l }}</a>@endforeach</div>
            @if($chef)<button class="btn-primary" x-data @click="$dispatch('open-modal', 'od')"><x-icon name="plus" class="h-4 w-4"/> Saisir une écriture</button>@endif
        </div>
        <div class="space-y-3">
            @forelse($ecritures as $e)
                <div class="card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 text-sm dark:border-slate-800 dark:bg-slate-800/30">
                        <span class="badge-blue">{{ $e->journal }}</span><span class="font-mono text-xs">{{ dfr($e->date) }}</span><span class="flex-1 font-medium">{{ $e->libelle }}</span>
                        <span class="text-xs text-slate-400">{{ $e->validePar ? 'validée par '.$e->validePar->name : '' }}</span>
                        @if($chef)<form method="POST" action="{{ route('compta.ecritures.annuler', $e) }}" onsubmit="return confirm('Annuler cette écriture ?')">@csrf<button class="btn-ghost btn-sm !text-rose-600">Annuler</button></form>@endif
                    </div>
                    <table class="w-full text-sm">@foreach($e->lignes as $l)<tr class="border-b border-slate-50 last:border-0 dark:border-slate-800"><td class="w-24 px-4 py-1.5 font-mono text-xs">{{ $l->compte }}</td><td class="px-2 py-1.5 text-slate-500 {{ $l->credit > 0 ? 'pl-10' : '' }}">{{ \App\Services\Compta::libelle($l->compte) }}</td><td class="num w-36 px-4 py-1.5">{{ n3($l->debit) }}</td><td class="num w-36 px-4 py-1.5">{{ n3($l->credit) }}</td></tr>@endforeach</table>
                </div>
            @empty <div class="card"><x-empty icon="book" title="Aucune écriture sur {{ $annee }}"/></div> @endforelse
        </div>
        <div class="mt-4">{{ $ecritures->links() }}</div>
        <x-modal name="od" title="Écriture manuelle" size="max-w-3xl">
            <form method="POST" action="{{ route('compta.od.store') }}" x-data="{ l: [{compte:'',debit:'',credit:''},{compte:'',debit:'',credit:''}], get d(){return this.l.reduce((s,x)=>s+(+x.debit||0),0)}, get c(){return this.l.reduce((s,x)=>s+(+x.credit||0),0)} }" class="space-y-4">@csrf
                <div class="grid gap-4 md:grid-cols-4">
                    <div><label class="label">Date</label><input type="date" name="date" class="input" value="{{ now()->format('Y-m-d') }}"></div>
                    <div><label class="label">Journal</label><select name="journal" class="input">@foreach(['OD' => 'Opérations diverses', 'VTE' => 'Ventes', 'ACH' => 'Achats', 'BQ' => 'Banque', 'CAI' => 'Caisse', 'AN' => 'À-nouveaux'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="md:col-span-2"><label class="label">Libellé</label><input name="libelle" class="input" required></div>
                </div>
                <datalist id="cpts">@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>
                <template x-for="(x, i) in l" :key="i"><div class="grid grid-cols-12 gap-2">
                    <input class="input col-span-5" list="cpts" placeholder="Compte" :name="`lignes[${i}][compte]`" x-model="x.compte">
                    <input type="number" step="0.001" class="input col-span-3" placeholder="Débit" :name="`lignes[${i}][debit]`" x-model="x.debit">
                    <input type="number" step="0.001" class="input col-span-3" placeholder="Crédit" :name="`lignes[${i}][credit]`" x-model="x.credit">
                    <button type="button" class="btn-icon col-span-1" @click="l.splice(i,1)"><x-icon name="trash" class="h-4 w-4"/></button>
                </div></template>
                <div class="flex items-center justify-between">
                    <button type="button" class="btn-secondary btn-sm" @click="l.push({compte:'',debit:'',credit:''})">+ Ligne</button>
                    <div class="text-sm">Débit <b class="font-mono" x-text="tnd(d)"></b> · Crédit <b class="font-mono" x-text="tnd(c)"></b> <span x-show="Math.abs(d-c) > 0.0005" class="badge-red ml-2">Déséquilibrée</span><span x-show="d > 0 && Math.abs(d-c) <= 0.0005" class="badge-green ml-2">Équilibrée</span></div>
                </div>
                <div class="flex justify-end"><button class="btn-primary">Enregistrer</button></div>
            </form>
        </x-modal>

    @elseif($tab === 'bal')
        <div class="mb-4 flex justify-end gap-2">
            <a href="{{ route('compta.balance.csv', ['annee' => $annee]) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel (CSV)</a>
            <a href="{{ route('compta.balance.print', ['annee' => $annee]) }}" target="_blank" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</a>
        </div>
        @include('compta._balance')

    @elseif($tab === 'gl')
        <form class="card mb-4 flex flex-wrap items-end gap-3 p-4"><input type="hidden" name="tab" value="gl"><input type="hidden" name="annee" value="{{ $annee }}">
            <div class="w-72"><label class="label">Compte (ou racine, ex. 6)</label><input name="compte" value="{{ $compte }}" class="input" list="cpts2"><datalist id="cpts2">@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist></div>
            <button class="btn-primary">Afficher</button>
        </form>
        @if($compte)
            @php $s = 0; @endphp
            <div class="card overflow-x-auto"><table class="tbl"><thead><tr><th>Date</th><th>Jnl</th><th>Compte</th><th>Libellé</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Solde</th></tr></thead><tbody>
                @forelse($mouvements as $m) @php $s += $m->debit - $m->credit; @endphp
                    <tr><td>{{ dfr($m->date) }}</td><td>{{ $m->journal }}</td><td class="font-mono text-xs">{{ $m->compte }}</td><td>{{ $m->libelle }}</td><td class="num">{{ n3($m->debit) }}</td><td class="num">{{ n3($m->credit) }}</td><td class="num">{{ tnd($s, false) }}</td></tr>
                @empty <tr><td colspan="7"><x-empty title="Aucun mouvement"/></td></tr> @endforelse
            </tbody>
            @if($mouvements->count())<tfoot><tr><td colspan="4">Totaux</td><td class="num">{{ tnd($mouvements->sum('debit'), false) }}</td><td class="num">{{ tnd($mouvements->sum('credit'), false) }}</td><td class="num">{{ tnd($s, false) }}</td></tr></tfoot>@endif
            </table></div>
        @endif

    @elseif($tab === 'plan')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card lg:col-span-2"><div class="card-h"><h2 class="card-t">Plan comptable</h2><span class="text-xs text-slate-400">Inspiré du système comptable des entreprises</span></div>
                <div class="max-h-[70vh] overflow-y-auto"><table class="tbl"><tbody>@foreach($plan as $n => $l)<tr><td class="w-24 font-mono text-xs">{{ $n }}</td><td>{{ $l }}</td></tr>@endforeach</tbody></table></div></div>
            <form method="POST" action="{{ route('compta.comptes.store') }}" class="card card-b space-y-3 self-start">@csrf
                <h2 class="card-t">Ajouter un compte</h2>
                <x-field label="Numéro" name="numero" required placeholder="6011"/>
                <x-field label="Libellé" name="libelle" required/>
                <button class="btn-primary w-full">Ajouter</button>
            </form>
        </div>
    @endif
</x-layouts.app>
