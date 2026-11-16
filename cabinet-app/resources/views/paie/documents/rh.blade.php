{{-- Documents RH du menu Employé. Page autonome (CSS en ligne) : impression depuis le navigateur ou PDF (dompdf). --}}
@php
    $fem = in_array($e->titre, ['Mme', 'Mlle'], true);
    $g = fn ($m, $f) => $e->titre ? ($fem ? $f : $m) : $m.'(e)';
    $civ = ['M.' => 'Monsieur', 'Mme' => 'Madame', 'Mlle' => 'Mademoiselle'][$e->titre] ?? '';
    $nom = trim($civ.' '.$e->nomComplet());
    $resp = $k['responsable_nom'] ?? $client->gerant_nom ?? '……………………';
    $respQ = $k['responsable_qualification'] ?? 'Gérant';
    $ville = $k['ville'] ?? '……………';
    $poste = $e->poste ?: '……………';
    $d = fn ($x) => $x ? $x->format('d/m/Y') : '……/……/…………';
    $logo = null;
    if (!empty($k['afficher_logo']) && !empty($k['logo']) && is_file(storage_path('app/public/'.$k['logo']))) {
        $logo = $pdf ? 'data:image/'.pathinfo($k['logo'], PATHINFO_EXTENSION).';base64,'.base64_encode(file_get_contents(storage_path('app/public/'.$k['logo']))) : asset('storage/'.$k['logo']);
    }
    $m = fn ($v) => number_format((float) $v, 3, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }} — {{ $e->nomComplet() }}</title>
    <style>
        @page { size: A4; margin: 18mm 18mm 20mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11pt; color: #111; line-height: 1.55; margin: 0; background: #fff; }
        .feuille { max-width: 180mm; margin: 0 auto; padding: 8mm 0; }
        .entete { display: table; width: 100%; border-bottom: 2px solid #1f4e5f; padding-bottom: 8pt; margin-bottom: 22pt; }
        .entete > div { display: table-cell; vertical-align: middle; }
        .entete img { max-height: 48pt; max-width: 140pt; }
        .employeur { font-size: 9pt; color: #333; }
        .employeur b { font-size: 12pt; color: #111; }
        h1 { text-align: center; font-size: 17pt; letter-spacing: 1px; text-transform: uppercase; margin: 10pt 0 26pt; }
        p { margin: 0 0 11pt; text-align: justify; }
        .signature { margin-top: 36pt; text-align: right; }
        .signature .cadre { display: inline-block; min-width: 200pt; text-align: center; }
        .signature .cadre div { height: 70pt; }
        table.t { width: 100%; border-collapse: collapse; margin: 10pt 0 16pt; font-size: 10pt; }
        table.t th, table.t td { border: 1px solid #555; padding: 5pt 7pt; }
        table.t th { background: #e8eef0; text-align: left; }
        table.t td.n { text-align: right; white-space: nowrap; }
        .bloc { border: 1px solid #555; padding: 6pt 9pt; margin-bottom: 10pt; font-size: 10pt; }
        .bloc h3 { margin: 0 0 4pt; font-size: 10pt; text-transform: uppercase; }
        .article { font-weight: bold; margin: 14pt 0 4pt; }
        .actions { position: fixed; top: 10px; right: 10px; font-family: Arial, sans-serif; font-size: 13px; background: #fff8e1; border: 1px solid #f0c36d; padding: 8px 12px; border-radius: 6px; }
        .actions button { padding: 5px 12px; margin-left: 6px; }
        [contenteditable]:focus { outline: 1px dashed #999; }
        @media print { .actions { display: none; } .feuille { padding: 0; } }
    </style>
</head>
<body>
@unless($pdf)
    <div class="actions">Le texte peut être modifié avant l’impression. <button onclick="window.print()">Imprimer</button></div>
@endunless
<div class="feuille" @unless($pdf) contenteditable="true" spellcheck="false" @endunless>
    @if($type !== 'demission')
        <div class="entete">
            <div class="employeur">
                <b>{{ $client->nom }}</b><br>
                {{ $client->adresse }} {{ trim(($k['code_postal'] ?? '').' '.($k['ville'] ?? '')) }}<br>
                @if($client->matricule_fiscal)Matricule fiscal : {{ $client->matricule_fiscal }}@endif
                @if($client->matricule_cnss) · N° employeur CNSS : {{ $client->matricule_cnss }}@endif
                @if($client->telephone)<br>Tél. : {{ $client->telephone }}@endif
            </div>
            @if($logo)<div style="text-align: right"><img src="{{ $logo }}" alt=""></div>@endif
        </div>
    @endif

    @switch($type)
        @case('attestation-travail')
            <h1>Attestation de travail</h1>
            <p>Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }} de la société <b>{{ $client->nom }}</b>, atteste que
                <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>{{ $e->cin_date ? ' délivrée le '.$d($e->cin_date) : '' }},
                est {{ $g('employé', 'employée') }} au sein de notre société depuis le <b>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</b> en qualité de <b>{{ $poste }}</b>@if($e->type_contrat), dans le cadre d’un contrat {{ \App\Models\Salarie::CONTRATS[$e->type_contrat] ?? $e->type_contrat }}@endif, et qu’{{ $fem ? 'elle' : 'il' }} fait toujours partie de notre personnel à ce jour.</p>
            <p>Cette attestation est délivrée à {{ $fem ? 'l’intéressée' : 'l’intéressé' }}, sur sa demande, pour servir et valoir ce que de droit.</p>
            @break

        @case('attestation-salaire')
            <h1>Attestation de salaire</h1>
            <p>Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }} de la société <b>{{ $client->nom }}</b>, atteste que <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>,
                est {{ $g('employé', 'employée') }} au sein de notre société depuis le <b>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</b> en qualité de <b>{{ $poste }}</b>
                et perçoit, pour le mois de {{ mois_fr($mois) }}, la rémunération suivante :</p>
            <table class="t">
                <tr><th>Salaire brut</th><td class="n">{{ $m($paie['brut'] ?? $e->salaire_base) }} TND</td></tr>
                <tr><th>Retenue CNSS</th><td class="n">{{ $m($paie['cnssS'] ?? 0) }} TND</td></tr>
                <tr><th>Retenue IRPP et CSS</th><td class="n">{{ $m($paie['ras'] ?? 0) }} TND</td></tr>
                <tr><th>Salaire net</th><td class="n"><b>{{ $m($paie['net'] ?? 0) }} TND</b></td></tr>
            </table>
            <p>Cette attestation est délivrée à {{ $fem ? 'l’intéressée' : 'l’intéressé' }}, sur sa demande, pour servir et valoir ce que de droit.</p>
            @break

        @case('certificat-travail')
            <h1>Certificat de travail</h1>
            <p>Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }} de la société <b>{{ $client->nom }}</b>, certifie que <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>,
                a été {{ $g('employé', 'employée') }} dans notre société du <b>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</b> au <b>{{ $d($e->date_sortie) }}</b> en qualité de <b>{{ $poste }}</b>.</p>
            <p>{{ $fem ? 'Elle' : 'Il' }} nous quitte libre de tout engagement.</p>
            <p>Ce certificat est délivré à {{ $fem ? 'l’intéressée' : 'l’intéressé' }} pour servir et valoir ce que de droit.</p>
            @break

        @case('certificat-retenue')
            <h1 style="font-size: 14pt">Certificat de retenue d’impôt sur le revenu<br><span style="font-size: 11pt; text-transform: none">Traitements et salaires — année {{ $annee }}</span></h1>
            <div class="bloc"><h3>A. Personne ou organisme payeur</h3>
                Raison sociale : <b>{{ $client->nom }}</b><br>Matricule fiscal : <b>{{ $client->matricule_fiscal ?: '……………' }}</b><br>
                Adresse : {{ $client->adresse }} {{ trim(($k['code_postal'] ?? '').' '.($k['ville'] ?? '')) }}</div>
            <div class="bloc"><h3>B. Bénéficiaire</h3>
                Nom et prénom : <b>{{ $e->nomComplet() }}</b><br>N° CIN / carte de séjour : <b>{{ $e->cin ?: '……………' }}</b><br>
                Adresse : {{ trim($e->adresse.' '.$e->code_postal.' '.$e->ville) ?: '……………' }}<br>Qualité : {{ $poste }}</div>
            <table class="t">
                <tr><th>Nature des montants payés</th><th style="text-align: right">Montant brut</th><th style="text-align: right">Retenue CNSS</th><th style="text-align: right">Retenue IRPP</th><th style="text-align: right">CSS</th><th style="text-align: right">Montant net servi</th></tr>
                <tr><td>Traitements et salaires</td><td class="n">{{ $m($cumul['brut']) }}</td><td class="n">{{ $m($cumul['cnssS']) }}</td><td class="n">{{ $m($cumul['ir']) }}</td><td class="n">{{ $m($cumul['css']) }}</td><td class="n">{{ $m($cumul['net']) }}</td></tr>
                <tr><th>Total</th><th style="text-align: right">{{ $m($cumul['brut']) }}</th><th style="text-align: right">{{ $m($cumul['cnssS']) }}</th><th style="text-align: right">{{ $m($cumul['ir']) }}</th><th style="text-align: right">{{ $m($cumul['css']) }}</th><th style="text-align: right">{{ $m($cumul['net']) }}</th></tr>
            </table>
            <p style="font-size: 10pt">Montants en dinars tunisiens. Je soussigné(e) certifie exacts les renseignements figurant sur le présent certificat et m’expose aux sanctions prévues par la loi pour toute inexactitude.</p>
            @break

        @case('contrat')
            @php $cdd = $e->type_contrat === 'CDD'; @endphp
            <h1>Contrat de travail {{ $cdd ? 'à durée déterminée' : ($e->type_contrat && $e->type_contrat !== 'CDI' ? '('.(\App\Models\Salarie::CONTRATS[$e->type_contrat] ?? $e->type_contrat).')' : 'à durée indéterminée') }}</h1>
            <p><b>Entre les soussignés :</b></p>
            <p>La société <b>{{ $client->nom }}</b>, {{ \App\Http\Controllers\PaieDossierController::STATUTS[$client->forme] ?? $client->forme }}, matricule fiscal {{ $client->matricule_fiscal ?: '……………' }}, dont le siège est situé {{ $client->adresse }} {{ $k['ville'] ?? '' }},
                représentée par <b>{{ $resp }}</b> en sa qualité de {{ $respQ }}, ci-après dénommée « l’employeur »,</p>
            <p><b>Et :</b></p>
            <p><b>{{ $nom }}</b>, {{ $g('né', 'née') }} le {{ $d($e->date_naissance) }} à {{ $e->lieu_naissance ?: '……………' }}, de nationalité {{ $e->nationalite ?: '……………' }}, titulaire de la CIN n° {{ $e->cin ?: '……………' }},
                demeurant {{ trim($e->adresse.' '.$e->code_postal.' '.$e->ville) ?: '……………' }}, ci-après dénommé(e) « le salarié »,</p>
            <p><b>Il a été convenu ce qui suit :</b></p>
            <div class="article">Article 1 — Engagement et fonctions</div>
            <p>L’employeur engage le salarié en qualité de <b>{{ $poste }}</b>@if($e->categorie), catégorie {{ $e->categorie }}@endif @if($e->echelon), échelon {{ $e->echelon }}@endif, à compter du <b>{{ $d($e->contrat_debut) }}</b>.</p>
            <div class="article">Article 2 — Durée</div>
            <p>@if($cdd) Le présent contrat est conclu pour une durée déterminée, du {{ $d($e->contrat_debut) }} au <b>{{ $d($e->contrat_fin) }}</b>, pour le motif suivant : ……………………………………. @else Le présent contrat est conclu pour une durée indéterminée. @endif
                Il est soumis à une période d’essai de …… mois.</p>
            <div class="article">Article 3 — Rémunération</div>
            <p>En contrepartie de son travail, le salarié perçoit un salaire de base {{ $e->regime === 'horaire' ? 'horaire' : 'mensuel' }} brut de <b>{{ $m($e->salaire_base) }} TND</b>{{ $e->regime === 'horaire' ? ' de l’heure' : '' }}, payable par {{ mb_strtolower(\App\Models\Salarie::MODES[$e->mode_paiement] ?? 'virement') }}, sous déduction des retenues légales.</p>
            <div class="article">Article 4 — Durée du travail</div>
            <p>La durée hebdomadaire de travail est fixée à {{ $k['heures_semaine'] ?? 48 }} heures, selon l’horaire en vigueur dans l’entreprise.</p>
            <div class="article">Article 5 — Congés</div>
            <p>Le salarié bénéficie des congés payés prévus par le Code du travail et la convention collective applicable.</p>
            <div class="article">Article 6 — Dispositions générales</div>
            <p>Pour tout ce qui n’est pas prévu au présent contrat, les parties se réfèrent au Code du travail et à la convention collective sectorielle applicable.</p>
            <p>Fait en deux exemplaires originaux, à {{ $ville }}, le {{ now()->format('d/m/Y') }}.</p>
            <div style="display: table; width: 100%; margin-top: 26pt"><div style="display: table-cell; width: 50%"><b>Le salarié</b><br><span style="font-size: 9pt">(lu et approuvé)</span></div><div style="display: table-cell; text-align: right"><b>L’employeur</b></div></div>
            @break

        @case('demission')
            <div style="margin-bottom: 26pt">{{ $nom }}<br>{{ trim($e->adresse.' '.$e->code_postal.' '.$e->ville) }}<br>@if($e->tel_mobile)Tél. : {{ $e->tel_mobile }}@endif</div>
            <div style="margin-left: 55%; margin-bottom: 26pt">À l’attention de {{ $resp }}<br>{{ $respQ }}<br><b>{{ $client->nom }}</b><br>{{ $client->adresse }} {{ $k['ville'] ?? '' }}<br><br>{{ $ville }}, le {{ now()->format('d/m/Y') }}</div>
            <p><b>Objet : démission</b></p>
            <p>Madame, Monsieur,</p>
            <p>Par la présente, je vous informe de ma décision de démissionner du poste de <b>{{ $poste }}</b> que j’occupe au sein de votre société depuis le {{ $d($e->date_embauche ?? $e->contrat_debut) }}.</p>
            <p>Conformément aux dispositions applicables, j’effectuerai mon préavis ; mon dernier jour de travail sera le <b>{{ $d($e->date_sortie) }}</b>, sauf accord entre nous pour une autre date.</p>
            <p>Je vous saurais gré de bien vouloir me remettre, à l’issue de mon contrat, mon certificat de travail ainsi que mon solde de tout compte.</p>
            <p>Je vous prie d’agréer, Madame, Monsieur, l’expression de mes salutations distinguées.</p>
            <div class="signature"><div class="cadre">{{ $nom }}<div></div></div></div>
            @break
    @endswitch

    @if(! in_array($type, ['contrat', 'demission'], true))
        <p style="margin-top: 22pt">Fait à {{ $ville }}, le {{ now()->format('d/m/Y') }}.</p>
        <div class="signature"><div class="cadre">{{ $resp }}<br>{{ $respQ }}<div></div>Signature et cachet de l’employeur</div></div>
    @endif
</div>
</body>
</html>
