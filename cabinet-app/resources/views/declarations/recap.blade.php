{{-- Récapitulatif fiscal de l'année : impôts et taxes déclarés, mois par mois (déclarations mensuelles). --}}
@php $M = \App\Services\DeclarationMensuelle::class; $st = ['brouillon' => ['Brouillon', 'gray'], 'validee' => ['Validée', 'amber'], 'deposee' => ['Déposée', 'green']]; @endphp
<x-layouts.app title="Récapitulatif fiscal">
    <div class="no-print"><x-page-header title="Déclarations fiscales" :subtitle="$client->nom">
        <x-client-picker :clients="$clients" :current="$client" :route="route('declarations.recap')" :params="['annee' => $annee]"/>
    </x-page-header>
    @include('declarations._onglets', ['actif' => 'recap'])
    <div class="card mb-6 flex flex-wrap items-center gap-4 p-4">
        <form class="flex items-end gap-2"><div><label class="label">Année</label><input type="number" name="annee" value="{{ $annee }}" class="input w-28" onchange="this.form.submit()"></div></form>
        <div class="flex-1"><div class="font-semibold">Récapitulatif fiscal {{ $annee }}</div><div class="text-xs text-slate-500">Montants des déclarations mensuelles, principal et pénalités.</div></div>
        <a href="{{ route('declarations.recap', ['annee' => $annee, 'format' => 'csv']) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel</a>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</button>
    </div></div>
    <div class="card overflow-x-auto print:shadow-none">
        <div class="hidden p-4 text-center print:block"><div class="text-lg font-bold">Récapitulatif fiscal {{ $annee }}</div><div class="text-sm">{{ $client->nom }} · {{ $client->matricule_fiscal }}</div></div>
        <table class="tbl text-xs"><thead><tr><th>Mois</th>@foreach($M::SECTIONS as $s => $t)<th class="text-right">{{ $t }}</th>@endforeach<th class="text-right">Crédit de TVA</th><th class="text-right">Pénalités</th><th class="text-right">Total à payer</th><th class="no-print">Statut</th></tr></thead><tbody>
            @foreach($lignes as $mo => $l)
                <tr class="{{ $l ? '' : 'text-slate-400' }}"><td class="whitespace-nowrap"><a href="{{ route('declarations.index', ['type' => 'men', 'periode' => $mo]) }}" class="capitalize hover:text-brand-700">{{ mois_fr($mo) }}</a></td>
                    @if($l)
                        @foreach($M::SECTIONS as $s => $t)<td class="num">{{ $l['sections'][$s]['du'] ? tnd($l['sections'][$s]['du'], false) : '' }}@if($l['sections'][$s]['base'])<div class="text-[10px] font-normal text-slate-400">base {{ tnd($l['sections'][$s]['base'], false) }}</div>@endif</td>@endforeach
                        <td class="num">{{ $l['credit'] ? tnd($l['credit'], false) : '' }}</td><td class="num">{{ $l['pen'] ? tnd($l['pen'], false) : '' }}</td><td class="num font-semibold">{{ tnd($l['tot'], false) }}</td>
                        <td class="no-print"><span class="badge-{{ $st[$l['statut']][1] }}">{{ $st[$l['statut']][0] }}</span></td>
                    @else
                        <td colspan="{{ count($M::SECTIONS) + 3 }}" class="text-center">Non établie</td><td class="no-print"></td>
                    @endif
                </tr>
            @endforeach
        </tbody><tfoot><tr><td>Total {{ $annee }}</td>@foreach($M::SECTIONS as $s => $t)<td class="num">{{ tnd($tot['sections'][$s], false) }}@if($tot['bases'][$s])<div class="text-[10px] font-normal text-slate-400">base {{ tnd($tot['bases'][$s], false) }}</div>@endif</td>@endforeach<td></td><td class="num">{{ tnd($tot['pen'], false) }}</td><td class="num">{{ tnd($tot['tot'], false) }}</td><td class="no-print"></td></tr></tfoot></table>
    </div>
    <p class="no-print mt-3 text-xs text-slate-500">Sous chaque montant, sa base : salaires et sommes soumises à retenue, masse salariale (TFP, FOPROLOS), chiffre d’affaires taxable (TVA, droit de consommation, autres taxes, taxes locales).
        Le crédit de TVA est celui reporté à la fin de chaque mois ; il n’entre pas dans le total à payer. Cliquez sur un mois pour ouvrir sa déclaration.</p>
</x-layouts.app>
