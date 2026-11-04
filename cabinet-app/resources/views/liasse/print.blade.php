<x-layouts.app :title="'États financiers '.$annee">
    <div class="no-print"><x-page-header :title="'États financiers '.$annee" :subtitle="$client->nom" :back="route('liasse.index', ['annee' => $annee])"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    <x-doc-sheet titre="ÉTATS FINANCIERS">
        <div class="mb-6 text-sm"><b class="text-base">{{ $client->nom }}</b><br>{{ $client->forme }} · {{ $client->adresse }}<br>Matricule fiscal : {{ $client->matricule_fiscal ?: '—' }}<br>Exercice clos le 31 décembre {{ $annee }} — montants en dinars tunisiens</div>
        @include('liasse._etats', ['show' => ['bil', 'res', 'fis']])
    </x-doc-sheet>
</x-layouts.app>
