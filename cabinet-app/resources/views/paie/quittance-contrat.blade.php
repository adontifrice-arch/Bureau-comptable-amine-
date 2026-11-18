@php
    $d = $x['stc'] ?? [];
    $societe = mb_strtoupper($client->nom);
    $femme = in_array($s?->titre, ['Mme', 'Mlle'], true);
    $civilite = $femme ? $s->titre : 'Mr';
    $matricule = ctype_digit((string) $x['matricule']) ? str_pad($x['matricule'], 4, '0', STR_PAD_LEFT) : (string) $x['matricule'];
    $vide = '……………';
    $cin = $x['cin'] ?: $vide;
    $delivree = 'délivrée à Tunis le '.($s?->cin_date?->format('d/m/Y') ?? '……………');
    $ville = mb_strtoupper(trim((string) (($client->paie_config ?? [])['ville'] ?? '')) ?: 'TUNIS');
    $date = now()->format('d/m/Y');
    $contrat = \App\Models\Salarie::CONTRATS[$s?->type_contrat] ?? '';
    $articles = 'articles 6 alinéa 2 paragraphe 2, 14 et 14 bis du code du travail tel que modifié par la loi n°96_62 du 15 juillet 1996, les textes législatifs ultérieurs et la convention collective cadre qui régit l’activité de l’employeur';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Quittance de solde de tout compte – {{ $x['nom'] }}</title>
    {{-- Modèle : quittance de solde de tout compte relative à un contrat de travail (A4 portrait, une page). --}}
    <style>
        @page { size: A4 portrait; margin: 16mm 16mm 12mm 16mm; }
        body { font-family: Cambria, 'DejaVu Serif', Georgia, serif; font-size: 9.5pt; color: #000; background: #fff; margin: 0; line-height: 1.3; }
        .titre { border: 3px double #000; text-align: center; font-family: 'Times New Roman', 'DejaVu Serif', serif; font-weight: bold; font-size: 13.5pt; padding: 8pt 0; margin: 0 4mm 7mm; }
        .preambule { font-family: 'Times New Roman', 'DejaVu Serif', serif; font-size: 8pt; margin: 0 0 7mm; }
        p { text-align: justify; margin: 0 0 1.1em; }
        .fin p { text-align: left; margin: 0 0 1.35em; }
        .fin p.serre { margin: 0; }
        .texte { outline: none; }
        .actions { margin: 10px 0; font-family: Arial, sans-serif; }
        .actions button, .actions a { margin-right: 8px; padding: 6px 12px; }
        .actions span { font-size: 12px; color: #64748b; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
@if($impression)
    <div class="actions">
        <button onclick="window.print()">Imprimer</button>
        <button onclick="history.back()">Retour</button>
        <a href="{{ route('paie.stc.quittance', [$paie, 'modele' => 'contrat', 'format' => 'pdf']) }}">PDF</a>
        <span>Cliquez dans le texte pour le retoucher avant l’impression.</span>
    </div>
@endif
<div class="texte" @if($impression) contenteditable="true" @endif>
    <div class="titre">QUITTANCE DE SOLDE DE TOUT COMPTE<br>RELATIVE A UN CONTRAT DE TRAVAIL</div>

    <div class="preambule">Conformément aux articles 6 alinéa 2 paragraphe 2, alinéa 4, paragraphe 2, 14, 14 bis et 14 ter du code de travail tel que modifié par la loi n°96_62 du 15 juillet 1996, les textes législatifs ultérieurs et la convention collective cadre qui régit l'activité de l'employeur,</div>

    <p>Je soussigné(e) <b>{{ mb_strtoupper($x['nom']) }}</b>, Matricule N° <b>{{ $matricule }}</b> {{ $femme ? 'née' : 'né' }} le <b>{{ $s?->date_naissance?->format('d/m/Y') ?? $vide }}</b> à {{ $s?->lieu_naissance }}, titulaire de la carte d'identité nationale n° <b>{{ $cin }}</b> {{ $delivree }}, recruté{{ $femme ? 'e' : '' }} par la Société <b>{{ $societe }}</b> sise à <b>{{ mb_strtoupper((string) $client->adresse) }}</b> par un contrat {{ $contrat }}, reconnait avoir reçu de la dite société <b>{{ $societe }}</b> à la date du <b>{{ $date }},</b> un montant de la somme de <b>{{ number_format($x['net'], 3, ',', ' ') }}</b> DT, déduction faite de retenues légales en paiement de tout salaire, accessoires de salaire, de toutes primes et toutes indemnités légales et conventionnelles quelque soit leurs natures ou objets qui pourrait être dues au titre de l’exécution et la cessation de mon contrat de travail convenu d’un commun accord avec mon employeur la Société <b>{{ $societe }}</b> à la suite de mon départ daté au <b>{{ $d['date_sortie'] ?? '  /  /' }}</b>.</p>

    <p>En acceptant la dite somme, je déclare expressément n’avoir plus rien à prétendre de mon employeur la Société <b>{{ $societe }}</b> pour aucune cause que ce soit, et quelque soit sa date, sa volontaire forfaitaire, transaction, transactionnel, global et définitif sans aucune réserve pour solde de tous comptes droits, indemnités et prétentions en vertu des {{ $articles }}.</p>

    <p>Par ailleurs, je déclare, et certifie qu’il n’existe aucun différent entre moi et mon employeur la Société <b>{{ $societe }}</b> concernant toute la relation et période de travail que j’ai passé à son service.</p>

    <p>Je reconnais également, que mon employeur la Société <b>{{ $societe }}</b> a procédé au payement de tous mes droits (sans exception ni réserves) quelque soient leurs natures ou objets. Toutes prétentions futures ayant pour cause d’une manière directe ou indirecte mon contrat de travail et plus généralement toute la période de travail que j’ai passé au sein de la Société <b>{{ $societe }}</b> seraient sans objet et non avenue, et entraînent par voies de conséquences ma responsabilité.</p>

    <p>Enfin, par ma signature en bas des présentes je décharge et libère expressément mon employeur la Société <b>{{ $societe }}</b> de toute responsabilités dettes, obligations ou droits et quitus lui est donné.</p>

    <div class="fin">
        <p style="margin-top: 2em">Fait à <b>{{ $ville }}</b> le <b>{{ $date }}</b></p>
        <p>Pour solde de tous comptes sans aucune réserve.</p>
        <p class="serre"><b>{{ $civilite }}</b> <b>{{ mb_strtoupper($x['nom']) }}</b> Matricule N° <b>{{ $matricule }}</b></p>
        <p class="serre">Titulaire de la Carte d’Identité Nationale N° <b>{{ $cin }}</b> {{ $delivree }}.</p>
    </div>
</div>
</body>
</html>
