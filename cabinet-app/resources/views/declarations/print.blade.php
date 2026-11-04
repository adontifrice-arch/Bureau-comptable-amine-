@php $lab = ['men' => 'MENSUELLE', 'ann' => 'ANNUELLE', 'emp' => 'D’EMPLOYEUR'][$type]; @endphp
<x-layouts.app title="Déclaration">
    <div class="no-print"><x-page-header :title="'Déclaration '.strtolower($lab)" :subtitle="$client->nom" :back="route('declarations.index', ['type' => $type, 'periode' => $periode])"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    <x-doc-sheet :titre="'DÉCLARATION '.$lab" :numero="$type === 'men' ? mois_fr($periode) : $periode">
        <div class="mb-6 text-sm"><b>{{ $client->nom }}</b> · Matricule fiscal {{ $client->matricule_fiscal ?: '—' }}<br>Statut : {{ ['brouillon' => 'Brouillon', 'validee' => 'Validée', 'deposee' => 'Déposée'][$d->statut] }}</div>
        @if($type !== 'emp') @include('declarations._champs', ['print' => true])
        @else
            <table class="w-full text-sm"><thead><tr class="bg-slate-100 text-left text-xs uppercase"><th class="px-3 py-2">Annexe</th><th class="px-3 py-2 text-right">Lignes</th><th class="px-3 py-2 text-right">Montants</th><th class="px-3 py-2 text-right">Retenues</th></tr></thead><tbody>
                @foreach($rec as $x)<tr class="border-b"><td class="px-3 py-2">{{ $x['t'] }}</td><td class="px-3 py-2 text-right">{{ $x['n'] }}</td><td class="px-3 py-2 text-right font-mono">{{ tnd($x['brut'], false) }}</td><td class="px-3 py-2 text-right font-mono">{{ tnd($x['ret'], false) }}</td></tr>@endforeach
            </tbody></table>
            @foreach(\App\Http\Controllers\DeclarationController::ANNEXES as $k => $t) @if(!empty($d->annexes[$k]))
                <h3 class="mt-6 mb-2 font-semibold">{{ $t }}</h3>
                <table class="w-full text-xs"><thead><tr class="bg-slate-100 text-left"><th class="px-2 py-1">Identifiant</th><th class="px-2 py-1">Bénéficiaire</th><th class="px-2 py-1 text-right">Brut</th><th class="px-2 py-1 text-right">Retenue</th><th class="px-2 py-1 text-right">Net</th></tr></thead><tbody>
                @foreach($d->annexes[$k] as $l)<tr class="border-b"><td class="px-2 py-1">{{ $l['id'] ?? '' }}</td><td class="px-2 py-1">{{ $l['nom'] }}</td><td class="px-2 py-1 text-right font-mono">{{ tnd($l['brut'], false) }}</td><td class="px-2 py-1 text-right font-mono">{{ tnd($l['ret'], false) }}</td><td class="px-2 py-1 text-right font-mono">{{ tnd($l['net'], false) }}</td></tr>@endforeach
                </tbody></table>
            @endif @endforeach
        @endif
    </x-doc-sheet>
</x-layouts.app>
