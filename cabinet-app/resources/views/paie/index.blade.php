@php $u = fn($t) => route('paie.index', ['tab' => $t, 'mois' => $mois]); $lock = $paie && $paie->statut !== 'brouillon'; @endphp
<x-layouts.app title="Paie">
    <x-page-header title="Paie" :subtitle="$client->nom.' · législation tunisienne (taux paramétrables)'">
        <x-client-picker :clients="$clients" :current="$client" :route="route('paie.index')" :params="['tab' => $tab, 'mois' => $mois]"/>
    </x-page-header>
    <x-tabs :active="$tab" :items="['calc' => ['Calcul du mois', $u('calc')], 'sal' => ['Salariés ('.$salaries->count().')', $u('sal')], 'hist' => ['Historique', $u('hist')], 'par' => ['Paramètres', $u('par')]]"/>

    @if($tab === 'calc')
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <form class="flex items-end gap-2"><input type="hidden" name="tab" value="calc"><div><label class="label">Mois de paie</label><input type="month" name="mois" value="{{ $mois }}" class="input" onchange="this.form.submit()"></div></form>
            <div class="flex gap-2">
                @if($paie)<span class="{{ ['brouillon' => 'badge-gray', 'transmise' => 'badge-amber', 'val' => 'badge-green'][$paie->statut] }} self-center">{{ ['brouillon' => 'Brouillon', 'transmise' => 'Transmise à la comptabilité', 'val' => 'Comptabilisée'][$paie->statut] }}</span>@endif
                <a href="{{ route('paie.bulletins', ['mois' => $mois]) }}" class="btn-secondary"><x-icon name="printer" class="h-4 w-4"/> Bulletins de paie</a>
                @unless($lock)<form method="POST" action="{{ route('paie.cloturer') }}" onsubmit="return confirm('Clôturer la paie et la transmettre à la comptabilité ?')">@csrf<input type="hidden" name="mois" value="{{ $mois }}"><button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Clôturer et transmettre</button></form>@endunless
            </div>
        </div>
        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <x-stat label="Masse salariale brute" :value="tnd($T['brut'], true, 0)" icon="wallet"/>
            <x-stat label="Net à payer" :value="tnd($T['net'], true, 0)" icon="check" tone="green"/>
            <x-stat label="Retenues IRPP + CSS" :value="tnd($T['ras'], true, 0)" icon="scale" tone="amber"/>
            <x-stat label="Coût employeur" :value="tnd($T['cout'], true, 0)" icon="trend"/>
        </div>
        @if(count($R))
        <form method="POST" action="{{ route('paie.calcul') }}" class="card overflow-x-auto">@csrf <input type="hidden" name="mois" value="{{ $mois }}">
            <table class="tbl">
                <thead><tr><th>Salarié</th><th class="text-right">Base</th><th class="text-right">Primes imposables</th><th class="text-right">Brut</th><th class="text-right">CNSS ({{ $P['cnssS'] }} %)</th><th class="text-right">IRPP</th><th class="text-right">CSS</th><th class="text-right">Net à payer</th><th class="text-right">Charges patronales</th></tr></thead>
                <tbody>
                @foreach($R as $x)
                    <tr><td><div class="font-medium">{{ $x['nom'] }}</div><div class="text-xs text-slate-500">{{ $x['poste'] }}</div></td>
                        <td class="num">{{ tnd($x['base'], false) }}</td>
                        <td class="text-right">@if($lock)<span class="font-mono">{{ n3($x['prime']) ?: '—' }}</span>@else<input type="number" step="0.001" name="primes[{{ $x['salarie_id'] }}]" value="{{ $x['prime'] ?: '' }}" class="input-sm ml-auto w-28 text-right">@endif</td>
                        <td class="num font-medium">{{ tnd($x['brut'], false) }}</td><td class="num">{{ tnd($x['cnssS'], false) }}</td><td class="num">{{ tnd($x['ir'], false) }}</td><td class="num">{{ tnd($x['css'], false) }}</td>
                        <td class="num font-semibold text-emerald-700 dark:text-emerald-400">{{ tnd($x['net'], false) }}</td><td class="num text-slate-500">{{ tnd($x['cnssP'] + $x['tfp'] + $x['fop'], false) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="border-t-2 border-slate-200"><tr><td>Total</td><td></td><td></td><td class="num">{{ tnd($T['brut'], false) }}</td><td class="num">{{ tnd($T['cnssS'], false) }}</td><td class="num">{{ tnd($T['ir'], false) }}</td><td class="num">{{ tnd($T['css'], false) }}</td><td class="num">{{ tnd($T['net'], false) }}</td><td class="num">{{ tnd($T['cnssP'] + $T['tfp'] + $T['fop'], false) }}</td></tr></tfoot>
            </table>
            @unless($lock)<div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-secondary">Recalculer avec les primes</button></div>@endunless
        </form>
        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <div class="card card-b text-sm"><h3 class="card-t mb-3">Charges patronales du mois</h3>
                <div class="space-y-1.5"><div class="flex justify-between"><span>CNSS patronale ({{ $P['cnssP'] }} %)</span><span class="font-mono">{{ tnd($T['cnssP']) }}</span></div><div class="flex justify-between"><span>TFP ({{ $P['tfp'] }} %)</span><span class="font-mono">{{ tnd($T['tfp']) }}</span></div><div class="flex justify-between"><span>FOPROLOS ({{ $P['fop'] }} %)</span><span class="font-mono">{{ tnd($T['fop']) }}</span></div></div></div>
            <div class="card card-b text-sm"><h3 class="card-t mb-3">Écriture de paie générée à la validation</h3>
                <table class="w-full">@foreach(\App\Services\PaieCalc::lignes($T) as $l)<tr><td class="py-0.5 font-mono text-xs">{{ $l['compte'] }}</td><td class="text-slate-500">{{ \App\Services\Compta::libelle($l['compte']) }}</td><td class="num">{{ n3($l['debit']) }}</td><td class="num">{{ n3($l['credit']) }}</td></tr>@endforeach</table></div>
        </div>
        @else <div class="card"><x-empty icon="users" title="Aucun salarié">Ajoutez les salariés du dossier dans l’onglet Salariés.</x-empty></div> @endif

    @elseif($tab === 'sal')
        <div class="mb-4 flex justify-end"><button class="btn-primary" x-data @click="$dispatch('sal-edit', {}); $dispatch('open-modal', 'sal')"><x-icon name="plus" class="h-4 w-4"/> Ajouter un salarié</button></div>
        <div class="card overflow-x-auto"><table class="tbl"><thead><tr><th>Salarié</th><th>CIN</th><th>N° CNSS</th><th>Situation</th><th class="text-right">Salaire de base</th><th></th></tr></thead><tbody>
            @forelse($salaries as $e)
                <tr><td><div class="font-medium">{{ $e->nom }}</div><div class="text-xs text-slate-500">{{ $e->poste }}</div></td><td>{{ $e->cin ?: '—' }}</td><td>{{ $e->cnss ?: '—' }}</td>
                    <td class="text-xs">{{ $e->chef_famille ? 'Chef de famille' : 'Non chef de famille' }} · {{ $e->enfants }} enfant(s)</td><td class="num">{{ tnd($e->salaire_base, false) }}</td>
                    <td class="text-right whitespace-nowrap"><button class="btn-icon" x-data @click="$dispatch('sal-edit', @js($e->only(['id','nom','cin','cnss','poste','salaire_base','enfants']) + ['chef_famille' => $e->chef_famille ? '1' : '0', 'date_embauche' => optional($e->date_embauche)->format('Y-m-d')])); $dispatch('open-modal', 'sal')"><x-icon name="edit" class="h-4 w-4"/></button><x-confirm-delete :action="route('paie.salaries.destroy', $e)"/></td></tr>
            @empty <tr><td colspan="6"><x-empty icon="users" title="Aucun salarié"/></td></tr> @endforelse
        </tbody></table></div>
        <x-modal name="sal" title="Salarié">
            <form method="POST" x-data="{ e: {} }" @sal-edit.window="e = Object.assign({chef_famille: '0', enfants: 0}, $event.detail)" :action="e.id ? '{{ url('paie/salaries') }}/' + e.id : '{{ route('paie.salaries.store') }}'" class="grid gap-4 md:grid-cols-2">@csrf
                <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
                <div class="md:col-span-2"><label class="label">Nom et prénom *</label><input name="nom" class="input" x-model="e.nom" required></div>
                <div><label class="label">CIN</label><input name="cin" class="input" x-model="e.cin"></div>
                <div><label class="label">N° d’affiliation CNSS</label><input name="cnss" class="input" x-model="e.cnss"></div>
                <div><label class="label">Poste</label><input name="poste" class="input" x-model="e.poste"></div>
                <div><label class="label">Date d’embauche</label><input type="date" name="date_embauche" class="input" x-model="e.date_embauche"></div>
                <div><label class="label">Salaire de base mensuel brut *</label><input type="number" step="0.001" name="salaire_base" class="input" x-model="e.salaire_base" required></div>
                <div><label class="label">Chef de famille</label><select name="chef_famille" class="input" x-model="e.chef_famille"><option value="0">Non</option><option value="1">Oui</option></select></div>
                <div><label class="label">Enfants à charge</label><input type="number" name="enfants" class="input" x-model="e.enfants" min="0"></div>
                <div class="flex justify-end md:col-span-2"><button class="btn-primary">Enregistrer</button></div>
            </form>
        </x-modal>

    @elseif($tab === 'hist')
        <div class="card">
            @forelse($historique as $p)
                <div class="flex items-center gap-4 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <div class="flex-1"><div class="font-medium capitalize">{{ mois_fr($p->mois) }}</div><div class="text-xs text-slate-500">{{ count($p->resultats ?? []) }} salarié(s) · brut {{ tnd($p->totaux['brut'] ?? 0) }} · net {{ tnd($p->totaux['net'] ?? 0) }}</div></div>
                    <span class="{{ $p->statut === 'val' ? 'badge-green' : 'badge-amber' }}">{{ $p->statut === 'val' ? 'Comptabilisée' : 'Transmise' }}</span>
                    <a href="{{ route('paie.index', ['mois' => $p->mois]) }}" class="btn-ghost btn-sm">Voir</a><a href="{{ route('paie.bulletins', ['mois' => $p->mois]) }}" class="btn-ghost btn-sm">Bulletins</a>
                </div>
            @empty <x-empty icon="wallet" title="Aucune paie clôturée"/> @endforelse
        </div>

    @elseif($tab === 'par')
        <form method="POST" action="{{ route('paie.parametres') }}" class="card">@csrf
            <div class="card-h"><h2 class="card-t">Taux et barème</h2><span class="text-xs text-slate-400">Valeurs de départ à vérifier selon la législation en vigueur</span></div>
            <div class="card-b grid gap-4 md:grid-cols-4">
                @foreach(['cnssS' => 'CNSS salariale (%)', 'cnssP' => 'CNSS patronale (%)', 'tfp' => 'TFP (%)', 'fop' => 'FOPROLOS (%)', 'css' => 'Contribution sociale de solidarité (%)', 'frais' => 'Frais professionnels (%)', 'fraisMax' => 'Plafond frais professionnels (TND / an)', 'chef' => 'Déduction chef de famille (TND / an)', 'enf' => 'Déduction par enfant (TND / an)', 'enfMax' => 'Nombre max. d’enfants'] as $k => $l)
                    <x-field :label="$l" :name="$k" type="number" step="any" :value="$P[$k]"/>
                @endforeach
                <div class="md:col-span-4"><label class="label">Barème annuel IRPP (une tranche par ligne : plafond taux)</label><textarea name="bar" rows="8" class="input font-mono">{{ $P['bar'] }}</textarea></div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 p-4 dark:border-slate-800">
                @if(auth()->user()->estChef())<button name="reset" value="1" class="btn-secondary">Rétablir les valeurs de départ</button><button class="btn-primary">Enregistrer</button>@else<span class="text-sm text-slate-500">Modification réservée au chef comptable.</span>@endif
            </div>
        </form>
    @endif
</x-layouts.app>
