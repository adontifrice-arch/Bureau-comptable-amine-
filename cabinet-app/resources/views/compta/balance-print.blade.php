<x-layouts.app :title="ucfirst(mb_strtolower($titre))">
    <div class="no-print"><x-page-header :title="ucfirst(mb_strtolower($titre))" :subtitle="$client->nom" :back="route('compta.index', ['tab' => 'bal', 'annee' => $annee, 'vue' => $vue] + ($vue === 'centralisee' ? ['niveau' => $niveau] : []))"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @push('head')<style>@page { size: A4 landscape; margin: 10mm }</style>@endpush
    <x-doc-sheet :titre="$titre" landscape>
        <div class="mb-4 text-sm"><b>{{ $client->nom }}</b>{{ $client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : '' }} — du 01/01/{{ $annee }} au 31/12/{{ $annee }}{{ $vue === 'comparative' ? ', comparé à '.($annee - 1) : '' }}</div>
        @include('compta._balance-vue', ['lien' => false])
    </x-doc-sheet>
</x-layouts.app>
