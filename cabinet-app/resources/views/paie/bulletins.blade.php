@php
    $kc = $client->paie_config ?? [];
    $logoEmp = !empty($kc['afficher_logo']) && !empty($kc['logo']) ? asset('storage/'.$kc['logo']) : null;
    $villeEmp = trim(($kc['code_postal'] ?? '').' '.($kc['ville'] ?? ''));
    $formats = \App\Http\Controllers\PaieController::FORMATS_BULLETIN;
    // A5 double : deux bulletins A5 côte à côte sur une feuille A4 paysage.
    $double = in_array($format, ['a5d', 'a5j', 'a5c', 'a5cin'], true);
    $variante = ['a5j' => 'jours', 'a5c' => 'conge', 'a5cin' => 'cin'][$format] ?? '';
@endphp
<x-layouts.app :title="'Bulletins '.$mois">
    @if($format === 'a5')<style>@media print { @page { size: A5 portrait; margin: 7mm; } }</style>@endif
    @if($double)<style>@media print { @page { size: A4 landscape; margin: 6mm; } .feuille { height: 196mm; } }</style>@endif
    @if($format === 'mat')<style>@media print { @page { size: A4 portrait; margin: 8mm; } } .matricielle { font-family: 'Courier New', Courier, monospace; font-size: 10pt; line-height: 1.25; color: #000; }</style>@endif
    <div class="no-print"><x-page-header :title="'Bulletins de paie — '.($formats[$format] ?? strtoupper($format)).' — '.($periode ?? mois_fr($mois))" :subtitle="$client->nom" :back="route('paie.index', ['mois' => $mois])">
        <select class="input-sm w-52" onchange="location.href = this.value">
            @foreach($formats as $f => $l)<option value="{{ route('paie.bulletins', ['format' => $f] + request()->except('format')) }}" @selected($f === $format)>{{ $l }}</option>@endforeach
        </select>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button>
    </x-page-header></div>

    @if(! count($R))
        <div class="card"><x-empty title="Aucun bulletin pour cette sélection"/></div>
    @elseif($double)
        @foreach(array_chunk($R, 2) as $paire)
            <div class="feuille mx-auto mb-8 grid max-w-6xl break-after-page grid-cols-2 gap-6">
                @foreach($paire as $x)@include('paie._bulletin_a5', ['x' => $x, 'variante' => $variante])@endforeach
            </div>
        @endforeach
    @elseif($format === 'mat')
        {{-- Matricielle : texte à chasse fixe sur 80 colonnes, sans couleur, pour imprimante matricielle. --}}
        @foreach($R as $x)
            @php
                $L = 80; $ligne = str_repeat('-', $L);
                $col = fn ($g, $d) => mb_substr($g, 0, $L - mb_strlen($d) - 1).str_repeat(' ', max(1, $L - mb_strlen(mb_substr($g, 0, $L - mb_strlen($d) - 1)) - mb_strlen($d))).$d;
                $txt = [$col(mb_strtoupper($client->nom), 'BULLETIN DE PAIE'), $col('MF '.$client->matricule_fiscal.'  CNSS '.$client->matricule_cnss, mb_strtoupper(($x['_periode'] ?? mois_fr($x['_mois'] ?? $mois)))), $ligne,
                    $col($x['nom'], 'Mat. '.($x['matricule'] ?? '-')), $col((string) $x['poste'], 'CIN '.($x['cin'] ?? '-').'  CNSS '.($x['cnss'] ?? '-')), $ligne];
                foreach (\App\Services\PaieCalc::lignesBulletin($x, $P) as $row) {
                    if (($row[2] ?? 0) === 1) $txt[] = str_repeat(' ', 50).str_repeat('-', 30);
                    $txt[] = $col((($row[2] ?? 0) === 2 ? '  ' : '').$row[0], number_format((float) $row[1], 3, ',', ' '));
                }
                $txt[] = $ligne;
                $txt[] = 'Charges patronales : CNSS '.n3($x['cnssP']).'  TFP '.n3($x['tfp']).'  FOPROLOS '.n3($x['fop']);
            @endphp
            <pre class="matricielle mx-auto mb-8 max-w-4xl break-after-page overflow-x-auto bg-white p-4">{{ implode("\n", $txt) }}</pre>
        @endforeach
    @elseif($format === 'a5')
        @foreach($R as $x)
            <div class="mx-auto mb-8 max-w-xl break-after-page">@include('paie._bulletin_a5', ['x' => $x, 'variante' => ''])</div>
        @endforeach
    @else
        @foreach($R as $x)
        <div class="mb-8 break-after-page">
        <x-doc-sheet titre="BULLETIN DE PAIE" :numero="null">
            <div class="mb-6 grid grid-cols-2 gap-6 rounded-lg bg-slate-50 p-4">
                <div><div class="text-[10px] font-semibold uppercase text-slate-400">Employeur</div>@if($logoEmp)<img src="{{ $logoEmp }}" alt="" class="my-1 max-h-12 max-w-40">@endif<div class="font-bold">{{ $client->nom }}</div><div class="text-xs">{{ $client->adresse }} {{ $villeEmp }}</div><div class="text-xs">MF {{ $client->matricule_fiscal }} · CNSS {{ $client->matricule_cnss }}</div></div>
                <div><div class="text-[10px] font-semibold uppercase text-slate-400">Salarié</div><div class="font-bold">{{ $x['nom'] }}</div><div class="text-xs">{{ $x['poste'] }}</div><div class="text-xs">Matricule {{ $x['matricule'] ?? '—' }} · CIN {{ $x['cin'] ?? '—' }} · CNSS {{ $x['cnss'] ?? '—' }}</div></div>
            </div>
            <div class="mb-3 text-sm">Période : <b class="capitalize">{{ ($x['_periode'] ?? mois_fr($x['_mois'] ?? $mois)) }}</b></div>
            <table class="w-full text-sm">
                <thead><tr class="bg-slate-100 text-left text-xs uppercase text-slate-600"><th class="px-3 py-2">Rubrique</th><th class="px-3 py-2 text-right">Montant (TND)</th></tr></thead>
                <tbody>
                    @foreach(\App\Services\PaieCalc::lignesBulletin($x, $P) as $row)
                        <tr class="border-b border-slate-200 {{ ($row[2] ?? 0) === 1 ? 'bg-slate-50 font-bold' : '' }} {{ ($row[2] ?? 0) === 2 ? 'text-xs italic text-slate-500' : '' }}"><td class="px-3 py-2">{{ $row[0] }}</td><td class="px-3 py-2 text-right font-mono">{{ tnd($row[1], false) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4 text-xs text-slate-500">Charges patronales : CNSS {{ tnd($x['cnssP']) }} · TFP {{ tnd($x['tfp']) }} · FOPROLOS {{ tnd($x['fop']) }}@if(! empty($x['etat'])) · quote-part de l’État (contrat Dignité) − {{ tnd($x['etat']) }}@endif — coût total employeur {{ tnd($x['cout']) }}</div>
        </x-doc-sheet>
        </div>
        @endforeach
    @endif
</x-layouts.app>
