<x-layouts.app :title="'Échéancier au '.dfr($dateEch)">
    <div class="no-print"><x-page-header title="Échéancier clients et fournisseurs" :subtitle="$client->nom.' · au '.dfr($dateEch)" :back="route('compta.index', array_filter(['tab' => 'gl', 'vue' => 'echeancier', 'annee' => substr($dateEch, 0, 4), 'date' => $dateEch, 'section' => $section, 'compte' => $compte, 'detail' => $detail ? 1 : null]))"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @push('head')<style>@page { size: A4 landscape; margin: 8mm }</style>@endpush
    <x-doc-sheet :titre="'ÉCHÉANCIER CLIENTS ET FOURNISSEURS AU '.dfr($dateEch)" landscape>
        <div class="mb-4 text-sm"><b>{{ $client->nom }}</b>{{ $client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : '' }}
            @if($section) · {{ $section }} — {{ \App\Services\Balances::AUXILIAIRES[$section] ?? '' }}@endif @if($compte) · comptes {{ $compte }}…@endif</div>
        @include('compta._echeancier', ['lien' => false])
    </x-doc-sheet>
</x-layouts.app>
