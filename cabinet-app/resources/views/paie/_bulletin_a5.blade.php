{{--
    Bulletin compact au format A5. $variante : '' (simple), 'jours' (salaire exprimé en jours et taux journalier),
    'conge' (congés acquis, pris et reste de l'année), 'cin' (salarié identifié par son CIN).
--}}
@php
    $nb = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $horaire = ($x['regime'] ?? 'mensuel') === 'horaire';
    $hj = (float) ($P['heures_jour'] ?? 8) ?: 8;
@endphp
<div class="flex h-full flex-col rounded-xl border border-slate-300 bg-white p-4 text-[11px] text-slate-900">
    <div class="flex items-start justify-between border-b-2 border-brand-600 pb-2">
        <div class="flex items-start gap-2">@if($logoEmp)<img src="{{ $logoEmp }}" alt="" class="max-h-10 max-w-20">@endif<div><div class="font-bold">{{ $client->nom }}</div><div class="text-[10px] text-slate-600">{{ $client->adresse }} {{ $villeEmp }}</div><div class="text-[10px] text-slate-600">MF {{ $client->matricule_fiscal }} · CNSS {{ $client->matricule_cnss }}</div></div></div>
        <div class="text-right"><div class="text-sm font-bold">BULLETIN DE PAIE</div><div class="capitalize">{{ ($x['_periode'] ?? mois_fr($x['_mois'] ?? $mois)) }}</div></div>
    </div>
    @if($variante === 'cin')
        <div class="my-2 flex items-center justify-between rounded bg-slate-50 p-2"><span><b>{{ $x['nom'] }}</b> — {{ $x['poste'] }}</span><span class="rounded border border-slate-400 px-2 py-0.5 font-mono font-bold">CIN {{ $x['cin'] ?: '—' }}</span></div>
        <div class="mb-1 text-[10px] text-slate-500">N° CNSS {{ $x['cnss'] ?? '—' }}</div>
    @else
        <div class="my-2 rounded bg-slate-50 p-2"><b>{{ $x['nom'] }}</b> — {{ $x['poste'] }}<div class="text-[10px] text-slate-600">Matricule {{ $x['matricule'] ?? '—' }} · CIN {{ $x['cin'] ?? '—' }} · CNSS {{ $x['cnss'] ?? '—' }}</div></div>
    @endif
    @if($variante === 'jours')
        @php $jPayes = $horaire ? (float) ($x['jours'] ?? 0) / $hj : (float) ($x['jours'] ?? 0); $jMois = $horaire ? (float) ($x['jours_mois'] ?? 0) / $hj : (float) ($x['jours_mois'] ?? 0); @endphp
        <div class="mb-2 grid grid-cols-3 gap-1 text-center text-[10px]">
            <div class="rounded border border-slate-200 p-1"><div class="text-slate-500">Jours ouvrables</div><b>{{ $nb($jMois) }}</b></div>
            <div class="rounded border border-slate-200 p-1"><div class="text-slate-500">Jours payés</div><b>{{ $nb($jPayes) }}</b></div>
            <div class="rounded border border-slate-200 p-1"><div class="text-slate-500">Taux journalier</div><b class="font-mono">{{ n3($jMois > 0 ? $x['base'] / $jMois : 0) }}</b></div>
        </div>
    @endif
    <table class="w-full">
        @foreach(\App\Services\PaieCalc::lignesBulletin($x, $P) as $row)
            <tr class="border-b border-slate-200 {{ ($row[2] ?? 0) === 1 ? 'bg-slate-50 font-bold' : '' }} {{ ($row[2] ?? 0) === 2 ? 'text-[10px] italic text-slate-500' : '' }}"><td class="px-2 py-0.5">{{ $row[0] }}</td><td class="px-2 py-0.5 text-right font-mono">{{ tnd($row[1], false) }}</td></tr>
        @endforeach
    </table>
    @if($variante === 'conge' && isset($x['_conge']))
        <div class="mt-2 grid {{ ($x['_conge']['report'] ?? 0) ? 'grid-cols-4' : 'grid-cols-3' }} gap-1 text-center text-[10px]">
            @if($x['_conge']['report'] ?? 0)<div class="rounded border border-slate-200 p-1"><div class="text-slate-500">Report</div><b>{{ $nb($x['_conge']['report']) }} j</b></div>@endif
            <div class="rounded border border-slate-200 p-1"><div class="text-slate-500">Congés acquis</div><b>{{ $nb($x['_conge']['acquis']) }} j</b></div>
            <div class="rounded border border-slate-200 p-1"><div class="text-slate-500">Congés pris</div><b>{{ $nb($x['_conge']['pris']) }} j</b></div>
            <div class="rounded border border-slate-300 bg-slate-50 p-1"><div class="text-slate-500">Reste congé</div><b>{{ $nb($x['_conge']['reste']) }} j</b></div>
        </div>
    @endif
    <div class="mt-auto pt-2 text-[10px] text-slate-500">Charges patronales : CNSS {{ tnd($x['cnssP']) }} · TFP {{ tnd($x['tfp']) }} · FOPROLOS {{ tnd($x['fop']) }}</div>
</div>
