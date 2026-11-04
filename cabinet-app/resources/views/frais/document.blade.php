<x-layouts.app :title="'Note de frais '.$doc->numero">
    <div class="no-print"><x-page-header :title="'Note de frais '.$doc->numero" :back="route('frais.index')"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    <x-doc-sheet titre="NOTE DE FRAIS" :numero="$doc->numero" sig="Signature du client">
        <div class="flex justify-between">
            <div><div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Adressée à</div><div class="mt-1 text-base font-bold">{{ $doc->client->nom }}</div>
                <div class="text-slate-600">{{ $doc->client->adresse }}</div>@if($doc->client->matricule_fiscal)<div class="text-slate-600">Matricule fiscal : {{ $doc->client->matricule_fiscal }}</div>@endif</div>
            <div class="text-right">Date : {{ dfr($doc->date) }}</div>
        </div>
        <table class="mt-8 w-full"><thead><tr class="bg-slate-100 text-left text-xs uppercase text-slate-600"><th class="px-3 py-2">Désignation</th><th class="px-3 py-2">Date</th><th class="px-3 py-2 text-right">Montant</th></tr></thead><tbody>
            @foreach($doc->lignes as $l)<tr class="border-b border-slate-200"><td class="px-3 py-2.5">{{ $l['designation'] }}</td><td class="px-3 py-2.5">{{ dfr($l['date'] ?? null) }}</td><td class="px-3 py-2.5 text-right font-mono">{{ tnd($l['montant'], false) }}</td></tr>@endforeach
        </tbody></table>
        <div class="mt-4 ml-auto flex w-72 justify-between border-t-2 border-slate-800 pt-2 text-base font-bold"><span>Total</span><span class="font-mono">{{ tnd($doc->total()) }}</span></div>
        @if($doc->observations)<p class="mt-6 whitespace-pre-line text-xs text-slate-600">{{ $doc->observations }}</p>@endif
        @if($cab['rib'] || $cab['iban'])<div class="mt-6 rounded-lg bg-slate-50 p-4 text-xs"><b>Coordonnées bancaires</b><br>@foreach(['banque' => 'Banque', 'titulaire' => 'Titulaire', 'rib' => 'RIB', 'iban' => 'IBAN', 'bic' => 'BIC'] as $k => $l)@if($cab[$k]){{ $l }} : {{ $cab[$k] }}<br>@endif @endforeach</div>@endif
    </x-doc-sheet>
</x-layouts.app>
