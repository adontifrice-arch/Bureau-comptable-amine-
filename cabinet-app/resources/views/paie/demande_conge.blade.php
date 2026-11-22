{{-- Demande de congé (Congé → Demande de congé) : à remplir et signer par le salarié, puis décision de l'employeur. --}}
@extends('paie.documents.layout', ['titre' => 'Demande de congé — '.$e->nomComplet(), 'retour' => route('paie.index', ['tab' => 'conges', 'mois' => $mois])])
@section('corps')
    @php
        ['resp' => $resp, 'respQ' => $respQ, 'ville' => $ville] = \App\Support\DocPaie::employeur($client);
        $d = fn ($x) => $x ? $x->format('d/m/Y') : '……/……/…………';
        $nb = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
        $paye = $c->type === 'paye';
        $mPrec = ($c->du ?? now())->copy()->startOfMonth()->subMonth()->format('Y-m');
    @endphp
    <div class="feuille" contenteditable="true" spellcheck="false">
        @include('paie.documents._entete')
        <h1>Demande de congé</h1>

        <div class="cadre">
            <div class="infos" style="margin: 0">
                <div><span>Nom et prénom</span><span><b>{{ $e->nomComplet() }}</b></span></div>
                <div><span>Matricule</span><span>{{ $e->matricule ?: '……………' }}</span></div>
                <div><span>Qualification</span><span>{{ $e->poste ?: '……………' }}</span></div>
                @if($e->departement)<div><span>Département / service</span><span>{{ $e->departement }}</span></div>@endif
                <div><span>Date d’embauche</span><span>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</span></div>
            </div>
        </div>

        <p><b>Nature du congé demandé :</b></p>
        <p style="line-height: 1.9">
            @foreach(\App\Models\Conge::TYPES as $k => $l)<span style="white-space: nowrap; margin-right: 14pt"><span class="case" style="text-align: center; font-size: 8pt; line-height: 9pt">{{ $c->type === $k ? '✕' : '' }}</span>{{ $l }}</span> @endforeach
        </p>

        <table class="t">
            <tr><th style="width: 40%">Période demandée</th><td>du <b>{{ $d($c->du) }}</b> au <b>{{ $d($c->au) }}</b> inclus</td></tr>
            <tr><th>Nombre de jours</th><td><b>{{ $c->jours !== null ? $nb($c->jours) : '……' }}</b> jour(s) ouvrable(s)</td></tr>
            <tr><th>Date de reprise du travail</th><td>{{ $d($reprise) }}</td></tr>
            <tr><th>Motif / observation</th><td style="height: 18pt">{{ $c->observation }}</td></tr>
        </table>

        @if($paye)
            <p><b>Situation des congés payés</b> (établie par le service du personnel) :</p>
            <table class="t">
                <tr><th>Solde disponible à fin {{ mois_fr($mPrec) }}</th><td class="n">{{ $nb($solde['reste']) }} j</td></tr>
                <tr><th>Congé demandé</th><td class="n">{{ $c->jours !== null ? $nb($c->jours) : '……' }} j</td></tr>
                <tr class="total"><th>Solde après le congé</th><td class="n">{{ $c->jours !== null ? $nb($solde['reste'] - $c->jours) : '……' }} j</td></tr>
            </table>
        @endif

        <p>Fait à {{ $ville }}, le {{ now()->format('d/m/Y') }}.</p>
        <div class="deux">
            <div><b>Signature du salarié</b><div class="sig"></div></div>
            <div style="text-align: right"><b>Avis du responsable hiérarchique</b><div class="sig"></div></div>
        </div>

        <div class="cadre" style="margin-top: 6pt">
            <b>Décision de l’employeur</b><br>
            <span style="line-height: 2"><span class="case"></span>Accordé &nbsp;&nbsp;&nbsp; <span class="case"></span>Refusé &nbsp;&nbsp;&nbsp; <span class="case"></span>Reporté du ……/……/………… au ……/……/…………</span><br>
            Motif : ………………………………………………………………………………………………
            <div class="deux" style="margin-top: 8pt"><div>Le ……/……/…………</div><div style="text-align: right">{{ $resp }}, {{ $respQ }}<div class="sig" style="height: 40pt"></div></div></div>
        </div>
    </div>
@endsection
