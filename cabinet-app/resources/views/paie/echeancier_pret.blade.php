{{--
    Échéancier de prêt du salarié : un prêt, ou tous les prêts d'un salarié (Prêt → Échéancier de prêt (salarié)).
    Chaque échéance mensuelle retenue sur la paie, son état (retenue sur une paie clôturée, soldée par le STC, à venir) et le reste dû.
--}}
@extends('paie.documents.layout', ['titre' => 'Échéancier de prêt — '.$salarie->nomComplet(), 'retour' => route('paie.index', ['tab' => 'prets', 'type' => $prets->first()?->type ?? 'societe', 'mois' => $mois])])
@section('corps')
    @php ['resp' => $resp, 'respQ' => $respQ, 'ville' => $ville] = \App\Support\DocPaie::employeur($client); @endphp
    <div class="feuille" contenteditable="true" spellcheck="false">
        @include('paie.documents._entete')
        <h1>Échéancier de prêt<small>{{ $prets->count() > 1 ? $prets->count().' prêts' : \App\Models\Pret::TYPES[$prets->first()->type] }} — situation à fin {{ mois_fr($mois) }}</small></h1>
        <div class="infos">
            <div><span>Salarié</span><span><b>{{ $salarie->nomComplet() }}</b>{{ $salarie->matricule ? ' — matricule '.$salarie->matricule : '' }}</span></div>
            <div><span>CIN</span><span>{{ $salarie->cin ?: '……………' }}</span></div>
            <div><span>Qualification</span><span>{{ $salarie->poste ?: '……………' }}</span></div>
        </div>

        @php $totaux = ['montant' => 0.0, 'rembourse' => 0.0, 'reste' => 0.0]; @endphp
        @foreach($prets as $p)
            @php
                $sit = $p->situation($mois);
                foreach (['rembourse', 'reste'] as $c) $totaux[$c] += $sit[$c];
                $totaux['montant'] += $p->montant;
                $reste = (float) $p->montant; $n = 0;
            @endphp
            <div class="cadre">
                <b>{{ \App\Models\Pret::TYPES[$p->type] }}</b>{{ $p->reference ? ' — réf. '.$p->reference : '' }} accordé le {{ $p->date->format('d/m/Y') }} :
                <b>{{ tnd($p->montant) }}</b>, remboursable en {{ $p->nb_echeances }} échéance(s) mensuelle(s) de {{ tnd($p->echeance) }} retenue(s) sur le salaire,
                de {{ mois_fr($p->premier_mois) }} à {{ $p->dernierMois() ? mois_fr($p->dernierMois()) : '—' }}.@if($p->observation) <i>{{ $p->observation }}</i>@endif
            </div>
            <table class="t">
                <tr><th class="c" style="width: 8%">N°</th><th>Paie de</th><th class="n">Échéance</th><th class="n">Remboursé</th><th class="n">Reste dû</th><th class="c">État</th></tr>
                @foreach($p->echeancier() as $m => $ech)
                    @php $reste = r3($reste - $ech); $etat = ! empty($sit['stc']) && $m > $sit['stc'] ? 'Soldée par le STC' : (in_array($m, $closes, true) ? 'Retenue' : ($m <= $mois ? 'En cours' : 'À venir')); @endphp
                    <tr><td class="c">{{ ++$n }}</td><td style="text-transform: capitalize">{{ mois_fr($m) }}</td><td class="n">{{ tnd($ech, false) }}</td>
                        <td class="n">{{ tnd(r3((float) $p->montant - $reste), false) }}</td><td class="n">{{ tnd($reste, false) }}</td><td class="c">{{ $etat }}</td></tr>
                @endforeach
                <tr class="total"><td colspan="2">Situation à fin {{ mois_fr($mois) }}</td><td class="n">{{ tnd($p->montant, false) }}</td><td class="n">{{ tnd($sit['rembourse'], false) }}</td><td class="n">{{ tnd($sit['reste'], false) }}</td><td></td></tr>
            </table>
        @endforeach

        @if($prets->count() > 1)
            <table class="t"><tr class="total"><td>Total des prêts</td><td class="n">{{ tnd($totaux['montant']) }}</td><td>Remboursé</td><td class="n">{{ tnd($totaux['rembourse']) }}</td><td>Reste dû</td><td class="n">{{ tnd($totaux['reste']) }}</td></tr></table>
        @endif

        <p style="font-size: 9pt">Le salarié autorise l’employeur à retenir chaque mois sur son salaire les échéances ci-dessus. En cas de départ de l’entreprise, le reste dû est retenu sur le solde de tout compte.</p>
        <p>Fait à {{ $ville }}, le {{ now()->format('d/m/Y') }}.</p>
        <div class="deux">
            <div><b>Le salarié</b><br><span style="font-size: 8.5pt">(signature précédée de « Lu et approuvé »)</span><div class="sig"></div></div>
            <div style="text-align: right"><b>Pour l’employeur</b><br><span style="font-size: 8.5pt">{{ $resp }}, {{ $respQ }}</span><div class="sig"></div></div>
        </div>
    </div>
@endsection
