{{-- État de rapprochement bancaire (écran et impression) : deux colonnes, comptabilité et banque, qui doivent se rejoindre. --}}
@php
    $m = fn ($v) => tnd(abs($v), false).(abs($v) < 0.0005 ? '' : ($v > 0 ? ' D' : ' C'));
    $ok = $R['ecart'] !== null && abs($R['ecart']) < 0.0005;
@endphp
<div class="grid gap-4 lg:grid-cols-2">
    <table class="tbl rounded-xl border border-slate-200 dark:border-slate-700">
        <thead><tr><th colspan="2">Comptabilité — compte {{ $R['compte'] }}</th></tr></thead>
        <tbody>
            <tr><td>Solde comptable au {{ dfr($R['date']) }}</td><td class="num font-semibold">{{ $m($R['soldeCompta']) }}</td></tr>
            <tr><td>+ Encaissements du relevé non comptabilisés</td><td class="num">{{ tnd($R['relEnc'], false) }}</td></tr>
            <tr><td>− Décaissements du relevé non comptabilisés</td><td class="num">{{ tnd($R['relDec'], false) }}</td></tr>
        </tbody>
        <tfoot class="border-t-2 border-slate-300"><tr><td>Solde comptable rapproché</td><td class="num">{{ $m($R['comptaAjuste']) }}</td></tr></tfoot>
    </table>
    <table class="tbl rounded-xl border border-slate-200 dark:border-slate-700">
        <thead><tr><th colspan="2">Banque — relevé</th></tr></thead>
        <tbody>
            <tr><td>Solde du relevé au {{ dfr($R['date']) }}</td><td class="num font-semibold">{{ $R['soldeReleve'] === null ? '—' : $m($R['soldeReleve']) }}</td></tr>
            <tr><td>+ Débits comptables non pointés (remises non encore créditées)</td><td class="num">{{ tnd($R['nonPD'], false) }}</td></tr>
            <tr><td>− Crédits comptables non pointés (chèques et virements non encore débités)</td><td class="num">{{ tnd($R['nonPC'], false) }}</td></tr>
        </tbody>
        <tfoot class="border-t-2 border-slate-300"><tr><td>Solde bancaire rapproché</td><td class="num">{{ $R['banqueAjuste'] === null ? '—' : $m($R['banqueAjuste']) }}</td></tr></tfoot>
    </table>
</div>
<div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
    @if($R['ecart'] === null)<span class="badge-amber">Saisissez le solde du relevé pour contrôler le rapprochement</span>
    @elseif($ok)<span class="badge-green">Rapprochement équilibré</span>
    @else<span class="badge-red">Écart de rapprochement : {{ tnd(abs($R['ecart']), false) }}</span><span class="text-xs text-slate-500">Pointez les lignes vues sur le relevé, ou comptabilisez les opérations du relevé en attente.</span>@endif
    <span class="text-xs text-slate-500">D : solde débiteur (avoir en banque) · C : solde créditeur (découvert).</span>
</div>

{{-- À l'écran, les écritures non pointées sont listées plus bas, avec le pointage --}}
@if($R['nonPointees']->count() && ! ($ecran ?? false))
    <h3 class="mb-1 mt-5 text-sm font-semibold">Écritures comptables non pointées</h3>
    <table class="tbl"><thead><tr><th>Date</th><th>Jnl</th><th>Libellé</th><th class="text-right">Débit</th><th class="text-right">Crédit</th></tr></thead><tbody>
        @foreach($R['nonPointees'] as $l)<tr><td>{{ dfr($l['date']) }}</td><td>{{ $l['journal'] }}</td><td>{{ $l['libelle'] }}</td><td class="num">{{ n3($l['debit']) }}</td><td class="num">{{ n3($l['credit']) }}</td></tr>@endforeach
    </tbody></table>
@endif
@if($R['releveAtt']->count())
    <h3 class="mb-1 mt-5 text-sm font-semibold">Opérations du relevé non comptabilisées</h3>
    <table class="tbl"><thead><tr><th>Date</th><th>Libellé</th><th class="text-right">Débit banque</th><th class="text-right">Crédit banque</th></tr></thead><tbody>
        @foreach($R['releveAtt'] as $l)<tr><td>{{ dfr($l->date) }}</td><td>{{ $l->libelle }}</td><td class="num">{{ n3($l->debit) }}</td><td class="num">{{ n3($l->credit) }}</td></tr>@endforeach
    </tbody></table>
    @if($ecran ?? false)<p class="mt-1 text-xs text-slate-500">À comptabiliser dans <a href="{{ route('compta.index', ['tab' => 'bq', 'statut' => 'att']) }}" class="underline">Relevés bancaires</a>.</p>@endif
@endif
