@php $G = \App\Models\Setting::paie(); $u = fn($t) => route('paie.index', ['tab' => $t, 'mois' => $mois]); $lock = $paie && $paie->statut !== 'brouillon'; @endphp
<x-layouts.app title="Paie">
    <x-page-header title="Paie" :subtitle="$client->nom.' · législation tunisienne (taux paramétrables)'">
        <x-client-picker :clients="$clients" :current="$client" :route="route('paie.index')" :params="['tab' => $tab, 'mois' => $mois]"/>
    </x-page-header>
    @include('paie._barre')

    @if($tab === 'calc')
        @include('paie._saisie')

    @elseif($tab === 'variable')
        @include('paie._saisie_variable')

    @elseif($tab === 'biblio')
        @include('paie._bibliographie')

    @elseif($tab === 'prets')
        @include('paie._prets')

    @elseif($tab === 'acomptes')
        @include('paie._acomptes')

    @elseif($tab === 'conges')
        @include('paie._conges')

    @elseif($tab === 'conges-ant')
        @include('paie._conges_anterieurs')

    @elseif($tab === 'stc')
        @include('paie._saisie_stc')

    @elseif($tab === 'rappel')
        @include('paie._saisie_rappel')

    @elseif($tab === 'prime')
        @include('paie._saisie_prime')

    @elseif($tab === 'autre')
        @include('paie._saisie_autre')

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
