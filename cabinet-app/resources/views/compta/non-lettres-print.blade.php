<x-layouts.app :title="'Comptes non lettrés au '.dfr($dateNL)">
    <div class="no-print"><x-page-header title="État des comptes non lettrés" :subtitle="$client->nom.' · au '.dfr($dateNL)" :back="route('compta.index', array_filter(['tab' => 'gl', 'vue' => 'nonlettres', 'annee' => substr($dateNL, 0, 4), 'date' => $dateNL, 'section' => $section, 'compte' => $compte]))"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @push('head')<style>@page { size: A4 portrait; margin: 10mm }</style>@endpush
    <x-doc-sheet :titre="'ÉTAT DES COMPTES NON LETTRÉS AU '.dfr($dateNL)">
        <div class="mb-4 text-sm"><b>{{ $client->nom }}</b>{{ $client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : '' }}
            @if($section) · {{ $section }} — {{ \App\Services\Balances::AUXILIAIRES[$section] ?? '' }}@endif @if($compte) · comptes {{ $compte }}…@endif</div>
        @include('compta._non-lettres', ['lien' => false])
    </x-doc-sheet>
</x-layouts.app>
