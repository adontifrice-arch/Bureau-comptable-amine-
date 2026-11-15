<x-layouts.app :title="'Balance '.$annee">
    <div class="no-print"><x-page-header :title="'Balance générale '.$annee" :subtitle="$client->nom" :back="route('compta.index', ['tab' => 'bal', 'annee' => $annee])"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @push('head')<style>@page { size: A4 landscape; margin: 10mm }</style>@endpush
    <x-doc-sheet :titre="'BALANCE '.$annee" landscape>
        <div class="mb-4 text-sm"><b>{{ $client->nom }}</b>{{ $client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : '' }} — du 01/01/{{ $annee }} au 31/12/{{ $annee }}</div>
        @include('compta._balance', ['lien' => false])
    </x-doc-sheet>
</x-layouts.app>
