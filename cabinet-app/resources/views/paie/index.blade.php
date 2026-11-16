@php $G = \App\Models\Setting::paie(); $u = fn($t) => route('paie.index', ['tab' => $t, 'mois' => $mois]); $lock = $paie && $paie->statut !== 'brouillon'; @endphp
<x-layouts.app title="Paie">
    <x-page-header title="Paie" :subtitle="$client->nom.' · législation tunisienne (taux paramétrables)'">
        <x-client-picker :clients="$clients" :current="$client" :route="route('paie.index')" :params="['tab' => $tab, 'mois' => $mois]"/>
    </x-page-header>
    @include('paie._barre')

    @if($tab === 'calc')
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold capitalize text-slate-800 dark:text-slate-100">Paie de {{ mois_fr($mois) }}</h2>
            <div class="flex gap-2">
                @if($paie)<span class="{{ ['brouillon' => 'badge-gray', 'transmise' => 'badge-amber', 'val' => 'badge-green'][$paie->statut] }} self-center">{{ ['brouillon' => 'Brouillon', 'transmise' => 'Transmise à la comptabilité', 'val' => 'Comptabilisée'][$paie->statut] }}</span>@endif
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
        <form method="POST" action="{{ route('paie.calcul') }}" id="form-primes" class="card overflow-x-auto scroll-mt-4">@csrf <input type="hidden" name="mois" value="{{ $mois }}">
            <table class="tbl">
                <thead><tr><th>Salarié</th><th class="text-right">Base</th><th class="text-right" title="Jours ouvrables du mois : {{ $P['jours_mois'] }}">Jours</th><th class="text-right" title="Majoration {{ $P['maj_hs'] }} %">H. sup.</th><th class="text-right">Primes imposables</th><th class="text-right">Brut</th><th class="text-right">CNSS ({{ $P['cnssS'] }} %)</th><th class="text-right">IRPP</th><th class="text-right">CSS</th><th class="text-right">Net à payer</th><th class="text-right">Charges patronales</th></tr></thead>
                <tbody>
                @foreach($R as $x)
                    <tr><td><div class="font-medium">{{ $x['nom'] }}</div><div class="text-xs text-slate-500">{{ $x['poste'] }}</div></td>
                        <td class="num">{{ tnd($x['base'], false) }}@if(($x['salaire'] ?? $x['base']) != $x['base'])<div class="text-xs text-slate-500">payé {{ tnd($x['salaire'], false) }}</div>@endif</td>
                        <td class="text-right">@if($lock)<span class="font-mono">{{ $x['jours'] ?? '—' }}</span>@else<input type="number" step="0.5" min="0" max="{{ $P['jours_mois'] }}" name="pointage[{{ $x['salarie_id'] }}][jours]" value="{{ ($paie?->pointage[$x['salarie_id']]['jours'] ?? null) }}" placeholder="{{ $P['jours_mois'] }}" class="input-sm ml-auto w-16 text-right">@endif</td>
                        <td class="text-right">@if($lock)<span class="font-mono">{{ ($x['hs'] ?? 0) ?: '—' }}</span>@else<input type="number" step="0.25" min="0" name="pointage[{{ $x['salarie_id'] }}][hs]" value="{{ ($x['hs'] ?? 0) ?: '' }}" class="input-sm ml-auto w-16 text-right">@endif
                            @if(($x['hs_montant'] ?? 0) > 0)<div class="text-xs text-slate-500">{{ tnd($x['hs_montant'], false) }}</div>@endif</td>
                        <td class="text-right" @if($loop->first) id="primes" @endif>@if($lock)<span class="font-mono">{{ n3($x['prime']) ?: '—' }}</span>@else<input type="number" step="0.001" name="primes[{{ $x['salarie_id'] }}]" value="{{ $x['prime'] ?: '' }}" class="input-sm ml-auto w-28 text-right">@endif</td>
                        <td class="num font-medium">{{ tnd($x['brut'], false) }}</td><td class="num">{{ tnd($x['cnssS'], false) }}</td><td class="num">{{ tnd($x['ir'], false) }}</td><td class="num">{{ tnd($x['css'], false) }}</td>
                        <td class="num font-semibold text-emerald-700 dark:text-emerald-400">{{ tnd($x['net'], false) }}</td><td class="num text-slate-500">{{ tnd($x['cnssP'] + $x['tfp'] + $x['fop'], false) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="border-t-2 border-slate-200"><tr><td>Total</td><td></td><td></td><td></td><td></td><td class="num">{{ tnd($T['brut'], false) }}</td><td class="num">{{ tnd($T['cnssS'], false) }}</td><td class="num">{{ tnd($T['ir'], false) }}</td><td class="num">{{ tnd($T['css'], false) }}</td><td class="num">{{ tnd($T['net'], false) }}</td><td class="num">{{ tnd($T['cnssP'] + $T['tfp'] + $T['fop'], false) }}</td></tr></tfoot>
            </table>
            @unless($lock)<div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-secondary">Recalculer avec le pointage et les primes</button></div>@endunless
        </form>
        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <div class="card card-b text-sm"><h3 class="card-t mb-3">Charges patronales du mois</h3>
                <div class="space-y-1.5"><div class="flex justify-between"><span>CNSS patronale ({{ $P['cnssP'] }} %)</span><span class="font-mono">{{ tnd($T['cnssP']) }}</span></div><div class="flex justify-between"><span>TFP ({{ $P['tfp'] }} %)</span><span class="font-mono">{{ tnd($T['tfp']) }}</span></div><div class="flex justify-between"><span>FOPROLOS ({{ $P['fop'] }} %)</span><span class="font-mono">{{ tnd($T['fop']) }}</span></div></div></div>
            <div class="card card-b text-sm"><h3 class="card-t mb-3">Écriture de paie générée à la validation</h3>
                <table class="w-full">@foreach(\App\Services\PaieCalc::lignes($T) as $l)<tr><td class="py-0.5 font-mono text-xs">{{ $l['compte'] }}</td><td class="text-slate-500">{{ \App\Services\Compta::libelle($l['compte']) }}</td><td class="num">{{ n3($l['debit']) }}</td><td class="num">{{ n3($l['credit']) }}</td></tr>@endforeach</table></div>
        </div>
        @else <div class="card"><x-empty icon="users" title="Aucun salarié">Ajoutez les salariés du dossier dans l’onglet Salariés.</x-empty></div> @endif

    @elseif($tab === 'sal')
        @php
            $statut = in_array(request('statut'), ['actifs', 'inactifs'], true) ? request('statut') : 'tous';
            $liste = $statut === 'actifs' ? $salaries->filter->estActif() : ($statut === 'inactifs' ? $salaries->reject->estActif() : $salaries);
        @endphp
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3" x-data>
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Employés</h2>
                <div class="flex rounded-lg border border-slate-200 p-0.5 text-sm dark:border-slate-700">
                    @foreach(['tous' => 'Tous', 'actifs' => 'Actifs', 'inactifs' => 'Inactifs'] as $c => $l)
                        <a href="{{ route('paie.index', ['tab' => 'sal', 'mois' => $mois, 'statut' => $c]) }}" class="rounded-md px-3 py-1 {{ $statut === $c ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">{{ $l }}
                            ({{ $c === 'tous' ? $salaries->count() : ($c === 'actifs' ? $salaries->filter->estActif()->count() : $salaries->reject->estActif()->count()) }})</a>
                    @endforeach
                </div>
            </div>
            <div class="flex items-center gap-2">
                <input type="search" placeholder="Rechercher (nom, matricule, CIN)…" class="input-sm w-64" @input="document.querySelectorAll('[data-recherche]').forEach(l => l.hidden = !l.dataset.recherche.includes($event.target.value.toLowerCase()))">
                <a href="{{ route('paie.employes.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Nouvel employé</a>
            </div>
        </div>
        <div class="card overflow-x-auto"><table class="tbl"><thead><tr><th>Mat.</th><th>Nom et prénom</th><th>CIN</th><th>Département</th><th>Contrat</th><th>Paiement</th><th class="text-right">Salaire de base</th><th>État</th></tr></thead><tbody>
            @forelse($liste as $e)
                <tr class="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50" onclick="location.href='{{ route('paie.employes.edit', $e) }}'" data-recherche="{{ mb_strtolower($e->nomComplet().' '.$e->matricule.' '.$e->cin) }}">
                    <td class="font-mono text-xs">{{ $e->matricule ?: '—' }}</td>
                    <td><a href="{{ route('paie.employes.edit', $e) }}" class="font-medium hover:text-brand-700">{{ $e->nomComplet() }}</a><div class="text-xs text-slate-500">{{ $e->poste }}</div></td>
                    <td>{{ $e->cin ?: '—' }}</td>
                    <td class="text-xs">{{ $e->departement ?: '—' }}</td>
                    <td class="text-xs">{{ \App\Models\Salarie::CONTRATS[$e->type_contrat] ?? '—' }}@if($e->contrat_fin)<div class="{{ $e->contrat_fin->isPast() ? 'text-rose-600' : 'text-slate-500' }}">jusqu’au {{ $e->contrat_fin->format('d/m/Y') }}</div>@endif</td>
                    <td class="text-xs">{{ \App\Models\Salarie::MODES[$e->mode_paiement] ?? '—' }}@if($e->mode_paiement === 'virement')<div class="{{ $e->rib ? 'font-mono text-slate-500' : 'text-amber-600' }}">{{ $e->rib ?: 'RIB manquant' }}</div>@endif</td>
                    <td class="num">{{ tnd($e->salaire_base, false) }}@if($e->regime === 'horaire')<div class="text-xs text-slate-500">/ heure</div>@endif</td>
                    <td><span class="{{ ['actif' => 'badge-green', 'suspendu' => 'badge-amber', 'sorti' => 'badge-gray'][$e->etat_presence] ?? 'badge-gray' }}">{{ \App\Models\Salarie::ETATS[$e->etat_presence] ?? $e->etat_presence }}</span>
                        @if($e->date_sortie)<div class="text-xs text-slate-500">le {{ $e->date_sortie->format('d/m/Y') }}</div>@endif</td>
                </tr>
            @empty <tr><td colspan="8"><x-empty icon="users" title="Aucun employé"/></td></tr> @endforelse
        </tbody></table></div>

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
            <div class="card-h"><h2 class="card-t">Taux et barème</h2><span class="text-xs text-slate-400">Taux du cabinet, valeurs de départ à vérifier selon la législation en vigueur. CNSS salariale, accident du travail, TFP et FOPROLOS se règlent aussi par employeur (Fichier → Renseignements employeur).</span></div>
            <div class="card-b grid gap-4 md:grid-cols-4">
                @foreach(['cnssS' => 'CNSS salariale (%)', 'cnssP' => 'CNSS patronale (%)', 'at' => 'dont accident du travail (%)', 'tfp' => 'TFP (%)', 'fop' => 'FOPROLOS (%)', 'css' => 'Contribution sociale de solidarité (%)', 'frais' => 'Frais professionnels (%)', 'fraisMax' => 'Plafond frais professionnels (TND / an)', 'chef' => 'Déduction chef de famille (TND / an)', 'enf' => 'Déduction par enfant (TND / an)', 'enfMax' => 'Nombre max. d’enfants', 'enfEtu' => 'Déduction enfant étudiant (TND / an)', 'enfInf' => 'Déduction enfant infirme (TND / an)', 'parentTaux' => 'Parents à charge (% du revenu net)', 'parentMax' => 'Plafond par parent à charge (TND / an)'] as $k => $l)
                    <x-field :label="$l" :name="$k" type="number" step="any" :value="$G[$k] ?? null"/>
                @endforeach
                <div class="md:col-span-4"><label class="label">Barème annuel IRPP (une tranche par ligne : plafond taux)</label><textarea name="bar" rows="8" class="input font-mono">{{ $G['bar'] }}</textarea></div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 p-4 dark:border-slate-800">
                @if(auth()->user()->estChef())<button name="reset" value="1" class="btn-secondary">Rétablir les valeurs de départ</button><button class="btn-primary">Enregistrer</button>@else<span class="text-sm text-slate-500">Modification réservée au chef comptable.</span>@endif
            </div>
        </form>
    @endif
</x-layouts.app>
