<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bulletin de paie — {{ $x['nom'] }} — {{ $x['_mois'] }}</title>
    {{-- Bulletin de paie au format PDF (Paie → Bulletin → Générer les bulletins) : mise en page simple lue par DomPDF. --}}
    <style>
        @page { margin: 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #111; }
        .entete { width: 100%; border-bottom: 2px solid #1e3a8a; padding-bottom: 6pt; margin-bottom: 8pt; }
        .entete td { vertical-align: top; }
        h1 { font-size: 13pt; margin: 0; }
        .petit { font-size: 8pt; color: #444; }
        .bloc { width: 100%; background: #f1f5f9; margin-bottom: 8pt; }
        .bloc td { padding: 5pt 7pt; vertical-align: top; width: 50%; }
        .etiquette { font-size: 7pt; text-transform: uppercase; color: #64748b; font-weight: bold; }
        table.lignes { width: 100%; border-collapse: collapse; }
        table.lignes th { background: #e2e8f0; text-align: left; padding: 4pt 6pt; font-size: 8pt; text-transform: uppercase; }
        table.lignes td { padding: 3.5pt 6pt; border-bottom: 1px solid #ddd; }
        .num { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; background: #f8fafc; }
        tr.info td { font-size: 8pt; font-style: italic; color: #64748b; }
        .pied { margin-top: 8pt; font-size: 8pt; color: #555; }
    </style>
</head>
<body>
    <table class="entete"><tr>
        <td><b>{{ $client->nom }}</b><br><span class="petit">{{ $client->adresse }}<br>MF {{ $client->matricule_fiscal }} · CNSS {{ $client->matricule_cnss }}</span></td>
        <td style="text-align: right"><h1>BULLETIN DE PAIE</h1><span style="text-transform: capitalize">{{ ($x['_periode'] ?? mois_fr($x['_mois'])) }}</span></td>
    </tr></table>
    <table class="bloc"><tr>
        <td><div class="etiquette">Salarié</div><b>{{ $x['nom'] }}</b><br>{{ $x['poste'] }}</td>
        <td><div class="etiquette">Identification</div>Matricule {{ $x['matricule'] ?? '—' }}<br>CIN {{ $x['cin'] ?? '—' }} · CNSS {{ $x['cnss'] ?? '—' }}</td>
    </tr></table>
    <table class="lignes">
        <thead><tr><th>Rubrique</th><th class="num">Montant (TND)</th></tr></thead>
        <tbody>
        @foreach($lignes as $row)
            <tr class="{{ ($row[2] ?? 0) === 1 ? 'total' : (($row[2] ?? 0) === 2 ? 'info' : '') }}"><td>{{ $row[0] }}</td><td class="num">{{ tnd($row[1], false) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="pied">Charges patronales : CNSS {{ tnd($x['cnssP']) }} · TFP {{ tnd($x['tfp']) }} · FOPROLOS {{ tnd($x['fop']) }}@if(! empty($x['etat'])) · quote-part de l’État (contrat Dignité) − {{ tnd($x['etat']) }}@endif — coût total employeur {{ tnd($x['cout']) }}</div>
</body>
</html>
