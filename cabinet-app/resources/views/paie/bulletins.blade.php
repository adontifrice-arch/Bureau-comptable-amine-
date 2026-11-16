@php
    $kc = $client->paie_config ?? [];
    $logoEmp = !empty($kc['afficher_logo']) && !empty($kc['logo']) ? asset('storage/'.$kc['logo']) : null;
    $villeEmp = trim(($kc['code_postal'] ?? '').' '.($kc['ville'] ?? ''));
    $lignes = fn ($x) => [['Salaire de base'.(isset($x['jours'], $x['jours_mois']) && $x['jours'] != $x['jours_mois'] ? ' ('.$x['jours'].((($x['regime'] ?? 'mensuel') === 'horaire') ? ' h / ' : ' j / ').$x['jours_mois'].')' : ''), $x['salaire'] ?? $x['base']], ['Heures supplémentaires'.(!empty($x['hs']) ? ' ('.$x['hs'].' h)' : ''), $x['hs_montant'] ?? 0], ['Primes et indemnités imposables', $x['prime']], ['SALAIRE BRUT', $x['brut'], 1], ['Retenue CNSS ('.$P['cnssS'].' %)', -$x['cnssS']], ['Salaire imposable annuel (après abattements)', $x['bi'], 2], ['Retenue à la source IRPP', -$x['ir']], ['Contribution sociale de solidarité', -$x['css']], ['NET À PAYER', $x['net'], 1]];
@endphp
<x-layouts.app :title="'Bulletins '.$mois">
    @if($format === 'a5')
        <style>@media print { @page { size: A5 portrait; margin: 7mm; } }</style>
    @endif
    <div class="no-print"><x-page-header :title="'Bulletins de paie '.strtoupper($format).' — '.mois_fr($mois)" :subtitle="$client->nom" :back="route('paie.index', ['mois' => $mois])">
        <a href="{{ route('paie.bulletins', ['mois' => $mois, 'format' => $format === 'a5' ? 'a4' : 'a5']) }}" class="btn-secondary">Format {{ $format === 'a5' ? 'A4' : 'A5' }}</a>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button>
    </x-page-header></div>
    @forelse($R as $x)
        @if($format === 'a5')
            {{-- Bulletin compact au format A5 --}}
            <div class="print-area mx-auto mb-8 max-w-xl break-after-page rounded-xl border border-slate-200 bg-white p-5 text-[11px] text-slate-900 shadow">
                <div class="flex items-start justify-between border-b-2 border-brand-600 pb-2">
                    <div class="flex items-start gap-2">@if($logoEmp)<img src="{{ $logoEmp }}" alt="" class="max-h-10 max-w-20">@endif<div><div class="font-bold">{{ $client->nom }}</div><div class="text-[10px] text-slate-600">{{ $client->adresse }} {{ $villeEmp }}</div><div class="text-[10px] text-slate-600">MF {{ $client->matricule_fiscal }} · CNSS {{ $client->matricule_cnss }}</div></div></div>
                    <div class="text-right"><div class="text-sm font-bold">BULLETIN DE PAIE</div><div class="capitalize">{{ mois_fr($mois) }}</div></div>
                </div>
                <div class="my-2 rounded bg-slate-50 p-2"><b>{{ $x['nom'] }}</b> — {{ $x['poste'] }} · CIN {{ $x['cin'] ?? '—' }} · CNSS {{ $x['cnss'] ?? '—' }}</div>
                <table class="w-full">
                    @foreach($lignes($x) as $row)
                        <tr class="border-b border-slate-200 {{ ($row[2] ?? 0) === 1 ? 'bg-slate-50 font-bold' : '' }} {{ ($row[2] ?? 0) === 2 ? 'text-[10px] italic text-slate-500' : '' }}"><td class="px-2 py-1">{{ $row[0] }}</td><td class="px-2 py-1 text-right font-mono">{{ tnd($row[1], false) }}</td></tr>
                    @endforeach
                </table>
                <div class="mt-2 text-[10px] text-slate-500">Charges patronales : CNSS {{ tnd($x['cnssP']) }} · TFP {{ tnd($x['tfp']) }} · FOPROLOS {{ tnd($x['fop']) }}</div>
            </div>
        @else
        <div class="mb-8 break-after-page">
        <x-doc-sheet titre="BULLETIN DE PAIE" :numero="null">
            <div class="mb-6 grid grid-cols-2 gap-6 rounded-lg bg-slate-50 p-4">
                <div><div class="text-[10px] font-semibold uppercase text-slate-400">Employeur</div>@if($logoEmp)<img src="{{ $logoEmp }}" alt="" class="my-1 max-h-12 max-w-40">@endif<div class="font-bold">{{ $client->nom }}</div><div class="text-xs">{{ $client->adresse }} {{ $villeEmp }}</div><div class="text-xs">MF {{ $client->matricule_fiscal }} · CNSS {{ $client->matricule_cnss }}</div></div>
                <div><div class="text-[10px] font-semibold uppercase text-slate-400">Salarié</div><div class="font-bold">{{ $x['nom'] }}</div><div class="text-xs">{{ $x['poste'] }}</div><div class="text-xs">CIN {{ $x['cin'] ?? '—' }} · CNSS {{ $x['cnss'] ?? '—' }}</div></div>
            </div>
            <div class="mb-3 text-sm">Période : <b class="capitalize">{{ mois_fr($mois) }}</b></div>
            <table class="w-full text-sm">
                <thead><tr class="bg-slate-100 text-left text-xs uppercase text-slate-600"><th class="px-3 py-2">Rubrique</th><th class="px-3 py-2 text-right">Montant (TND)</th></tr></thead>
                <tbody>
                    @foreach($lignes($x) as $row)
                        <tr class="border-b border-slate-200 {{ ($row[2] ?? 0) === 1 ? 'bg-slate-50 font-bold' : '' }} {{ ($row[2] ?? 0) === 2 ? 'text-xs italic text-slate-500' : '' }}"><td class="px-3 py-2">{{ $row[0] }}</td><td class="px-3 py-2 text-right font-mono">{{ tnd($row[1], false) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4 text-xs text-slate-500">Charges patronales : CNSS {{ tnd($x['cnssP']) }} · TFP {{ tnd($x['tfp']) }} · FOPROLOS {{ tnd($x['fop']) }} — coût total employeur {{ tnd($x['cout']) }}</div>
        </x-doc-sheet>
        </div>
        @endif
    @empty <div class="card"><x-empty title="Aucun salarié"/></div> @endforelse
</x-layouts.app>
