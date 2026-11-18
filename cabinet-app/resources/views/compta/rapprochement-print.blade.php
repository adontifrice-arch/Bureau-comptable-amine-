<x-layouts.app :title="'État de rapprochement '.$compteBq">
    <div class="no-print"><x-page-header title="État de rapprochement bancaire" :subtitle="$client->nom.' · compte '.$compteBq.' · arrêté au '.dfr($dateRap)" :back="route('compta.index', ['tab' => 'bq', 'vue' => 'rapprochement', 'compte' => $compteBq, 'date' => $dateRap])"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @push('head')<style>@page { size: A4 portrait; margin: 12mm }</style>@endpush
    <x-doc-sheet titre="ÉTAT DE RAPPROCHEMENT BANCAIRE">
        <div class="mb-4 text-sm"><b>{{ $client->nom }}</b>{{ $client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : '' }} — compte {{ $compteBq }} {{ \App\Services\Compta::libelle($compteBq) }} — arrêté au {{ dfr($dateRap) }}</div>
        @include('compta._rapprochement-etat')
    </x-doc-sheet>
</x-layouts.app>
