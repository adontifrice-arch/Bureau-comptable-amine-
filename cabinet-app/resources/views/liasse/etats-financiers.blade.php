{{-- États financiers complets (modèle du cabinet) : garde, sommaire, bilan, état de résultat, flux de trésorerie, notes --}}
@php
    use App\Services\EtatsFinanciers as EF;
    $m = fn ($v) => EF::montant($v);
    $N = $ef['N']; $P = $ef['N1']; $F = $ef['F']; $F1 = $ef['F1']; $notes = $ef['notes'];
    $an = $ef['annee']; $date = '31/12/'.$an; $date1 = '31/12/'.($an - 1);
    $arrete = 'ÉTATS FINANCIERS ARRÊTÉS AU '.$date;
    $mf = trim((string) $client->matricule_fiscal);
    $note = fn ($c) => isset($notes[$c]) ? $c : '';
    /** Ligne d'un état : libellé, note, N, N-1, style (titre, sous, total, grand, retrait, net). */
    $l = function ($lib, $code, $n, $n1, $style = '') use ($m) {
        if ($style === 'titre' || $style === 'sous') return '<tr class="'.$style.'"><td colspan="4">'.e($lib).'</td></tr>';
        return '<tr class="'.e($style).'"><td>'.e($lib).'</td><td class="note">'.e($code).'</td><td class="num">'.$m($n).'</td><td class="num">'.$m($n1).'</td></tr>';
    };
    /** Ligne de l'état des flux : clé du calcul, N et N-1. */
    $f = fn ($lib, $k, $style = '') => $l($lib, '', $F[$k], $F1[$k], $style);
    $tete = '<tr><th></th><th class="note">Note</th><th class="num">'.$date.'</th><th class="num">'.$date1.'</th></tr>';
    $taux = collect(\App\Services\Amortissement::NATURES)->filter(fn ($n) => $n[3] > 0)->map(fn ($n) => [$n[0], rtrim(rtrim(number_format($n[3], 2, ',', ''), '0'), ',')]);
    $entete = '<div class="ecran-tete"><span>'.e($arrete).'</span></div>';
    $pied = '<div class="ecran-pied"><span>'.e($client->nom).'</span><span>'.e($mf).'</span></div>';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>États financiers {{ $an }} – {{ $client->nom }}</title>
    <style>
        @page { size: A4 portrait; margin: 22mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.5pt; color: #111; margin: 0; background: #fff; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .ecran-tete, .ecran-pied { display: {{ $impression ? 'flex' : 'none' }}; justify-content: space-between; font-size: 7.5pt; color: #555; }
        .ecran-tete { border-bottom: 1px solid #999; margin-bottom: 8pt; padding-bottom: 3pt; }
        .ecran-pied { border-top: 1px solid #999; margin-top: 12pt; padding-top: 3pt; }
        .garde { text-align: center; padding-top: 150pt; }
        .garde h1 { font-size: 22pt; margin: 0 0 14pt; }
        .garde .soc { font-size: 16pt; font-weight: bold; margin-top: 40pt; }
        .garde .mf { font-size: 11pt; color: #444; margin-top: 6pt; }
        .intercalaire { text-align: center; padding-top: 250pt; font-size: 18pt; font-weight: bold; }
        h2 { font-size: 13pt; text-align: center; margin: 0 0 2pt; }
        .soustitre { text-align: center; font-size: 9pt; color: #444; margin-bottom: 10pt; }
        .societe { text-align: center; font-weight: bold; font-size: 10pt; margin-bottom: 2pt; }
        table.etat { width: 100%; border-collapse: collapse; }
        .etat th { background: #e2e8f0; border-bottom: 1px solid #333; padding: 4pt; text-align: left; font-size: 8pt; }
        .etat td { padding: 2.6pt 4pt; border-bottom: 1px solid #e5e7eb; }
        .etat .num { text-align: right; white-space: nowrap; width: 22%; }
        .etat .note { text-align: center; width: 8%; color: #333; }
        .etat tr.titre td { background: #f1f5f9; font-weight: bold; border-bottom: 1px solid #94a3b8; padding-top: 5pt; }
        .etat tr.sous td { font-weight: bold; font-style: italic; padding-left: 10pt; }
        .etat tr.retrait td:first-child { padding-left: 22pt; color: #444; }
        .etat tr.net td { font-style: italic; }
        .etat tr.net td:first-child { padding-left: 22pt; }
        .etat tr.total td { font-weight: bold; border-top: 1px solid #555; }
        .etat tr.grand td { font-weight: bold; border-top: 2px double #111; background: #f8fafc; }
        .sommaire { margin: 40pt auto 0; width: 70%; font-size: 11pt; line-height: 2.2; }
        .texte p { text-align: justify; line-height: 1.5; margin: 0 0 6pt; }
        .texte h3 { font-size: 10pt; margin: 12pt 0 5pt; }
        .texte h4 { font-size: 9pt; margin: 9pt 0 4pt 14pt; }
        .presentation td { padding: 3pt 6pt; }
        .presentation td:first-child { color: #444; width: 35%; }
        .detail { margin-bottom: 12pt; page-break-inside: avoid; }
        .detail h4 { margin: 10pt 0 2pt; font-size: 9.5pt; }
        .detail .intro { font-size: 8pt; color: #444; margin-bottom: 3pt; }
        .actions { margin: 10px; font-family: Arial, sans-serif; }
        @media print { .actions, .ecran-tete, .ecran-pied { display: none; } }
    </style>
</head>
<body>
@if($impression)
    <div class="actions"><button onclick="window.print()">Imprimer</button> <a href="{{ route('liasse.etats-financiers', ['annee' => $an, 'format' => 'pdf']) }}">Télécharger le PDF</a></div>
@endif

{{-- Page de garde --}}
<div class="page">{!! $entete !!}
    <div class="garde">
        <h1>ÉTATS FINANCIERS<br>ARRÊTÉS AU {{ $date }}</h1>
        <div class="soc">{{ $client->nom }}</div>
        @if($mf)<div class="mf">{{ $mf }}</div>@endif
    </div>{!! $pied !!}
</div>

{{-- Sommaire --}}
<div class="page">{!! $entete !!}
    <h2>Sommaire</h2>
    <div class="sommaire">
        <div>BILAN ARRÊTÉ AU {{ $date }}</div>
        <div>ÉTAT DE RÉSULTAT ARRÊTÉ AU {{ $date }}</div>
        <div>ÉTAT DES FLUX DE TRÉSORERIE ARRÊTÉ AU {{ $date }}</div>
        <div>NOTES AUX ÉTATS FINANCIERS</div>
    </div>{!! $pied !!}
</div>

<div class="page">{!! $entete !!}<div class="intercalaire">ÉTATS FINANCIERS ARRÊTÉS<br>AU {{ $date }}</div>{!! $pied !!}</div>

{{-- Bilan : actifs --}}
<div class="page">{!! $entete !!}
    <div class="societe">{{ $client->nom }}</div><h2>BILAN</h2><div class="soustitre">(Exprimés en dinars)</div>
    <table class="etat"><thead><tr><th>Actifs</th><th class="note">Note</th><th class="num">{{ $date }}</th><th class="num">{{ $date1 }}</th></tr></thead><tbody>
        {!! $l('Actifs non courants', '', 0, 0, 'titre') !!}
        {!! $l('Actifs immobilisés', '', 0, 0, 'sous') !!}
        @foreach(['A1' => 'amortissements', 'A2' => 'amortissements', 'A3' => 'provisions'] as $k => $moins)
            {!! $l(EF::RUBRIQUES[$k], $note($k), $N['A'][$k]['brut'], $P['A'][$k]['brut']) !!}
            {!! $l('moins : '.$moins, '', -$N['A'][$k]['amort'], -$P['A'][$k]['amort'], 'retrait') !!}
            {!! $l('Valeur nette', '', $N['A'][$k]['net'], $P['A'][$k]['net'], 'net') !!}
        @endforeach
        {!! $l('Total des actifs immobilisés', '', $N['A']['immo'], $P['A']['immo'], 'total') !!}
        {!! $l(EF::RUBRIQUES['A4'], $note('A4'), $N['A']['A4']['net'], $P['A']['A4']['net']) !!}
        {!! $l('Total des actifs non courants', '', $N['A']['anc'], $P['A']['anc'], 'total') !!}
        {!! $l('Actifs courants', '', 0, 0, 'titre') !!}
        @foreach(['A5', 'A6', 'A7', 'A8'] as $k)
            {!! $l(EF::RUBRIQUES[$k], $note($k), $N['A'][$k]['brut'], $P['A'][$k]['brut']) !!}
            @if($N['A'][$k]['amort'] || $P['A'][$k]['amort'] || in_array($k, ['A5', 'A6'], true))
                {!! $l('moins : provisions', '', -$N['A'][$k]['amort'], -$P['A'][$k]['amort'], 'retrait') !!}
                {!! $l('Valeur nette', '', $N['A'][$k]['net'], $P['A'][$k]['net'], 'net') !!}
            @endif
        @endforeach
        {!! $l(EF::RUBRIQUES['A9'], $note('A9'), $N['A']['A9']['net'], $P['A']['A9']['net']) !!}
        {!! $l('Total des actifs courants', '', $N['A']['ac'], $P['A']['ac'], 'total') !!}
        {!! $l('Total des actifs', '', $N['A']['total'], $P['A']['total'], 'grand') !!}
    </tbody></table>{!! $pied !!}
</div>

{{-- Bilan : capitaux propres et passifs --}}
<div class="page">{!! $entete !!}
    <div class="societe">{{ $client->nom }}</div><h2>BILAN</h2><div class="soustitre">(Exprimés en dinars)</div>
    <table class="etat"><thead><tr><th>Capitaux propres &amp; passifs</th><th class="note">Note</th><th class="num">{{ $date }}</th><th class="num">{{ $date1 }}</th></tr></thead><tbody>
        {!! $l('Capitaux propres', '', 0, 0, 'titre') !!}
        {!! $l('Capital social', $note('CP1'), $N['P']['capital'], $P['P']['capital']) !!}
        {!! $l('Réserves', $note('CP1'), $N['P']['reserves'], $P['P']['reserves']) !!}
        {!! $l('Autres capitaux propres', $note('CP1'), $N['P']['acp'], $P['P']['acp']) !!}
        {!! $l('Résultats reportés', $note('CP1'), $N['P']['report'], $P['P']['report']) !!}
        {!! $l('Total des capitaux propres avant résultat de l’exercice', '', $N['P']['cpar'], $P['P']['cpar'], 'total') !!}
        {!! $l('Résultat de l’exercice', '', $N['P']['resultat'], $P['P']['resultat']) !!}
        {!! $l('Total des capitaux propres avant affectation', '', $N['P']['cp'], $P['P']['cp'], 'total') !!}
        {!! $l('Passifs', '', 0, 0, 'titre') !!}
        {!! $l('Passifs non courants', '', 0, 0, 'sous') !!}
        {!! $l('Emprunts', $note('CP2'), $N['P']['emprunts'], $P['P']['emprunts']) !!}
        {!! $l('Autres passifs financiers', $note('CP3'), $N['P']['apf'], $P['P']['apf']) !!}
        {!! $l('Provisions', $note('CP3'), $N['P']['provisions'], $P['P']['provisions']) !!}
        {!! $l('Total des passifs non courants', '', $N['P']['pnc'], $P['P']['pnc'], 'total') !!}
        {!! $l('Passifs courants', '', 0, 0, 'sous') !!}
        {!! $l('Fournisseurs & comptes rattachés', $note('CP4'), $N['P']['CP4'], $P['P']['CP4']) !!}
        {!! $l('Autres passifs courants', $note('CP5'), $N['P']['CP5'], $P['P']['CP5']) !!}
        {!! $l('Concours bancaires & autres passifs financiers', $note('CP6'), $N['P']['CP6'], $P['P']['CP6']) !!}
        {!! $l('Total des passifs courants', '', $N['P']['pc'], $P['P']['pc'], 'total') !!}
        {!! $l('Total des passifs', '', $N['P']['passifs'], $P['P']['passifs'], 'total') !!}
        {!! $l('Total des capitaux propres & passifs', '', $N['P']['total'], $P['P']['total'], 'grand') !!}
    </tbody></table>{!! $pied !!}
</div>

{{-- État de résultat (modèle autorisé) --}}
<div class="page">{!! $entete !!}
    <div class="societe">{{ $client->nom }}</div><h2>ÉTAT DE RÉSULTAT</h2><div class="soustitre">(Exprimés en dinars)</div>
    @php $R = $N['R']; $R1 = $P['R']; @endphp
    <table class="etat"><thead>{!! $tete !!}</thead><tbody>
        {!! $l('Produits d’exploitation', '', 0, 0, 'titre') !!}
        {!! $l('Revenus', $note('R1'), $R['R1'], $R1['R1']) !!}
        {!! $l('Autres produits d’exploitation', $note('R2'), $R['R2'], $R1['R2']) !!}
        {!! $l('Production immobilisée', $note('R3'), $R['R3'], $R1['R3']) !!}
        {!! $l('Total des produits d’exploitation', '', $R['tpe'], $R1['tpe'], 'total') !!}
        {!! $l('Charges d’exploitation', '', 0, 0, 'titre') !!}
        {!! $l('Variation des stocks de produits finis et encours', '', $R['vspf'], $R1['vspf']) !!}
        {!! $l('Achats de marchandises consommés', $note('R4'), $R['R4'], $R1['R4']) !!}
        {!! $l('Achats consommés d’approvisionnements', $note('R5'), $R['R5'], $R1['R5']) !!}
        {!! $l('Charges de personnel', $note('R6'), $R['R6'], $R1['R6']) !!}
        {!! $l('Dotations aux amortissements et aux provisions', $note('R7'), $R['R7'], $R1['R7']) !!}
        {!! $l('Autres charges d’exploitation', $note('R8'), $R['R8'], $R1['R8']) !!}
        {!! $l('Total des charges d’exploitation', '', $R['tce'], $R1['tce'], 'total') !!}
        {!! $l('Résultat d’exploitation', '', $R['rex'], $R1['rex'], 'total') !!}
        {!! $l('Charges financières nettes', '', $R['cfn'], $R1['cfn']) !!}
        {!! $l('Produits des placements', '', $R['pp'], $R1['pp']) !!}
        {!! $l('Autres gains ordinaires', '', $R['ago'], $R1['ago']) !!}
        {!! $l('Autres pertes ordinaires', '', $R['apo'], $R1['apo']) !!}
        {!! $l('Résultat des activités ordinaires avant impôt', '', $R['rav'], $R1['rav'], 'total') !!}
        {!! $l('Impôt sur les bénéfices', '', $R['is'], $R1['is']) !!}
        {!! $l('Autres impôts sur les bénéfices : CSS, …', '', $R['ais'], $R1['ais']) !!}
        {!! $l('Résultat des activités ordinaires après impôt', '', $R['rap'], $R1['rap'], 'total') !!}
        {!! $l('Éléments extraordinaires (gains / pertes)', '', $R['ext'], $R1['ext']) !!}
        {!! $l('Résultat net de l’exercice', '', $R['rn'], $R1['rn'], 'total') !!}
        {!! $l('Effet des modifications comptables (net d’impôt)', '', $R['modif'], $R1['modif']) !!}
        {!! $l('Résultat après modifications comptables', '', $R['ram'], $R1['ram'], 'grand') !!}
    </tbody></table>{!! $pied !!}
</div>

{{-- État des flux de trésorerie (modèle autorisé) --}}
<div class="page">{!! $entete !!}
    <div class="societe">{{ $client->nom }}</div><h2>ÉTAT DES FLUX DE TRÉSORERIE</h2><div class="soustitre">(Exprimés en dinars)</div>
    <table class="etat"><thead><tr><th></th><th class="note"></th><th class="num">{{ $date }}</th><th class="num">{{ $date1 }}</th></tr></thead><tbody>
        {!! $l('Flux de trésorerie liés à l’exploitation', '', 0, 0, 'titre') !!}
        {!! $f('Résultat net', 'rn') !!}
        {!! $l('Ajustements pour :', '', 0, 0, 'sous') !!}
        {!! $f('Amortissements & provisions', 'amort', 'retrait') !!}
        {!! $f('Variations des stocks', 'stocks', 'retrait') !!}
        {!! $f('Variations des créances', 'creances', 'retrait') !!}
        {!! $f('Variations des autres actifs', 'autres_actifs', 'retrait') !!}
        {!! $f('Variations des fournisseurs & autres dettes', 'dettes', 'retrait') !!}
        {!! $f('Plus ou moins-values de cession', 'pmv', 'retrait') !!}
        {!! $f('Transferts de charges', 'transferts', 'retrait') !!}
        {!! $f('Autres flux', 'autres_exp', 'retrait') !!}
        {!! $f('Flux de trésorerie liés à l’exploitation', 'exp', 'total') !!}
        {!! $l('Flux de trésorerie liés aux activités d’investissement', '', 0, 0, 'titre') !!}
        {!! $f('Acquisitions d’immobilisations corp. & incorp.', 'acq') !!}
        {!! $f('Cession & reclassement d’immobilisations', 'ces') !!}
        {!! $f('Acquisitions d’immobilisations financières', 'acq_fin') !!}
        {!! $f('Cession d’immobilisations financières', 'ces_fin') !!}
        {!! $f('Autres flux', 'autres_inv') !!}
        {!! $f('Flux de trésorerie liés à l’investissement', 'inv', 'total') !!}
        {!! $l('Flux de trésorerie liés aux activités de financement', '', 0, 0, 'titre') !!}
        {!! $f('Encaissements suite à l’émission d’actions', 'emission') !!}
        {!! $f('Dividendes & autres distributions', 'dividendes') !!}
        {!! $f('Encaissements provenant d’emprunts', 'emprunts') !!}
        {!! $f('Remboursements d’emprunts', 'remboursements') !!}
        {!! $f('Autres flux', 'autres_fin') !!}
        {!! $f('Flux de trésorerie liés au financement', 'fin', 'total') !!}
        {!! $f('Incidences des taux de change / liquidités & équivalents', 'change') !!}
        {!! $f('Autres postes des flux de trésorerie', 'autres_postes') !!}
        {!! $f('Variation de trésorerie', 'variation', 'total') !!}
        {!! $f('Trésorerie au début de l’exercice', 'tresorerie_debut') !!}
        {!! $f('Trésorerie à la clôture de l’exercice', 'tresorerie_fin', 'grand') !!}
    </tbody></table>{!! $pied !!}
</div>

<div class="page">{!! $entete !!}<div class="intercalaire">NOTES AUX ÉTATS FINANCIERS<br>ARRÊTÉS AU {{ $date }}</div>{!! $pied !!}</div>

{{-- Notes : présentation, référentiel, principes comptables --}}
<div class="page texte">{!! $entete !!}
    <h2>NOTES AUX ÉTATS FINANCIERS ARRÊTÉS AU {{ $date }}</h2>
    <h3>1. Présentation générale de la société</h3>
    <table class="presentation">
        <tr><td>Raison sociale :</td><td><b>{{ $client->nom }}</b></td></tr>
        @if($mf)<tr><td>Identifiant unique :</td><td>{{ $mf }}</td></tr>@endif
        @if($client->forme)<tr><td>Forme juridique :</td><td>{{ $client->forme }}</td></tr>@endif
        @if((float) $client->capital > 0)<tr><td>Capital :</td><td>{{ number_format((float) $client->capital, 3, ',', ' ') }} dinars tunisiens</td></tr>@endif
        @if($infos['activite'])<tr><td>Activité :</td><td>{{ $infos['activite'] }}</td></tr>@endif
        @if($infos['creation'])<tr><td>Créée le :</td><td>{{ \Carbon\Carbon::parse($infos['creation'])->format('d/m/Y') }}</td></tr>@endif
        @if($infos['entree'])<tr><td>Entrée en activité :</td><td>{{ \Carbon\Carbon::parse($infos['entree'])->format('d/m/Y') }}</td></tr>@endif
        @if($infos['effectif'] !== null && $infos['effectif'] !== '')<tr><td>Effectif moyen :</td><td>{{ $infos['effectif'] }}</td></tr>@endif
        <tr><td>Monnaie de présentation :</td><td>Dinars tunisiens</td></tr>
    </table>
    @if($client->type_personne !== 'physique')
        <p>La société {{ $client->nom }} est une société {{ $client->residence === 'non_residente' ? 'non résidente' : 'résidente' }}{{ $client->forme ? ', ayant la forme '.$client->forme : '' }}, régie par la loi n° 2000-93 du 3 novembre 2000 portant promulgation du code des sociétés commerciales, par toutes autres dispositions légales et réglementaires en vigueur et par ses statuts.</p>
    @endif
    <h3>2. Référentiel d’élaboration des états financiers</h3>
    <p>Les états financiers relatifs à l’exercice clos le 31 décembre {{ $an }} sont établis conformément aux normes comptables tunisiennes telles que définies par la loi n° 96-112 du 30 décembre 1996 relative au système comptable des entreprises et le décret n° 96-2459 du 30 décembre 1996 portant approbation du cadre conceptuel de la comptabilité.</p>
    <p>L’état de résultat et l’état des flux de trésorerie sont présentés selon le modèle autorisé.</p>
    <h3>3. Bases de mesure et principes comptables pertinents appliqués</h3>
    <p>Les états financiers ont été préparés compte tenu des hypothèses de la continuité de l’exploitation et de la comptabilité d’engagement et par référence aux conventions comptables de base, notamment : la convention du coût historique, la convention de la périodicité, la convention de rattachement des charges aux produits, la convention de prudence et la convention de permanence des méthodes.</p>
    <p>Les états financiers de l’exercice {{ $an }} couvrent la période allant du 1er janvier au 31 décembre {{ $an }}. Aucune dérogation n’a été apportée aux méthodes comptables par rapport à l’exercice précédent. Les principales méthodes comptables adoptées pour l’établissement des états financiers se présentent comme suit.</p>
    <h4>3.1 Immobilisations incorporelles</h4>
    <p>Cette rubrique comprend notamment les logiciels informatiques acquis pour l’usage interne. Ces immobilisations sont comptabilisées à leur coût historique d’acquisition hors taxes récupérables et amorties linéairement sur trois exercices.</p>
    <h4>3.2 Immobilisations corporelles</h4>
    <p>Les immobilisations corporelles sont comptabilisées à leur coût d’acquisition, constitué du prix d’achat facturé majoré de tous autres éléments de coût supportés à l’occasion de l’acquisition ou engagés pour la mise en service. Le montant amortissable est déterminé sous déduction de la valeur résiduelle du bien, si elle est significative. Les dotations aux amortissements sont calculées selon le mode linéaire aux taux suivants :</p>
    <table class="presentation">@foreach($taux as [$lib, $t])<tr><td>{{ $lib }}</td><td>{{ $t }} %</td></tr>@endforeach</table>
    <h4>3.3 Immobilisations financières</h4>
    <p>Les immobilisations financières sont constituées des titres de participation, des prêts accordés au personnel et des dépôts et cautionnements versés.</p>
    <h4>3.4 Autres actifs non courants</h4>
    <p>Les autres actifs non courants sont composés essentiellement des frais préliminaires et des charges à répartir, amortis sur trois ans et présentés à leur valeur nette des résorptions.</p>
    <h4>3.5 Stocks</h4>
    <p>Les stocks sont valorisés au coût moyen pondéré. La méthode de l’inventaire intermittent est utilisée ; un inventaire physique est réalisé à la date d’arrêté des états financiers.</p>
    <h4>3.6 Clients et comptes rattachés</h4>
    <p>Les créances clients sont comptabilisées à leur valeur nominale ; une provision est constituée lorsque leur recouvrement est compromis.</p>
    <h4>3.7 Autres actifs courants</h4>
    <p>Les autres actifs courants regroupent notamment les avances au personnel, les impôts et taxes débiteurs, les débiteurs divers, les fournisseurs débiteurs et les comptes de régularisation actifs (charges constatées d’avance, produits à recevoir).</p>
    <h4>3.8 Placements et autres actifs financiers</h4>
    <p>Les placements sont comptabilisés à leur coût d’acquisition ; une provision est constituée lorsque leur valeur de marché à la date de clôture lui est inférieure.</p>
    <h4>3.9 Taxe sur la valeur ajoutée</h4>
    <p>Les charges sont comptabilisées hors TVA. La TVA récupérable est constatée dans le compte « État, TVA récupérable ».</p>
    {!! $pied !!}
</div>

{{-- Notes : détail des rubriques --}}
<div class="page texte">{!! $entete !!}
    <h3>4. Informations afférentes aux éléments figurant dans le corps des états financiers</h3>
    @forelse($notes as $code => $nt)
        @php $tn = $nt['brut']['n'] - ($nt['amort']['n'] ?? 0); $tn1 = $nt['brut']['n1'] - ($nt['amort']['n1'] ?? 0); @endphp
        <div class="detail">
            <h4>{{ $code }} {{ $nt['titre'] }}</h4>
            <div class="intro">Les {{ mb_strtolower($nt['titre']) }} se détaillent comme suit :</div>
            <table class="etat"><thead><tr><th>Rubriques</th><th class="num">{{ $date }}</th><th class="num">{{ $date1 }}</th><th class="num">Variation</th></tr></thead><tbody>
                @foreach($nt['lignes'] as $x)
                    <tr><td>{{ $x['libelle'] }}</td><td class="num">{{ $m($x['n']) }}</td><td class="num">{{ $m($x['n1']) }}</td><td class="num">{{ $m($x['n'] - $x['n1']) }}</td></tr>
                @endforeach
                @if($nt['amort'])
                    <tr class="total"><td>Total brut</td><td class="num">{{ $m($nt['brut']['n']) }}</td><td class="num">{{ $m($nt['brut']['n1']) }}</td><td class="num">{{ $m($nt['brut']['n'] - $nt['brut']['n1']) }}</td></tr>
                    <tr><td>{{ in_array($code, ['A1', 'A2'], true) ? 'Amortissements' : 'Provisions' }}</td><td class="num">{{ $m(-$nt['amort']['n']) }}</td><td class="num">{{ $m(-$nt['amort']['n1']) }}</td><td class="num">{{ $m(-($nt['amort']['n'] - $nt['amort']['n1'])) }}</td></tr>
                @endif
                <tr class="grand"><td>Total net</td><td class="num">{{ $m($tn) }}</td><td class="num">{{ $m($tn1) }}</td><td class="num">{{ $m($tn - $tn1) }}</td></tr>
            </tbody></table>
        </div>
    @empty
        <p>Aucune rubrique à détailler : aucune écriture n’est comptabilisée pour cet exercice.</p>
    @endforelse
    {!! $pied !!}
</div>
</body>
</html>
