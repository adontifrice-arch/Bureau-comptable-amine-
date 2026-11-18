@php $titre = mb_strtoupper(\App\Services\Journaux::VUES[$vueJ]).' — '.mb_strtoupper($periodeJ); @endphp
<x-layouts.app :title="\App\Services\Journaux::VUES[$vueJ].' '.$annee">
    <div class="no-print"><x-page-header :title="\App\Services\Journaux::VUES[$vueJ]" :subtitle="$client->nom.' · '.$periodeJ" :back="route('compta.index', array_filter(['tab' => 'jnl', 'vue' => $vueJ, 'annee' => $annee, 'mois' => $moisJ, 'journal' => $journalJ]))"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @push('head')<style>@page { size: A4 portrait; margin: 10mm }</style>@endpush
    <x-doc-sheet :titre="$titre">
        <div class="mb-4 text-sm"><b>{{ $client->nom }}</b>{{ $client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : '' }} — {{ $periodeJ }}@if($journalJ) · journal {{ $journalJ }} — {{ \App\Models\Ecriture::journaux($client->id)[$journalJ] ?? '' }}@endif</div>
        @include('compta._journaux-edition', ['lien' => false])
    </x-doc-sheet>
</x-layouts.app>
