{{-- Déclaration de l'impôt sur le revenu des personnes physiques, édition officielle DGI en français (1re et 2e partie), remplie. --}}
@php
    $A = \App\Services\DeclarationAnnuelle::class;
    $f = $A::champs($d->champs ?? [], $client, (int) $periode); $C = $A::calculIr($f); $imp = $A::impotsIr($f);
    $mf = \App\Services\Exports\FichierDgi::matricule($client->matricule_fiscal);
    preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', (string) $client->adresse, $cp);
    $n = fn ($k) => $A::n($f, $k);
    $m = fn ($x) => number_format((float) $x, 3, '.', '');                                   // présentation de l'édition officielle : 14546.708
    $X = fn ($b) => $b ? 'X' : '';
    $bp = fn ($v) => (float) $v < 0 ? 'P' : 'B';                                             // résultat : B = bénéfice, P = perte
    $pr = fn ($v) => (float) $v < 0 ? 'R' : 'P';                                             // impôt : P = à payer, R = report (trop perçu)
    $T = fn ($t) => array_values((array) ($f[$t] ?? []));
    $sections = $A::sectionsIr($client, $f);
    $L = fn ($lib, $v, $ind = 0, $signe = null, $gras = false) => '<tr><td class="lib'.($gras ? ' gras' : '').'" style="padding-left:'.(0.6 + 1.1 * $ind).'rem">'.e($lib).'</td>'
        .'<td class="bp">'.($signe ? $signe : '').'</td><td class="mt">'.$m($v).'</td></tr>';
    $S = fn ($t, $ind = 0) => '<tr><td class="lib gras" colspan="3" style="padding-left:'.(0.6 + 1.1 * $ind).'rem">'.e($t).'</td></tr>';
    $parts = fn ($p) => $L('Part dans le bénéfice ou dans la perte des sociétés de personnes et assimilées et plus-value réalisée de la cession des droits dans lesdites sociétés', 0, 0)
        .$L('Parts', $n($p.'_qp'), 1, $bp($n($p.'_qp'))).$L('Plus-value', $n($p.'_pv'), 1).$L('Résultat', $n($p.'_qp') + $n($p.'_pv'), 1, $bp($n($p.'_qp') + $n($p.'_pv')));
    $cat = ['bic_r' => $n('bic_res_fiscal') || $n('bic_ca_ttc'), 'bic_q' => $n('bic_qp') || $n('bic_pv'), 'bnc_r' => $n('bnc_res_fiscal') || $n('bnc_ca_ttc'), 'bnc_f' => $n('bnc_rec_ttc') || $n('bnc_rec_loc'), 'bnc_q' => $n('bnc_qp') || $n('bnc_pv'),
        'agr_r' => $n('agr_res_fiscal') || $n('agr_ca_tot'), 'agr_f' => $n('agr_rec'), 'agr_m' => $n('agr_forf'), 'agr_q' => $n('agr_qp') || $n('agr_pv'),
        'fon_r' => $n('fon_res_fiscal') || $n('fon_ca'), 'fon_f' => $n('fon_bati') || $n('fon_nb'), 'fon_q' => $n('fon_qp') || $n('fon_pv'),
        'sal' => (bool) $C['sal'], 'rcm' => $C['rcm'] || $n('rcm_div'), 'aut' => $C['aut'] || $n('jeux'), 'exo' => (bool) $C['exo_tot'], 'ni' => (bool) $C['ni_tot']];
    $ded = $A::IR['ded'][2]; $liq = $A::IR['liq'][2];
@endphp
<x-layouts.app title="Déclaration de l’impôt sur le revenu des personnes physiques">
    <div class="no-print"><x-page-header title="Édition officielle en français" :subtitle="$client->nom.' · revenus '.$periode" :back="route('declarations.index', ['type' => 'ann', 'periode' => $periode])">
        <a href="{{ route('declarations.arabe', ['type' => 'ann', 'periode' => $periode]) }}" class="btn-secondary">Formulaire en arabe</a>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    <style>
        .dur { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px }
        .dur table { width: 100%; border-collapse: collapse }
        .dur .g th, .dur .g td { border: 1px solid #555; padding: 3px 5px; vertical-align: middle }
        .dur .g th { background: #e8ecf1; font-weight: 600; text-align: center; font-size: 11px }
        .dur .lib { text-align: left }
        .dur .mt { text-align: right; font-family: 'IBM Plex Mono', monospace; white-space: nowrap; width: 9rem }
        .dur .bp { text-align: center; width: 1.6rem; font-weight: 700 }
        .dur .gras { font-weight: 700 }
        .dur .coche { text-align: center; font-weight: 700; height: 1.6rem }
        .dur .titre { background: #1e3a5f; color: #fff; font-weight: 700; padding: 4px 8px; margin-top: 14px }
        .dur .bloc { border: 1px solid #555; padding: 8px; margin-top: 10px }
        @media print { .dur .saut { break-before: page } }
    </style>

    <div class="dur mx-auto max-w-5xl bg-white p-6 shadow-sm print:max-w-none print:p-0 print:shadow-none">
        {{-- ======================= 1re partie ======================= --}}
        <div class="text-right text-xs text-slate-500">Édition (1ère partie)</div>
        <table><tr>
            <td class="w-40 text-center text-xs font-bold leading-5">République Tunisienne<br>Ministère des Finances<br>D.G.I</td>
            <td class="text-center"><div class="text-lg font-bold">Déclaration de l’impôt sur le revenu des personnes physiques</div>
                <div class="mt-1">Résultat de l’exercice : <b>{{ $periode }}</b></div></td>
            <td class="w-48 text-xs">Date de la validation : {{ $d->validee_le ? dfr($d->validee_le) : '' }}<br>Date du dépôt : {{ $d->deposee_le ? dfr($d->deposee_le) : '' }}</td>
        </tr></table>
        <div class="mt-2">Code acte : <b>{{ $f['acte'] ?? '0' }}</b></div>
        <table class="g mt-2">
            <tr><th>N° CIN</th><th>N° de la carte de séjour pour les étrangers</th><th>Matricule fiscal</th><th>Code T.V.A.</th><th>Code catégorie</th></tr>
            <tr><td class="text-center">{{ ($f['id_code'] ?? '2') == '2' ? ($f['id_num'] ?? '') : '' }}</td><td class="text-center">{{ ($f['id_code'] ?? '') == '3' ? ($f['id_num'] ?? '') : '' }}</td>
                <td class="text-center font-mono">{{ $mf ? $mf['num'].$mf['cle'] : '' }}</td><td class="text-center">{{ $mf['tva'] ?? '' }}</td><td class="text-center">{{ $mf['cat'] ?? '' }}</td></tr>
        </table>
        <div class="mt-2">Date de début : <b>{{ ! empty($f['ouv']) ? dfr($f['ouv']) : '' }}</b> &nbsp;&nbsp; Date de clôture de l’exercice : <b>{{ ! empty($f['clo']) ? dfr($f['clo']) : '' }}</b></div>
        <div class="mt-2 font-bold">Impôt ou taxe acquitté sur la base de cette déclaration</div>
        <table class="g mt-1"><tr><th>Impôt sur le revenu</th><th>Taxe de visite</th><th>Prélèvements sur le fonds d’intéressement du personnel non réparti</th></tr>
            <tr>@foreach(['ir', 'visite', 'fonds'] as $k)<td class="coche">{{ $X(in_array($k, $imp, true)) }}</td>@endforeach</tr></table>
        <table class="g mt-2">
            <tr><th class="w-40 lib">Nom et prénom</th><td>{{ trim(($f['nom'] ?? $client->nom).' '.($f['prenom'] ?? '')) }}</td></tr>
            <tr><th class="lib">Adresse</th><td>{{ $client->adresse }}</td></tr>
            <tr><th class="lib">Code postal</th><td>{{ $cp[1] ?? '' }}</td></tr>
        </table>
        <table class="g mt-2">
            <tr><th>État civil</th><th>Nombre d’enfants handicapés à charge</th><th colspan="4">Autres enfants à charge (1er, 2ème, 3ème, 4ème)</th></tr>
            <tr><td class="text-center">{{ $A::SITUATIONS[$f['situation'] ?? ''][0] ?? '' }}</td><td class="text-center">{{ $f['enf_handicap'] ?? 0 }}</td>
                @foreach(['enf1', 'enf2', 'enf3', 'enf4'] as $k)<td class="text-center">{{ $f[$k] ?? '0' }}</td>@endforeach</tr>
        </table>

        <div class="mt-3 font-bold">Catégories de revenus</div>
        <table class="g mt-1 text-[10px]">
            <tr><th colspan="2">Bénéfices industriels et commerciaux</th><th colspan="3">Bénéfices des professions non commerciales</th><th colspan="4">Bénéfices des activités agricoles et de pêche</th><th colspan="3">Revenus fonciers</th>
                <th rowspan="2">Salaires et pensions de source tunisienne</th><th rowspan="2">Revenus des valeurs et des capitaux mobiliers</th><th rowspan="2">Autres revenus</th><th rowspan="2">Revenus exonérés</th><th rowspan="2">Revenus non imposables</th></tr>
            <tr><th>Régime réel</th><th>Part et plus-value relatives aux sociétés de personnes</th><th>Régime réel</th><th>Base forfaitaire</th><th>Part et plus-value</th>
                <th>Régime réel</th><th>Base forfaitaire</th><th>Monographies sectorielles</th><th>Part et plus-value</th><th>Régime réel</th><th>Base forfaitaire</th><th>Part et plus-value</th></tr>
            <tr>@foreach(['bic_r', 'bic_q', 'bnc_r', 'bnc_f', 'bnc_q', 'agr_r', 'agr_f', 'agr_m', 'agr_q', 'fon_r', 'fon_f', 'fon_q', 'sal', 'rcm', 'aut', 'exo', 'ni'] as $k)<td class="coche">{{ $X($cat[$k]) }}</td>@endforeach</tr>
        </table>
        <table class="g mt-3">
            <tr><th>Cadre légal</th><th>Date de la déclaration d’investissement</th><th>Date d’entrée en activité</th></tr>
            @for($i = 0; $i < 3; $i++) @php $l = $T('cadres')[$i] ?? []; @endphp
                <tr><td>{{ trim(($l['code'] ?? '').' '.($l['cadre'] ?? '')) ?: '-' }}</td><td class="text-center">{{ ! empty($l['date']) ? dfr($l['date']) : '' }}</td><td class="text-center">{{ ! empty($l['entree']) ? dfr($l['entree']) : '' }}</td></tr>
            @endfor
        </table>
        <div class="bloc space-y-1">
            <div>Le contribuable continue d’exploiter une entreprise transmise et exerçant dans le cadre de la législation relative aux avantages fiscaux :
                <b class="ml-2">{{ $X(empty($f['privilege'])) }} Non</b> <b class="ml-2">{{ $X(! empty($f['privilege'])) }} Oui</b></div>
            <div>Carte d’identité nationale ou matricule fiscal : <b class="font-mono">{{ $f['priv_id'] ?? '' }}</b></div>
        </div>
        <div class="bloc space-y-1">
            <div>Tenue des comptes et établissement des déclarations fiscales par un centre de gestion intégré :
                <b class="ml-2">{{ $X(! empty($f['cgi'])) }} Oui</b> <b class="ml-2">{{ $X(empty($f['cgi'])) }} Non</b></div>
            <div>Première année d’adhésion au premier centre : <b>{{ $f['cgi_annee'] ?? '' }}</b> &nbsp;&nbsp; Matricule fiscal : <b class="font-mono">{{ $f['cgi_mf'] ?? '' }}</b></div>
        </div>

        @if(in_array('bic', $sections, true))
            <table class="g mt-3 saut"><thead><tr><th class="lib">1- Bénéfices industriels et commerciaux</th><th></th><th>Montant</th></tr></thead><tbody>
                {!! $S('Régime réel') !!}
                @foreach(['bic_stk_deb', 'bic_stk_fin', 'bic_achats', 'bic_ca_loc', 'bic_ca_exp', 'bic_ca_ttc', 'bic_ca_srv', 'bic_ca_conso', 'bic_subv'] as $k){!! $L($A::IR['bic'][2][$k][0], $n($k), 1) !!}@endforeach
                {!! $L('Résultat comptable', $n('bic_res_compta'), 1, $bp($n('bic_res_compta'))) !!}{!! $L('Résultat fiscal', $n('bic_res_fiscal'), 1, $bp($n('bic_res_fiscal'))) !!}
                {!! $parts('bic') !!}{!! $L('Total', $C['bic'], 0, $bp($C['bic']), true) !!}
            </tbody></table>
        @endif
        @if(in_array('bnc', $sections, true))
            <table class="g mt-3"><thead><tr><th class="lib">2- Bénéfices des professions non commerciales</th><th></th><th>Montant</th></tr></thead><tbody>
                {!! $S('Régime réel') !!}
                {!! $L('Chiffre d’affaires local HT', $n('bnc_ca_loc'), 1) !!}{!! $L('Chiffre d’affaires à l’exportation', $n('bnc_ca_exp'), 1) !!}{!! $L('Chiffre d’affaires global TTC', $n('bnc_ca_ttc'), 1) !!}
                {!! $L('Montant de la prime', $n('bnc_subv'), 1) !!}{!! $L('Résultat comptable', $n('bnc_res_compta'), 1, $bp($n('bnc_res_compta'))) !!}{!! $L('Résultat fiscal', $n('bnc_res_fiscal'), 1, $bp($n('bnc_res_fiscal'))) !!}
                {!! $S('Détermination forfaitaire du bénéfice') !!}
                {!! $L('Recettes au titre des services locaux', $n('bnc_rec_loc'), 1) !!}{!! $L('Recettes au titre des services exportés', $n('bnc_rec_exp'), 1) !!}
                {!! $L('Recettes globales brutes (TTC)', $n('bnc_rec_ttc') ?: $n('bnc_rec_loc') + $n('bnc_rec_exp'), 1) !!}{!! $L('Bénéfice fiscal (80 % du total des recettes brutes)', $C['bnc_forf'], 1) !!}{!! $L('Montant de la prime', $n('bnc_forf_subv'), 1) !!}
                {!! $parts('bnc') !!}{!! $L('Total', $C['bnc'], 0, $bp($C['bnc']), true) !!}
            </tbody></table>
        @endif
        @if(in_array('agr', $sections, true))
            <table class="g mt-3"><thead><tr><th class="lib">3- Bénéfices des activités agricoles et de pêche</th><th></th><th>Montant</th></tr></thead><tbody>
                {!! $S('Régime réel') !!}
                {!! $L('Chiffre d’affaires local', $n('agr_ca_loc'), 1) !!}{!! $L('Chiffre d’affaires à l’exportation', $n('agr_ca_exp'), 1) !!}{!! $L('Chiffre d’affaires global', $n('agr_ca_tot'), 1) !!}
                {!! $L('Montant de la prime', $n('agr_subv'), 1) !!}{!! $L('Résultat comptable', $n('agr_res_compta'), 1, $bp($n('agr_res_compta'))) !!}{!! $L('Résultat fiscal', $n('agr_res_fiscal'), 1, $bp($n('agr_res_fiscal'))) !!}
                {!! $S('Détermination forfaitaire du bénéfice') !!}
                {!! $L('Recettes brutes', $n('agr_rec'), 1) !!}{!! $L('Stocks', $n('agr_stocks'), 1) !!}{!! $L('Total', $n('agr_rec') + $n('agr_stocks'), 1) !!}
                {!! $L('Déduction des dépenses d’exploitation justifiées', $n('agr_dep'), 1) !!}{!! $L('Prime', $n('agr_subv2'), 1) !!}{!! $L('Résultat', $C['agr_rd'], 1, $bp($C['agr_rd'])) !!}{!! $L('Bénéfice net', max(0, $C['agr_rd']), 1) !!}
                {!! $S('Détermination du bénéfice sur la base de monographies sectorielles') !!}{!! $L('Bénéfice fiscal', $n('agr_forf'), 1) !!}
                {!! $parts('agr') !!}{!! $L('Total', $C['agr'], 0, $bp($C['agr']), true) !!}
            </tbody></table>
        @endif
        @if(in_array('fon', $sections, true))
            <table class="g mt-3"><thead><tr><th class="lib">4- Revenus fonciers</th><th></th><th>Montant</th></tr></thead><tbody>
                {!! $S('Régime réel') !!}{!! $L('Chiffre d’affaires réalisé', $n('fon_ca'), 1) !!}{!! $L('Résultat comptable', $n('fon_res_compta'), 1, $bp($n('fon_res_compta'))) !!}{!! $L('Résultat fiscal', $n('fon_res_fiscal'), 1, $bp($n('fon_res_fiscal'))) !!}
                {!! $S('Détermination forfaitaire') !!}{!! $S('Revenus des immeubles bâtis', 1) !!}
                {!! $L('Recettes brutes réalisées (TTC)', $n('fon_bati'), 2) !!}{!! $L('Déduction de 25 % au titre des frais, charges et amortissements', $C['fon_bati_abat'], 2) !!}
                {!! $L('Taxe sur les immeubles bâtis et contribution au FNAH effectivement payées', $n('fon_bati_tax'), 2) !!}{!! $L('Résultat', $C['fon_bati_res'], 2, $bp($C['fon_bati_res'])) !!}
                {!! $S('Revenus des terrains non bâtis', 1) !!}
                {!! $L('Recettes brutes réalisées (TTC)', $n('fon_nb'), 2) !!}{!! $L('Dépenses justifiées', $n('fon_nb_dep'), 2) !!}{!! $L('Taxe sur les terrains non bâtis effectivement payée', $n('fon_nb_tax'), 2) !!}
                {!! $L('Résultat net', $C['fon_nb_res'], 2, $bp($C['fon_nb_res'])) !!}
                {!! $parts('fon') !!}{!! $L('Total', $C['fon'], 0, $bp($C['fon']), true) !!}
            </tbody></table>
        @endif
        @if(in_array('sal', $sections, true))
            <table class="g mt-3"><thead><tr><th class="lib">5- Traitements, salaires, pensions et rentes viagères</th><th></th><th>Montant</th></tr></thead><tbody>
                {!! $S('Traitements et salaires') !!}{!! $L('Montant brut des traitements et salaires', $n('sal_brut'), 1) !!}{!! $L('Avantages en nature', $n('sal_nat'), 1) !!}{!! $L('Total', $C['sal_tot'], 1) !!}
                {!! $L('Déduction de 10 % au titre des frais professionnels (2 000 D au plus)', $C['sal_frais'], 1) !!}{!! $L('Revenu brut (a)', $C['sal_a'], 1, null, true) !!}
                {!! $S('Pensions et rentes viagères') !!}{!! $L('Montant brut des pensions et rentes viagères', $n('pen_brut'), 1) !!}{!! $L('Avantages en nature', $n('pen_nat'), 1) !!}{!! $L('Total', $C['pen_tot'], 1) !!}
                {!! $L('Déduction de 25 %', $C['pen_abat'], 1) !!}{!! $L('Revenu net (b)', $C['pen_b'], 1, null, true) !!}
                {!! $L('Total (a + b)', $C['sal'], 0, null, true) !!}
            </tbody></table>
        @endif
        @if(in_array('rcm', $sections, true))
            <table class="g mt-3"><thead><tr><th class="lib">6- Revenus des valeurs mobilières et des capitaux mobiliers</th><th></th><th>Montant</th></tr></thead><tbody>
                @foreach($A::IR['rcm'][2] as $k => [$lib, , $nat])@if(in_array($nat, ['m', 'r'], true)){!! $L($lib, $n($k), 1) !!}@endif @endforeach
                {!! $L('Revenus des valeurs mobilières (a)', $C['rcm_a'], 0) !!}{!! $L('Revenus des capitaux mobiliers (b)', $C['rcm_b'], 0) !!}{!! $L('Total (a + b)', $C['rcm'], 0, null, true) !!}
            </tbody></table>
        @endif
        @if(in_array('aut', $sections, true))
            <table class="g mt-3"><thead><tr><th class="lib">7- Autres revenus</th><th></th><th>Montant</th></tr></thead><tbody>
                @foreach($A::IR['aut'][2] as $k => [$lib, , $nat])@if(in_array($nat, ['m', 'r'], true)){!! $L($lib, $n($k), 1) !!}@endif @endforeach
                {!! $L('Total', $C['aut'], 0, null, true) !!}
            </tbody></table>
        @endif

        {{-- ======================= 2e partie ======================= --}}
        <div class="saut mt-6 text-right text-xs text-slate-500">Édition (2ème partie)</div>
        <div class="titre">Déficits des années antérieures non imputés</div>
        <div class="mt-1">Oui <b>{{ $X($C['pertes'] > 0) }}</b> &nbsp;&nbsp; Non <b>{{ $X($C['pertes'] <= 0) }}</b></div>
        <table class="g mt-1"><tr><th>Année de perte</th><th>Montant</th></tr>
            @for($i = 0; $i < 5; $i++) @php $l = $T('pertes')[$i] ?? []; @endphp<tr><td class="text-center">{{ $l['annee'] ?? '-' }}</td><td class="mt">{{ isset($l['montant']) ? $m($l['montant']) : '' }}</td></tr>@endfor
            <tr><td class="gras">Total des déficits déductibles</td><td class="mt">{{ $m($C['pertes_imput']) }}</td></tr>
            <tr><td class="gras">Reliquat des déficits non imputés</td><td class="mt">{{ $m($C['pertes_reste']) }}</td></tr></table>
        <table class="g mt-2"><tbody>{!! $L('Revenu net global', $C['rng_net'], 0, $bp($C['rng_net']), true) !!}</tbody></table>

        <div class="titre">I - Déductions communes</div>
        <div class="mt-1 font-bold">a - Déductions au titre de l’activité donnant droit à déduction</div>
        <table class="g mt-1"><tr><th>Cadre légal</th><th>Montants déduits</th></tr>
            @for($i = 0; $i < 5; $i++) @php $l = $T('ded_act')[$i] ?? []; @endphp<tr><td>{{ trim(($l['code'] ?? '').' '.($l['cadre'] ?? '')) ?: '-' }}</td><td class="mt">{{ $m($l['montant'] ?? 0) }}</td></tr>@endfor
            <tr><td class="gras">Total</td><td class="mt">{{ $m($C['ded_act']) }}</td></tr></table>
        <div class="mt-2 font-bold">b - Autres déductions</div>
        <table class="g mt-1"><tr><th>Déduction</th><th>Montant</th></tr>
            @foreach(['ded_ep_bq', 'ded_ep_cent', 'ded_oblig', 'ded_bons', 'ded_verts', 'ded_rentes', 'ded_assur', 'ded_fne'] as $k)<tr><td>{{ $ded[$k][0] }}</td><td class="mt">{{ $m($n($k)) }}</td></tr>@endforeach
            <tr><td>Chef de famille</td><td class="mt">{{ $m($C['ded_chef']) }}</td></tr>
            <tr><td>Enfants à charge</td><td class="mt">{{ $m($C['ded_enf']) }}</td></tr>
            <tr><td>Parents à charge ({{ (int) ($f['parents'] ?? 0) }})</td><td class="mt">{{ $m($C['ded_parents']) }}</td></tr>
            @foreach(['ded_coti', 'ded_univ', 'ded_hab_sociale', 'ded_primes', 'ded_logement'] as $k)<tr><td>{{ $ded[$k][0] }}</td><td class="mt">{{ $m($n($k)) }}</td></tr>@endforeach
            <tr><td class="gras">Total</td><td class="mt">{{ $m($C['ded_communes']) }}</td></tr></table>

        <div class="titre">II - Déductions des revenus réinvestis</div>
        <table class="g mt-1"><tr><th>Cadre légal</th><th>Montants investis</th><th>Montants déduits</th></tr>
            @for($i = 0; $i < 5; $i++) @php $l = $T('reinv')[$i] ?? []; @endphp<tr><td>{{ trim(($l['code'] ?? '').' '.($l['cadre'] ?? '')) ?: '-' }}</td><td class="mt">{{ $m($l['investi'] ?? 0) }}</td><td class="mt">{{ $m($l['deduit'] ?? 0) }}</td></tr>@endfor
            <tr><td class="gras" colspan="2">Total</td><td class="mt">{{ $m($C['reinv']) }}</td></tr></table>

        <div class="titre">Détermination du revenu global imposable</div>
        <table class="g mt-1"><tbody>
            {!! $L('Revenu net soumis à l’impôt', $C['rni']) !!}{!! $L('Revenu global net imposable (arrondi au dinar supérieur)', $C['rni_arrondi'], 0, null, true) !!}{!! $L('Impôt dû selon le barème', $C['impot_bareme'], 0, null, true) !!}
        </tbody></table>

        <div class="titre">Détermination du minimum d’impôt</div>
        <table class="g mt-1 text-[10px]">
            <tr><th>Chiffre d’affaires local TTC (DT)</th><th>Chiffre d’affaires local TTC soumis au minimum d’impôt</th><th>Chiffre d’affaires local soumis au minimum d’impôt et réalisé de la commercialisation de produits ou de services soumis au régime de l’homologation administrative des prix et dont la marge bénéficiaire brute ne dépasse pas 6 %</th>
                <th>Chiffre d’affaires total à l’exportation soumis au minimum d’impôt</th><th>Minimum d’impôt liquidé sur la base du chiffre d’affaires ou minimum d’impôt de 200 ou 300 (DT)</th><th>Part de l’associé dans le minimum d’impôt des sociétés de personnes et assimilées</th><th>Minimum d’impôt au taux de 30 % ou 60 %</th></tr>
            <tr><td class="mt">{{ $m($n('bic_ca_ttc') + $n('bnc_ca_ttc') ?: $n('min_loc_02') + $n('min_loc_01')) }}</td><td class="mt">{{ $m($n('min_loc_02')) }}</td><td class="mt">{{ $m($n('min_loc_01')) }}</td>
                <td class="mt">{{ $m($n('min_exp_02') + $n('min_exp_01')) }}</td><td class="mt">{{ $m($C['min_ca']) }}</td><td class="mt">{{ $m($n('min_qp')) }}</td><td class="mt">{{ $m($n('min_30')) }}</td></tr>
        </table>
        <table class="g mt-2"><tbody>
            {!! $L('Impôt sur le revenu dû ou minimum d’impôt', $C['impot'], 0, null, true) !!}
            {!! $L('Imputation des excédents, avances et acomptes', $C['II'], 0, null, true) !!}
            @php $imputations = ['ded_exc' => 'Excédent d’impôt dégagé sur la déclaration de l’année précédente', 'ded_acp' => 'Acomptes provisionnels effectivement acquittés', 'ded_rs' => 'Retenues à la source',
                'ded_imp' => 'Avance sur les produits importés', 'ded_aut' => 'Autres montants déductibles', 'ded_qp' => 'Acompte de l’avance exigible sur les sociétés de personnes et assimilées',
                'ded_bnc_forf' => $liq['ded_bnc_forf'][0], 'ded_div' => 'Retenue à la source au titre des parts des actions qui ne dépasse pas 10 000 dinars annuellement',
                'ded_recrut' => $liq['ded_recrut'][0], 'ded_alc' => $liq['ded_alc'][0], 'ded_1pc' => $liq['ded_1pc'][0], 'ded_reduc' => $liq['ded_reduc'][0]]; @endphp
            @foreach($imputations as $k => $lib)
                @if(! in_array($k, ['ded_alc', 'ded_1pc', 'ded_reduc'], true) || $n($k)){!! $L($lib, $k === 'ded_acp' ? $C['acomptes'] : $n($k), 1) !!}@endif
            @endforeach
            {!! $L('Montants restitués', $n('restit')) !!}
            {!! $L('Reste à payer ou trop perçu', abs($C['solde']), 0, $pr($C['solde']), true) !!}
        </tbody></table>

        <div class="titre">Contribution sociale de solidarité</div>
        <table class="g mt-1"><tbody>
            {!! $L('Revenu global net imposable', $C['css_base']) !!}{!! $L('Contribution due ('.str_replace('.', ',', (string) +$C['css_taux']).' %)', $C['css_du']) !!}
            {!! $L('Excédent de l’année antérieure au titre de la contribution', 0) !!}{!! $L('Retenue à la source au titre de la contribution', $n('css_rs')) !!}
            {!! $L('Reste à payer ou trop perçu', abs($C['css_net']), 0, $pr($C['css_net']), true) !!}
        </tbody></table>

        <div class="mt-3 grid grid-cols-3 gap-4">
            <div><div class="font-bold">Taxe de visite</div><table class="g mt-1"><tr><th>Montant</th></tr><tr><td class="mt">{{ $m($n('visite')) }}</td></tr></table></div>
            <div class="col-span-2"><div class="font-bold">Prélèvement sur le fonds d’intéressement du personnel non réparti</div>
                <table class="g mt-1"><tr><th>Assiette</th><th>Taux</th><th>Montant</th></tr><tr><td class="mt">{{ $m($n('fonds_base')) }}</td><td class="text-center">7 %</td><td class="mt">{{ $m($C['fonds']) }}</td></tr></table></div>
        </div>
        @php $syn = ['ir' => 'Impôt sur le revenu', 'css' => 'Contribution sociale de solidarité', 'exo' => 'Pénalités sur revenus exonérés non déclarés', 'fonds' => 'Prélèvement sur le fonds d’intéressement', 'visite' => 'Taxe de visite']; @endphp
        <table class="g mt-3 text-xs"><tr><th>Impôts et taxes</th><th>Impôt dû : P / Report : R (I)</th><th>Déduction (II)</th><th>Impôt dû : P / Report : R (III = I − II)</th><th>Pénalités de retard</th><th>Total</th></tr>
            @foreach($syn as $k => $l) @php $x = $C['synthese'][$k]; @endphp
                @if(in_array($k, ['ir', 'css'], true) || $x['tot'] || $x['I'])
                    <tr><td class="lib">{{ $l }}</td><td class="mt">{{ $pr($x['I']) }} {{ $m(abs($x['I'])) }}</td><td class="mt">{{ $m($x['II']) }}</td><td class="mt">{{ $pr($x['III']) }} {{ $m(abs($x['III'])) }}</td><td class="mt">{{ $m($x['pen']) }}</td><td class="mt">{{ $m($x['tot']) }}</td></tr>
                @endif
            @endforeach
            <tr class="gras"><td class="lib">Total</td><td></td><td></td><td></td><td class="mt">{{ $m(array_sum(array_column($C['synthese'], 'pen'))) }}</td><td class="mt">{{ $m($C['tot']) }}</td></tr>
        </table>

        <table class="g mt-4 text-xs"><tr>
            <td class="w-1/2 align-top"><div class="font-bold">Réservé à l’administration</div>Recette des finances :<br>Code :<br>Code paiement :<br>Numéro de la quittance :<br>Date de la quittance :<br><br>Cachet et signature</td>
            <td class="align-top">Je soussigné, certifie exactes et sincères les mentions portées à la présente déclaration et m’expose aux sanctions prévues par la loi pour toute infraction.<br><br>
                à .............., le ..../..../..........<br><br>Cachet et signature</td>
        </tr></table>
    </div>
</x-layouts.app>
