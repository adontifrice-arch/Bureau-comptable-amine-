{{-- Congé → Saisie congé antérieur : report des congés des années précédentes (jours restant dus au 1er janvier d'une année), pour tous les salariés. --}}
@php
    $anneeRep = (int) (request('annee') ?: substr($mois, 0, 4));
    $nb = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $jj = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Saisie des congés antérieurs</h2>
</div>
<form method="POST" action="{{ route('paie.conges.anterieurs') }}" class="card overflow-x-auto">@csrf
    <div class="card-b flex flex-wrap items-center gap-3 text-sm">
        <label class="font-semibold">Jours restant dus au 1er janvier</label>
        <input type="number" name="annee" value="{{ $anneeRep }}" min="1990" max="2100" class="input-sm w-24" required>
        <span class="text-xs text-slate-400">Saisissez le reliquat de congés de chaque salarié à cette date (années précédentes cumulées). Les années suivantes sont calculées par la paie.</span>
    </div>
    <table class="tbl text-sm">
        <thead><tr><th>Mat.</th><th>Salarié</th><th>Embauche</th><th class="text-right">Acquis / mois</th><th class="text-right">Report saisi</th><th class="w-36 text-right">Jours au 1er janvier</th><th class="text-right">Solde à fin {{ mois_fr($mois) }}</th></tr></thead>
        <tbody>
        @forelse($salaries as $e)
            @php $c = \App\Services\PaieCalc::soldeConges($e, $mois); @endphp
            <tr><td class="font-mono text-xs">{{ $e->matricule }}</td><td class="font-medium">{{ $e->nomComplet() }}</td><td>{{ $e->date_embauche?->format('d/m/Y') ?? '—' }}</td>
                <td class="num">{{ $jj($e->conges_acquis) }}</td>
                <td class="num text-xs text-slate-500">{{ $e->conges_report !== null ? $jj($e->conges_report).' j au 01/01/'.$e->conges_report_annee : '—' }}</td>
                <td><input type="number" step="0.5" name="report[{{ $e->id }}]" value="{{ (int) $e->conges_report_annee === $anneeRep ? $nb($e->conges_report) : '' }}" class="input-sm ml-auto w-28 text-right"></td>
                <td class="num font-semibold">{{ $jj($c['reste']) }} j</td></tr>
        @empty <tr><td colspan="7"><x-empty icon="users" title="Aucun salarié"/></td></tr> @endforelse
        </tbody>
    </table>
    <div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button></div>
</form>
