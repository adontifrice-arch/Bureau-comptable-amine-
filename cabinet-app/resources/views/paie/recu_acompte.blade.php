{{--
    Reçu d'avance sur salaire (Acompte → Saisie des acomptes) : remis après la saisie de l'acompte, à faire signer par le salarié.
    Deux exemplaires sur la feuille (employeur, salarié), séparés par un trait de coupe.
--}}
@extends('paie.documents.layout', ['titre' => 'Reçu d’avance sur salaire — '.$a->salarie?->nomComplet(), 'retour' => route('paie.index', ['tab' => 'acomptes', 'mois' => $a->mois])])
@section('corps')
    @php
        ['resp' => $resp, 'respQ' => $respQ, 'ville' => $ville] = \App\Support\DocPaie::employeur($client);
        $s = $a->salarie;
        $numero = str_pad((string) $a->id, 6, '0', STR_PAD_LEFT);
    @endphp
    <div class="feuille" contenteditable="true" spellcheck="false">
        @foreach(['Exemplaire employeur', 'Exemplaire salarié'] as $ex)
            @if(! $loop->first)<div class="coupe">✂ — — — — — — — — — — — — — — — — — — — — — — — — — — — — — —</div>@endif
            @include('paie.documents._entete')
            <h1 style="margin-bottom: 10pt">Reçu d’avance sur salaire<small>N° {{ $numero }} — {{ $ex }}</small></h1>
            <table class="t">
                <tr><th style="width: 32%">Salarié</th><td><b>{{ $s?->nomComplet() }}</b>{{ $s?->matricule ? ' — matricule '.$s->matricule : '' }}{{ $s?->cin ? ' — CIN '.$s->cin : '' }}</td></tr>
                <tr><th>Qualification</th><td>{{ $s?->poste ?: '……………' }}</td></tr>
                <tr><th>Date de l’avance</th><td>{{ $a->date->format('d/m/Y') }}</td></tr>
                <tr><th>Montant</th><td><b>{{ tnd($a->montant) }}</b></td></tr>
                <tr><th>Mode de versement</th><td>{{ \App\Models\Salarie::MODES[$a->mode] ?? $a->mode }}</td></tr>
                <tr><th>Retenue sur la paie de</th><td style="text-transform: capitalize">{{ mois_fr($a->mois) }}</td></tr>
            </table>
            <p>Je soussigné(e) <b>{{ $s?->nomComplet() }}</b> reconnais avoir reçu de la société <b>{{ $client->nom }}</b> la somme de
                <b>{{ montant_lettres($a->montant) }}</b> ({{ tnd($a->montant) }}) à titre d’avance sur mon salaire, et accepte qu’elle soit retenue sur ma paie de {{ mois_fr($a->mois) }}.</p>
            @if($a->observation)<p style="font-size: 9pt">Observation : {{ $a->observation }}</p>@endif
            <div class="deux" style="margin-top: 8pt">
                <div><b>Le salarié</b><br><span style="font-size: 8.5pt">(signature précédée de « Reçu la somme de … »)</span><div class="sig" style="height: 42pt"></div></div>
                <div style="text-align: right"><b>Pour l’employeur</b><br><span style="font-size: 8.5pt">{{ $resp }}, {{ $respQ }} — {{ $ville }}, le {{ $a->date->format('d/m/Y') }}</span><div class="sig" style="height: 42pt"></div></div>
            </div>
        @endforeach
    </div>
@endsection
