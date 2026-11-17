{{-- Congé → Saisie des congés : registre des congés (période, jours ouvrables, type) et solde de chaque salarié. --}}
@php
    $annee = (int) (request('annee') ?: substr($mois, 0, 4));
    $types = \App\Models\Conge::TYPES;
    $registre = \App\Models\Conge::with('salarie')->where('client_id', $client->id)->where('au', '>=', $annee.'-01-01')->where('du', '<=', $annee.'-12-31 23:59:59')->orderByDesc('du')->get();
    $nb = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $finSolde = $annee < (int) substr($mois, 0, 4) ? $annee.'-12' : $mois;
    $actifs = $salaries->filter(fn ($e) => ! $e->date_sortie || $e->date_sortie->format('Y') >= $annee);
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Saisie des congés</h2>
    <form class="flex items-center gap-2"><input type="hidden" name="tab" value="conges"><input type="hidden" name="mois" value="{{ $mois }}">
        <label class="text-sm text-slate-500">Année</label><input type="number" name="annee" value="{{ $annee }}" min="2000" max="2100" class="input-sm w-24" onchange="this.form.submit()"></form>
</div>

<div class="mb-6 grid gap-4 lg:grid-cols-[24rem_1fr]">
    <form method="POST" action="{{ route('paie.conges.store') }}" class="card card-b space-y-3 text-sm">@csrf
        <input type="hidden" name="mois" value="{{ $mois }}">
        <div class="font-semibold">Nouveau congé</div>
        <div><label class="label">Salarié</label>
            <select name="salarie_id" class="input" required><option value="">— Choisir —</option>@foreach($actifs as $e)<option value="{{ $e->id }}" @selected(old('salarie_id') == $e->id)>{{ $e->matricule ? $e->matricule.' — ' : '' }}{{ $e->nomComplet() }}</option>@endforeach</select></div>
        <div><label class="label">Type</label><select name="type" class="input">@foreach($types as $k => $l)<option value="{{ $k }}" @selected(old('type', 'paye') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Du</label><input type="date" name="du" value="{{ old('du') }}" class="input" required></div>
            <div><label class="label">Au</label><input type="date" name="au" value="{{ old('au') }}" class="input" required></div>
        </div>
        <div><label class="label">Nombre de jours <span class="font-normal text-slate-400">(vide : jours ouvrables de la période)</span></label><input type="number" step="0.5" min="0" name="jours" value="{{ old('jours') }}" class="input"></div>
        <div><label class="label">Observation</label><input name="observation" value="{{ old('observation') }}" class="input"></div>
        <p class="text-xs text-slate-400">Le congé payé est décompté du solde et payé dans la paie du mois (colonne Congé de Saisie Paie, si elle n’est pas saisie) ; le congé exceptionnel est payé sans être décompté ; le congé sans solde est retiré des jours payés ; le congé de maladie est noté pour mémoire.</p>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Enregistrer le congé</button></div>
    </form>

    <div class="card overflow-x-auto">
        <div class="card-h"><h3 class="card-t">Solde des congés à fin {{ mois_fr($finSolde) }}</h3>
            <a href="{{ route('paie.edition', ['conges-solde', 'imprimer', 'mois' => $finSolde]) }}" target="_blank" class="text-xs text-brand-600 hover:underline">Imprimer</a></div>
        <table class="tbl text-sm">
            <thead><tr><th>Salarié</th><th class="text-right">Report</th><th class="text-right">Acquis {{ $annee }}</th><th class="text-right">Pris {{ $annee }}</th><th class="text-right">Reste</th></tr></thead>
            <tbody>
            @forelse($actifs as $e)
                @php $c = \App\Services\PaieCalc::soldeConges($e, $finSolde); @endphp
                <tr><td>{{ $e->nomComplet() }}</td><td class="num">{{ $nb($c['report']) }}</td><td class="num">{{ $nb($c['acquis']) }}</td><td class="num">{{ $nb($c['pris']) }}</td>
                    <td class="num font-semibold {{ $c['reste'] < 0 ? 'text-rose-600' : '' }}">{{ $nb($c['reste']) }} j</td></tr>
            @empty <tr><td colspan="5"><x-empty icon="users" title="Aucun salarié"/></td></tr> @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card overflow-x-auto">
    <div class="card-h"><h3 class="card-t">Registre des congés {{ $annee }}</h3>
        <a href="{{ route('paie.edition', ['conges-registre', 'imprimer', 'mois' => $annee.'-01']) }}" target="_blank" class="text-xs text-brand-600 hover:underline">Imprimer</a></div>
    <table class="tbl text-sm">
        <thead><tr><th>Salarié</th><th>Type</th><th>Du</th><th>Au</th><th class="text-right">Jours</th><th>Observation</th><th></th></tr></thead>
        <tbody>
        @forelse($registre as $c)
            <tr><td class="font-medium">{{ $c->salarie?->nomComplet() }}</td>
                <td><span class="{{ ['paye' => 'badge-green', 'exceptionnel' => 'badge-amber', 'sans_solde' => 'badge-gray', 'maladie' => 'badge-gray'][$c->type] ?? 'badge-gray' }}">{{ $types[$c->type] ?? $c->type }}</span></td>
                <td>{{ $c->du->format('d/m/Y') }}</td><td>{{ $c->au->format('d/m/Y') }}</td><td class="num">{{ $nb($c->jours) }}</td><td class="text-xs text-slate-500">{{ $c->observation }}</td>
                <td class="text-right"><form method="POST" action="{{ route('paie.conges.destroy', $c) }}" onsubmit="return confirm('Supprimer ce congé ?')">@csrf @method('DELETE')<button class="btn-icon text-rose-600" title="Supprimer"><x-icon name="trash" class="h-4 w-4"/></button></form></td></tr>
        @empty <tr><td colspan="7"><x-empty icon="calendar" title="Aucun congé saisi en {{ $annee }}"/></td></tr> @endforelse
        </tbody>
    </table>
</div>
