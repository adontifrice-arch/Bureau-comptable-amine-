{{--
    Bulletin de paie au format A5 (modèle « Désignation | Nombre ou base | Part salariale : Montant, Ret.Sal »).
    Mis en page en tableaux et en mm pour être identique à l'écran, à l'impression et en PDF (DomPDF).
    $x : résultat de paie ; $s : fiche employé (Salarie, peut être null) ; $variante : '' (simple), 'jours' (taux journalier),
    'conge' (congés acquis, pris et reste de l'année), 'cin' (salarié identifié par son CIN).
--}}
@once
<style>
    .bp { width: 100%; font-family: 'Times New Roman', 'DejaVu Serif', serif; font-size: 7.5pt; color: #000; background: #fff; }
    .bp table { border-collapse: collapse; width: 100%; }
    .bp td, .bp th { padding: 0; vertical-align: top; }
    .bp .bp-titre td { font-weight: bold; padding: 0 1mm 1mm; border-bottom: 0.3mm solid #000; }
    .bp .bp-titre .bp-t { font-size: 10pt; }
    .bp .bp-cadre { border: 0.4mm solid #000; margin-top: 2mm; }
    .bp .bp-emp { border: 0.3mm solid #000; border-radius: 2mm; border-top: none; border-left: none; padding: 1mm 2mm 1.5mm; height: 26mm; }
    .bp .bp-emp .bp-lib { text-align: center; font-weight: bold; font-style: italic; text-decoration: underline; font-size: 9pt; }
    .bp .bp-sal { padding: 1mm 2mm; }
    .bp .bp-sal b, .bp .bp-emp b { font-family: 'Cambria', 'DejaVu Serif', serif; }
    .bp .bp-petit { font-size: 6.5pt; }
    .bp .bp-info th { font-size: 6pt; padding: 1.5mm 0.5mm 0.5mm; border-bottom: 0.3mm solid #000; text-align: center; }
    .bp .bp-info td { font-size: 6.5pt; padding: 1mm 0.5mm; text-align: center; height: 6mm; vertical-align: middle; }
    .bp .bp-enf { font-size: 5.5pt; line-height: 1; display: inline-block; vertical-align: middle; text-align: right; }
    .bp .bp-lignes th { font-size: 8.5pt; font-family: 'Cambria', 'DejaVu Serif', serif; text-align: center; border: 0.3mm solid #000; padding: 0.5mm; }
    .bp .bp-lignes thead tr:first-child th { border-top: 0.4mm solid #000; }
    .bp .bp-lignes td { font-size: 6.5pt; height: 4mm; line-height: 1.25; padding: 0.4mm 1mm 0; border-left: 0.3mm solid #000; white-space: nowrap; overflow: hidden; }
    .bp .bp-lignes td:first-child { border-left: none; }
    .bp .bp-lignes .bp-n { text-align: right; }
    .bp .bp-lignes .bp-g td { font-weight: bold; }
    .bp .bp-pied td { border-top: 0.4mm solid #000; font-weight: bold; font-size: 9pt; height: 9mm; vertical-align: middle; padding: 0 2mm; font-family: 'Cambria', 'DejaVu Serif', serif; }
    .bp .bp-conge td { font-size: 6.5pt; text-align: center; border-top: 0.3mm solid #000; padding: 0.8mm; }
</style>
@endonce
@php
    $n3f = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 3, ',', ' ');
    $mt = fn ($v) => $v === null ? '' : tnd($v, false);
    $s = $s ?? null;
    $per = \Carbon\Carbon::parse(($x['_mois'] ?? $mois).'-01')->locale('fr');
    $periode = ! empty($x['special']) && ! empty($x['_periode']) ? $x['_periode'] : ucfirst($per->translatedFormat('F')).'  -  '.$per->year;
    $horaire = ($x['regime'] ?? 'mensuel') === 'horaire';
    $hj = (float) ($P['heures_jour'] ?? 8) ?: 8;
    $adrSal = $s ? trim(implode(' ', array_filter([$s->adresse, $s->code_postal, $s->ville]))) : '';
    $contrat = $s?->type_contrat ? (\App\Models\Salarie::CONTRATS[$s->type_contrat] ?? $s->type_contrat) : '';
    $dateContrat = $s?->contrat_debut ?? $s?->date_embauche;
    $enfants = $s ? array_values(array_filter((array) $s->enfants_detail, fn ($e) => ! empty($e['a_charge']) || ! empty($e['etudiant']) || ! empty($e['infirme']))) : [];
    $nbEnf = $s ? ($enfants ? count($enfants) : (int) $s->enfants) : null;
    $lignes = \App\Services\PaieCalc::lignesBulletinA5($x, $P);
    // Hauteur de la zone des lignes : les colonnes descendent jusqu'au pied du bulletin.
    $zone = $variante === 'conge' && isset($x['_conge']) ? 106 : 112;
    $vide = max(0, $zone - count($lignes) * 4.4);
@endphp
<div class="bp">
    <table class="bp-titre"><tr><td class="bp-t">Bulletin de Paie</td><td style="text-align: right">{{ $periode }}</td></tr></table>
    <div class="bp-cadre">
        <table><tr>
            <td style="width: 48%"><div class="bp-emp">
                <div class="bp-lib">Employeur</div>
                @if(! empty($logoEmp))<img src="{{ $logoEmp }}" alt="" style="max-height: 7mm; max-width: 25mm; float: right">@endif
                <div style="font-weight: bold; font-size: 8pt; margin: 1mm 0 2mm">{{ $client->nom }}</div>
                <div class="bp-petit">{{ $client->adresse }}@if($villeEmp)<br>{{ $villeEmp }}@endif</div>
                <div style="margin-top: 1.5mm"><b style="font-size: 8pt">N° Employeur :</b> &nbsp;{{ $client->matricule_cnss }}</div>
            </div></td>
            <td class="bp-sal">
                <div><b style="text-decoration: underline">Employé</b> &nbsp;<b>{{ $x['matricule'] ?? '' }}{{ ! empty($x['matricule']) ? ' - ' : '' }}{{ $x['nom'] }}</b></div>
                <div class="bp-petit">{{ $adrSal }}</div>
                <table style="margin-top: 1.5mm">
                    <tr><td style="width: 50%"><b>Catégorie</b> &nbsp;{{ $s?->categorie }}</td><td><b>Echelon</b> &nbsp;{{ $s?->echelon }}</td></tr>
                    <tr><td colspan="2"><b>RIB/ RIP</b> &nbsp;{{ $s?->rib }}</td></tr>
                    @if($variante === 'cin')<tr><td colspan="2"><b>N° CIN</b> &nbsp;{{ $x['cin'] ?: '—' }}</td></tr>@endif
                    <tr><td colspan="2"><b>N° C.N.S.S</b> &nbsp;{{ $x['cnss'] ?? '' }}</td></tr>
                    <tr><td><b>Contrat</b> &nbsp;{{ $contrat }}</td><td style="text-align: right"><b>Date</b> &nbsp;{{ $dateContrat?->format('d/m/Y') }}</td></tr>
                </table>
            </td>
        </tr></table>
        <table class="bp-info">
            <tr><th style="width: 18%">Qualification</th><th>Profil</th><th>Sit. Fam</th><th>Chef<br>Fam</th><th>Nb. Enf</th><th>{{ $horaire ? 'H.Ouv' : 'J.Ouv' }}</th><th>{{ $horaire ? 'H.Trav' : 'J.Trav' }}</th>@if($variante === 'jours')<th><abbr title="Taux journalier" style="text-decoration: none">T.Jour</abbr></th>@endif<th>S.BASE</th></tr>
            <tr>
                <td>{{ $x['poste'] }}</td>
                <td>{{ $s?->profil ?? ($x['profil'] ?? '') }}</td>
                <td>{{ $s?->situation_familiale ? str_replace('(e)', ' (e)', \App\Models\Salarie::SITUATIONS[$s->situation_familiale] ?? $s->situation_familiale) : '' }}</td>
                <td>{{ $s ? ($s->chef_famille ? 'Oui' : 'Non') : '' }}</td>
                <td>{{ $nbEnf }}@if($enfants) <span class="bp-enf">@foreach($enfants as $i => $e){{ $i + 1 }}:{{ ! empty($e['infirme']) ? 'I' : (! empty($e['etudiant']) ? 'E' : 'O') }}@if(! $loop->last)<br>@endif @endforeach</span>@endif</td>
                <td>{{ isset($x['jours_mois']) ? $n3f($x['jours_mois']) : '' }}</td>
                <td>{{ isset($x['jours']) ? $n3f($x['jours']) : '' }}</td>
                @if($variante === 'jours')
                    @php $jMois = $horaire ? (float) ($x['jours_mois'] ?? 0) / $hj : (float) ($x['jours_mois'] ?? 0); @endphp
                    <td>{{ $n3f($jMois > 0 ? $x['base'] / $jMois : 0) }}</td>
                @endif
                <td>{{ $mt($x['base'] ?? null) }}</td>
            </tr>
        </table>
        <table class="bp-lignes">
            <colgroup><col style="width: 46%"><col style="width: 19%"><col style="width: 19%"><col style="width: 16%"></colgroup>
            <thead>
                <tr><th rowspan="2" style="border-left: none; vertical-align: middle">Désignation</th><th style="border-bottom: none">Nombre ou</th><th colspan="2" style="border-right: none; border-bottom: 0.2mm solid #000">Part Salariale</th></tr>
                <tr><th style="border-top: none">Base</th><th style="text-align: left">Montant</th><th style="border-right: none">Ret.Sal</th></tr>
            </thead>
            <tbody>
                @foreach($lignes as $l)
                    @if($l[0] === null)
                        <tr><td></td><td></td><td></td><td></td></tr>
                    @else
                        <tr @class(['bp-g' => $l[4]])><td>{{ $l[0] }}</td><td class="bp-n" style="font-weight: normal">{{ $l[1] === null ? '' : $n3f($l[1]) }}</td><td class="bp-n">{{ $mt($l[2]) }}</td><td class="bp-n">{{ $mt($l[3]) }}</td></tr>
                    @endif
                @endforeach
                @if($vide > 0)<tr><td style="height: {{ $vide }}mm"></td><td></td><td></td><td></td></tr>@endif
            </tbody>
        </table>
        @if($variante === 'conge' && isset($x['_conge']))
            @php $c = $x['_conge']; $j = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',').' j'; @endphp
            <table class="bp-conge"><tr>
                @if($c['report'] ?? 0)<td>Report : <b>{{ $j($c['report']) }}</b></td>@endif
                <td>Congés acquis : <b>{{ $j($c['acquis']) }}</b></td><td>Congés pris : <b>{{ $j($c['pris']) }}</b></td><td>Reste congé : <b>{{ $j($c['reste']) }}</b></td>
            </tr></table>
        @endif
        <table class="bp-pied"><tr><td style="width: 65%">Signature</td><td style="border-left: 0.4mm solid #000">Net à payer &nbsp;&nbsp; {{ tnd($x['net'], false) }}</td></tr></table>
    </div>
</div>
