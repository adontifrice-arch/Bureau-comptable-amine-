{{-- En-tête de l'employeur des documents de paie. --}}
@php ['k' => $k, 'logo' => $logo] = \App\Support\DocPaie::employeur($client); @endphp
<div class="entete">
    <div class="employeur">
        <b>{{ $client->nom }}</b><br>
        {{ trim($client->adresse.' '.trim(($k['code_postal'] ?? '').' '.($k['ville'] ?? ''))) }}<br>
        @if($client->matricule_fiscal)Matricule fiscal : {{ $client->matricule_fiscal }}@endif
        @if($client->matricule_cnss) · N° employeur CNSS : {{ $client->matricule_cnss }}@endif
        @if($client->telephone)<br>Tél. : {{ $client->telephone }}@endif
    </div>
    @if($logo)<div style="text-align: right"><img src="{{ $logo }}" alt=""></div>@endif
</div>
