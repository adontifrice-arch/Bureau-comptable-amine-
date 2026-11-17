{{-- Prêt → Prêt Société / Prêt CNSS → Saisie des prêts : prêts du type choisi, échéances retenues sur le net de la paie. --}}
@php
    $typePret = array_key_exists(request('type'), \App\Models\Pret::TYPES) ? request('type') : 'societe';
    $libType = \App\Models\Pret::TYPES[$typePret];
    $prets = \App\Models\Pret::with('salarie')->where('client_id', $client->id)->where('type', $typePret)->orderByDesc('date')->get();
    $enCours = $prets->filter(fn ($p) => $p->situation($mois)['reste'] > 0.0005);
    $soldes = $prets->reject(fn ($p) => $p->situation($mois)['reste'] > 0.0005);
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">{{ $libType }} — saisie des prêts</h2>
    <div class="flex rounded-lg border border-slate-200 p-0.5 text-sm dark:border-slate-700">
        @foreach(\App\Models\Pret::TYPES as $k => $l)
            <a href="{{ route('paie.index', ['tab' => 'prets', 'type' => $k, 'mois' => $mois]) }}" class="rounded-md px-3 py-1 {{ $typePret === $k ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">{{ $l }}</a>
        @endforeach
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-[24rem_1fr]">
    <form method="POST" action="{{ route('paie.prets.store') }}" class="card card-b space-y-3 text-sm" x-data="{ m: @js(old('montant')), n: @js(old('nb_echeances')), e: @js(old('echeance')) }">@csrf
        <input type="hidden" name="type" value="{{ $typePret }}"><input type="hidden" name="retour" value="{{ $mois }}">
        <div class="font-semibold">Nouveau {{ mb_strtolower($libType) }}</div>
        <div><label class="label">Salarié</label>
            <select name="salarie_id" class="input" required><option value="">— Choisir —</option>@foreach($salaries->filter(fn ($e) => \App\Services\PaieCalc::payeLeMois($e, $mois)) as $e)<option value="{{ $e->id }}" @selected(old('salarie_id') == $e->id)>{{ $e->matricule ? $e->matricule.' — ' : '' }}{{ $e->nomComplet() }}</option>@endforeach</select></div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Date du prêt</label><input type="date" name="date" value="{{ old('date', now()->format('Y-m-d')) }}" class="input" required></div>
            <div><label class="label">Montant</label><input type="number" step="0.001" min="0.001" name="montant" x-model="m" class="input text-right" required></div>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Nombre d’échéances</label><input type="number" min="1" max="240" name="nb_echeances" x-model="n" @input="e = ''" class="input text-right"></div>
            <div><label class="label">ou montant de l’échéance</label><input type="number" step="0.001" min="0.001" name="echeance" x-model="e" @input="n = ''" class="input text-right"></div>
        </div>
        <p class="text-xs text-slate-500" x-show="m && (n || e)" x-text="n ? n + ' échéance(s) de ' + (m / n).toFixed(3) : Math.ceil(m / e) + ' échéance(s), la dernière ajustée'"></p>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Première retenue</label><input type="month" name="premier_mois" value="{{ old('premier_mois', $mois) }}" class="input" required></div>
            <div><label class="label">Référence</label><input name="reference" value="{{ old('reference') }}" placeholder="{{ $typePret === 'cnss' ? 'n° de prêt CNSS' : '' }}" class="input"></div>
        </div>
        @if($typePret === 'societe')
            <div><label class="label">Versé par</label><select name="mode" class="input">@foreach(\App\Models\Salarie::MODES as $k => $l)<option value="{{ $k }}" @selected(old('mode', 'virement') === $k)>{{ $l }}</option>@endforeach</select></div>
        @endif
        <div><label class="label">Observation</label><input name="observation" value="{{ old('observation') }}" class="input"></div>
        <p class="text-xs text-slate-400">Chaque mois, l’échéance est retenue sur le net de la paie ; la dernière solde exactement le prêt. Au départ du salarié, le reste dû est retenu sur son solde de tout compte.
            {{ $typePret === 'cnss' ? 'Les retenues sont à reverser à la CNSS (compte 427 de l’écriture de paie).' : 'Le versement est comptabilisé à l’enregistrement (débit 421 Personnel – avances et acomptes, crédit 532 Banques ou 54 Caisse) ; les retenues soldent le 421 dans l’écriture de paie.' }}</p>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Enregistrer le prêt</button></div>
    </form>

    <div class="space-y-4">
        @foreach(['En cours à fin '.mois_fr($mois) => $enCours, 'Soldés' => $soldes] as $titre => $liste)
            <div class="card overflow-x-auto">
                <div class="card-h"><h3 class="card-t">{{ $titre }} ({{ $liste->count() }})</h3>
                    @if($loop->first)<span class="flex gap-3 text-xs">
                        <a href="{{ route('paie.edition', ['prets-etat', 'imprimer', 'mois' => $mois, 'type' => $typePret]) }}" target="_blank" class="text-brand-600 hover:underline">Etat des prêts</a>
                        <a href="{{ route('paie.edition', ['prets-retenues', 'imprimer', 'mois' => $mois, 'type' => $typePret]) }}" target="_blank" class="text-brand-600 hover:underline">Retenues du mois</a></span>@endif</div>
                <table class="tbl text-sm">
                    <thead><tr><th>Salarié</th><th>Date</th><th class="text-right">Montant</th><th class="text-right">Échéance</th><th>Période</th><th class="text-right">Ce mois</th><th class="text-right">Reste dû</th><th></th></tr></thead>
                    <tbody>
                    @forelse($liste as $p)
                        @php $s = $p->situation($mois); @endphp
                        <tr><td class="font-medium">{{ $p->salarie?->nomComplet() }}@if($p->reference)<div class="text-xs text-slate-400">{{ $p->reference }}</div>@endif</td>
                            <td>{{ $p->date->format('d/m/Y') }}</td><td class="num">{{ n3($p->montant) }}</td><td class="num">{{ n3($p->echeance) }} × {{ $p->nb_echeances }}</td>
                            <td class="text-xs">{{ mois_fr($p->premier_mois) }} → {{ $p->dernierMois() ? mois_fr($p->dernierMois()) : '—' }}</td>
                            <td class="num">{{ ($m = $p->echeanceDuMois($mois)) ? n3($m) : '—' }}</td><td class="num font-semibold">{{ n3($s['reste']) }}@if(! empty($s['stc']))<div class="text-xs font-normal text-slate-500">soldé par le STC de {{ mois_fr($s['stc']) }}</div>@endif</td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('paie.prets.echeancier', $p) }}" target="_blank" class="btn-icon" title="Échéancier"><x-icon name="list" class="h-4 w-4"/></a>
                                <form method="POST" action="{{ route('paie.prets.destroy', $p) }}" class="inline" onsubmit="return confirm('Supprimer ce prêt ?')">@csrf @method('DELETE')<button class="btn-icon text-rose-600" title="Supprimer"><x-icon name="trash" class="h-4 w-4"/></button></form>
                            </td></tr>
                    @empty <tr><td colspan="8" class="py-6 text-center text-sm text-slate-400">Aucun prêt.</td></tr> @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</div>
