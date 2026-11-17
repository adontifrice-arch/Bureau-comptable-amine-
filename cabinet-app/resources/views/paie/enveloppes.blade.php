@php
    $modes = \App\Services\Exports\EtatsPaie::MODES;
    $coupure = fn ($c) => $c >= 1000 ? ($c / 1000).' DT' : $c.' mill.';
    $kc = $client->paie_config ?? [];
    $logoEmp = !empty($kc['afficher_logo']) && !empty($kc['logo']) ? asset('storage/'.$kc['logo']) : null;
@endphp
<x-layouts.app :title="'Enveloppes de paie '.$mois">
    <style>@media print { @page { size: A4 portrait; margin: 8mm; } .enveloppe { break-inside: avoid; } }</style>
    <div class="no-print"><x-page-header :title="'Enveloppes de paie — '.$periode" :subtitle="$client->nom" :back="route('paie.index', ['mois' => $mois])">
        <div class="flex rounded-lg border border-slate-200 p-0.5 text-sm dark:border-slate-700">
            @foreach(['' => 'Tous'] + $modes as $c => $l)
                <a href="{{ route('paie.enveloppes', ['mois' => $mois, 'mode' => $c ?: null, 'paie' => $paie !== 'normale' ? $paie : null]) }}" class="rounded-md px-3 py-1 {{ (string) $mode === (string) $c ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">{{ $l }}</a>
            @endforeach
        </div>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</button>
    </x-page-header></div>

    @if($total)
        <div class="enveloppe mx-auto mb-6 max-w-3xl rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-900 shadow">
            <div class="mb-2 font-bold">Billetage des paiements en espèces — {{ $periode }}</div>
            <table class="w-full">
                <thead><tr class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><th class="py-1">Coupure</th><th class="py-1 text-right">Nombre</th><th class="py-1 text-right">Montant</th></tr></thead>
                @foreach($total as $c => $n)<tr class="border-b border-slate-100"><td class="py-0.5">{{ $coupure($c) }}</td><td class="py-0.5 text-right font-mono">{{ $n }}</td><td class="py-0.5 text-right font-mono">{{ tnd($c * $n / 1000, false) }}</td></tr>@endforeach
                <tr class="font-bold"><td class="py-1">Total à retirer</td><td></td><td class="py-1 text-right font-mono">{{ tnd(array_sum(array_map(fn ($c, $n) => $c * $n, array_keys($total), $total)) / 1000, false) }}</td></tr>
            </table>
        </div>
    @endif

    <div class="mx-auto grid max-w-3xl gap-6">
        @forelse($E as $x)
            <div class="enveloppe rounded-xl border-2 border-dashed border-slate-300 bg-white p-6 text-sm text-slate-900">
                <div class="flex items-start justify-between border-b border-slate-200 pb-3">
                    <div class="flex items-start gap-3">@if($logoEmp)<img src="{{ $logoEmp }}" alt="" class="max-h-10 max-w-20">@endif
                        <div><div class="font-bold">{{ $client->nom }}</div><div class="text-xs text-slate-500">{{ $client->adresse }}</div></div></div>
                    <div class="text-right"><div class="text-base font-bold tracking-wide">ENVELOPPE DE PAIE</div><div class="text-slate-600">{{ $x['special'] ?? mois_fr($mois) }}</div></div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1">
                    <div><span class="text-slate-500">Salarié :</span> <b>{{ $x['nom'] }}</b></div>
                    <div><span class="text-slate-500">Matricule :</span> {{ $x['matricule'] ?: '—' }}</div>
                    <div><span class="text-slate-500">Fonction :</span> {{ $x['poste'] ?: '—' }}</div>
                    <div><span class="text-slate-500">Mode de paiement :</span> {{ $modes[$x['mode']] ?? '—' }}</div>
                    <div><span class="text-slate-500">Salaire brut :</span> <span class="font-mono">{{ tnd($x['brut']) }}</span></div>
                    <div><span class="text-slate-500">Retenues :</span> <span class="font-mono">{{ tnd($x['brut'] - $x['net']) }}</span></div>
                </div>
                <div class="mt-4 flex items-end justify-between gap-6">
                    @if($x['billets'])
                        <table class="text-xs">
                            <tr class="text-slate-500"><td class="pr-3">Coupure</td>@foreach($x['billets'] as $c => $n)<td class="px-1.5 text-center">{{ $coupure($c) }}</td>@endforeach</tr>
                            <tr><td class="pr-3 text-slate-500">Nombre</td>@foreach($x['billets'] as $n)<td class="px-1.5 text-center font-mono font-semibold">{{ $n }}</td>@endforeach</tr>
                        </table>
                    @else<div></div>@endif
                    <div class="rounded-lg border-2 border-slate-800 px-4 py-2 text-right"><div class="text-xs uppercase text-slate-500">Net à payer</div><div class="font-mono text-xl font-bold">{{ tnd($x['net']) }}</div></div>
                </div>
                <div class="mt-6 flex justify-between text-xs text-slate-500"><span>Signature de l’employeur</span><span>Reçu, signature du salarié</span></div>
                <div class="h-10"></div>
            </div>
        @empty
            <div class="card"><x-empty icon="wallet" title="Aucun salarié à payer">{{ $mode ? 'Aucun salarié payé par '.mb_strtolower($modes[$mode]).' ce mois-ci.' : 'Aucune paie pour ce mois.' }}</x-empty></div>
        @endforelse
    </div>
</x-layouts.app>
