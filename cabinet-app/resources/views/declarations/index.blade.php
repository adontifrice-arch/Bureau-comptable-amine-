@php $lock = $d->verrouillee(); $st = ['brouillon' => ['Brouillon', 'gray'], 'validee' => ['Validée', 'amber'], 'deposee' => ['Déposée', 'green']][$d->statut];
     $lab = ['men' => 'mensuelle', 'ann' => 'annuelle', 'emp' => 'd’employeur'][$type]; $q = ['type' => $type, 'periode' => $periode]; @endphp
<x-layouts.app title="Déclarations">
    <x-page-header title="Déclarations fiscales" :subtitle="$client->nom">
        <x-client-picker :clients="$clients" :current="$client" :route="route('declarations.index')" :params="['type' => $type]"/>
    </x-page-header>
    <x-tabs :active="$type" :items="['men' => ['Déclaration mensuelle', route('declarations.index', ['type' => 'men'])], 'ann' => ['Déclaration annuelle', route('declarations.index', ['type' => 'ann'])], 'emp' => ['Déclaration d’employeur', route('declarations.index', ['type' => 'emp'])]]"/>

    <div class="card mb-6 flex flex-wrap items-center gap-4 p-4">
        <form class="flex items-end gap-2"><input type="hidden" name="type" value="{{ $type }}">
            <div><label class="label">{{ $type === 'men' ? 'Mois' : 'Année' }}</label>
                @if($type === 'men')<input type="month" name="periode" value="{{ $periode }}" class="input" onchange="this.form.submit()">@else<input type="number" name="periode" value="{{ $periode }}" class="input w-28" onchange="this.form.submit()">@endif</div>
        </form>
        <div class="flex-1"><div class="font-semibold">Déclaration {{ $lab }} · {{ $type === 'men' ? mois_fr($periode) : $periode }}</div>
            <div class="text-xs text-slate-500">{{ $d->validee_le ? 'Validée le '.dfr($d->validee_le) : '' }} {{ $d->deposee_le ? '· déposée le '.dfr($d->deposee_le) : '' }}</div></div>
        <span class="badge-{{ $st[1] }}">{{ $st[0] }}</span>
        <div class="flex flex-wrap gap-2">
            @unless($lock)<form method="POST" action="{{ route('declarations.prefill') }}">@include('declarations._hidden')<button class="btn-secondary"><x-icon name="sparkles" class="h-4 w-4"/> Pré-remplir</button></form>@endunless
            <form method="POST" action="{{ route('declarations.statut') }}">@include('declarations._hidden')
                @if($d->statut === 'brouillon')<button name="action" value="valider" class="btn-secondary">Valider</button>
                @elseif($d->statut === 'validee')<button name="action" value="deposer" class="btn-success">Marquer déposée</button><button name="action" value="rouvrir" class="btn-ghost">Rouvrir</button>
                @else<button name="action" value="rouvrir" class="btn-ghost">Rouvrir</button>@endif
            </form>
            <a href="{{ route('declarations.csv', $q) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel</a>
            <a href="{{ route('declarations.print', $q) }}" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> PDF</a>
        </div>
    </div>

    @if($type !== 'emp')
        <div class="grid gap-6 lg:grid-cols-3">
            <form method="POST" action="{{ route('declarations.save') }}" class="card overflow-hidden lg:col-span-2">@include('declarations._hidden')
                @include('declarations._champs')
                @unless($lock)<div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-primary">Enregistrer</button></div>@endunless
            </form>
            <div class="space-y-4 self-start">
                @if($type === 'men')
                    <x-stat label="TVA {{ ($calc['tvap'] ?? 0) >= 0 ? 'à payer' : 'crédit à reporter' }}" :value="tnd(abs($calc['tvap'] ?? 0))" icon="scale" :tone="($calc['tvap'] ?? 0) >= 0 ? 'amber' : 'green'"/>
                    <x-stat label="Total à payer" :value="tnd($calc['tot'] ?? 0)" icon="wallet"/>
                @else
                    <x-stat label="Résultat fiscal" :value="tnd($calc['rf'] ?? 0)" icon="trend"/>
                    <x-stat label="Impôt dû" :value="tnd($calc['isd'] ?? 0)" icon="scale" tone="amber"/>
                    <x-stat label="{{ ($calc['sol'] ?? 0) >= 0 ? 'Solde à payer' : 'Crédit d’impôt' }}" :value="tnd(abs($calc['sol'] ?? 0))" icon="wallet" :tone="($calc['sol'] ?? 0) >= 0 ? 'red' : 'green'"/>
                @endif
                <div class="card card-b text-xs text-slate-500">« Pré-remplir » reprend les écritures comptables validées, la paie clôturée et les factures reçues{{ $type === 'ann' ? ', ainsi que le tableau de passage de la liasse fiscale' : '' }}. Vérifiez chaque montant avant le dépôt sur la plateforme de télédéclaration.</div>
            </div>
        </div>
    @else
        @php $axs = ['rec' => 'Récapitulatif'] + collect(\App\Http\Controllers\DeclarationController::ANNEXES)->mapWithKeys(fn($t, $k) => [$k => 'Annexe '.substr($k, 1).' ('.count($d->annexes[$k] ?? []).')'])->all(); @endphp
        <div class="mb-4 flex flex-wrap gap-1">@foreach($axs as $k => $l)<a href="{{ route('declarations.index', $q + ['ax' => $k]) }}" class="{{ $ax === $k ? 'badge-blue' : 'badge-gray' }} !py-1.5 !px-3">{{ $l }}</a>@endforeach</div>
        @if($ax === 'rec')
            <div class="card overflow-x-auto"><table class="tbl"><thead><tr><th>Annexe</th><th class="text-right">Lignes</th><th class="text-right">Montants servis</th><th class="text-right">Retenues</th><th class="text-right">Net servi</th></tr></thead><tbody>
                @foreach($rec as $k => $x)<tr><td><a href="{{ route('declarations.index', $q + ['ax' => $k]) }}" class="hover:text-brand-700">{{ $x['t'] }}</a></td><td class="num">{{ $x['n'] }}</td><td class="num">{{ tnd($x['brut'], false) }}</td><td class="num">{{ tnd($x['ret'], false) }}</td><td class="num">{{ tnd($x['net'], false) }}</td></tr>@endforeach
            </tbody><tfoot><tr><td>Total</td><td class="num">{{ collect($rec)->sum('n') }}</td><td class="num">{{ tnd(collect($rec)->sum('brut'), false) }}</td><td class="num">{{ tnd(collect($rec)->sum('ret'), false) }}</td><td class="num">{{ tnd(collect($rec)->sum('net'), false) }}</td></tr></tfoot></table></div>
        @else
            <div class="card">
                <div class="card-h"><h2 class="card-t">{{ \App\Http\Controllers\DeclarationController::ANNEXES[$ax] }}</h2>@unless($lock)<button class="btn-primary btn-sm" x-data @click="$dispatch('open-modal', 'anx')"><x-icon name="plus" class="h-3.5 w-3.5"/> Ligne</button>@endunless</div>
                <div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Identifiant</th><th>Bénéficiaire</th><th>Activité / emploi</th><th class="text-right">Montant brut</th><th class="text-right">Taux</th><th class="text-right">Retenue</th><th class="text-right">Net</th><th></th></tr></thead><tbody>
                    @forelse($d->annexes[$ax] ?? [] as $i => $l)
                        <tr><td class="text-xs">{{ ['1' => 'MF', '2' => 'CIN', '3' => 'CS', '4' => 'ND'][$l['idk'] ?? ''] ?? '' }} {{ $l['id'] ?? '' }}</td><td class="font-medium">{{ $l['nom'] }} @if(!empty($l['auto']))<span class="badge-blue ml-1">auto</span>@endif</td><td class="text-xs">{{ $l['act'] ?? '' }}</td>
                            <td class="num">{{ tnd($l['brut'], false) }}</td><td class="num">{{ $l['taux'] !== null && $l['taux'] !== '' ? (float) $l['taux'].' %' : '' }}</td><td class="num">{{ tnd($l['ret'], false) }}</td><td class="num">{{ tnd($l['net'], false) }}</td>
                            <td>@unless($lock)<form method="POST" action="{{ route('declarations.annexe.destroy') }}">@include('declarations._hidden') @method('DELETE')<input type="hidden" name="ax" value="{{ $ax }}"><input type="hidden" name="i" value="{{ $i }}"><button class="btn-icon hover:!text-rose-600"><x-icon name="trash" class="h-4 w-4"/></button></form>@endunless</td></tr>
                    @empty <tr><td colspan="8"><x-empty icon="list" title="Aucune ligne">Utilisez « Pré-remplir » ou ajoutez les lignes manuellement.</x-empty></td></tr> @endforelse
                </tbody></table></div>
            </div>
            <x-modal name="anx" title="Nouvelle ligne">
                <form method="POST" action="{{ route('declarations.annexe.store') }}" class="grid gap-4 md:grid-cols-2">@include('declarations._hidden')<input type="hidden" name="ax" value="{{ $ax }}">
                    <x-select label="Type d’identifiant" name="idk" :options="['1' => 'Matricule fiscal', '2' => 'CIN', '3' => 'Carte de séjour', '4' => 'Non domicilié ni établi']"/>
                    <x-field label="Identifiant" name="id"/>
                    <x-field label="Nom / raison sociale *" name="nom" required span="md:col-span-2"/>
                    <x-field label="Adresse" name="adr"/><x-field label="Activité / emploi" name="act"/>
                    <x-field label="Montant brut servi *" name="brut" type="number" step="0.001" required/>
                    <x-field label="Taux de retenue (%)" name="taux" type="number" step="0.01"/>
                    <x-field label="Retenue (si différente du taux)" name="ret" type="number" step="0.001"/>
                    <div class="flex items-end justify-end"><button class="btn-primary">Ajouter</button></div>
                </form>
            </x-modal>
        @endif
    @endif
</x-layouts.app>
