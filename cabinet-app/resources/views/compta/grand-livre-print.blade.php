<x-layouts.app :title="'Grand livre auxiliaire '.$annee">
    <div class="no-print"><x-page-header :title="'Grand livre auxiliaire '.$annee" :subtitle="$client->nom" :back="route('compta.index', array_filter(['tab' => 'gl', 'vue' => 'auxiliaire', 'annee' => $annee, 'section' => $section, 'compte' => $compte]))"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @push('head')<style>@page { size: A4 portrait; margin: 10mm }</style>@endpush
    <x-doc-sheet :titre="'GRAND LIVRE AUXILIAIRE '.$annee">
        <div class="mb-4 text-sm"><b>{{ $client->nom }}</b>{{ $client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : '' }} — du 01/01/{{ $annee }} au 31/12/{{ $annee }}
            @if($section) · {{ $section }} — {{ \App\Services\Balances::AUXILIAIRES[$section] ?? '' }}@endif @if($compte) · comptes {{ $compte }}…@endif</div>
        @include('compta._grand-livre-aux', ['lien' => false])
    </x-doc-sheet>
</x-layouts.app>
