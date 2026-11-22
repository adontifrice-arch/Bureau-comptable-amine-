{{--
    Mise en page des documents de paie remis au salarié (échéancier de prêt, reçu d'avance sur salaire, demande de congé) :
    page autonome à l'en-tête de l'employeur, texte modifiable avant l'impression. Variables : $client, $titre ; section « corps ».
--}}

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    <style>
        @page { size: {{ $format ?? 'A4' }}; margin: 14mm 15mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5pt; color: #111; line-height: 1.5; margin: 0; background: #fff; }
        .feuille { max-width: 180mm; margin: 0 auto; padding: 8mm 0; }
        .feuille + .feuille { page-break-before: always; break-before: page; }
        .entete { display: table; width: 100%; border-bottom: 2px solid #1f4e5f; padding-bottom: 6pt; margin-bottom: 16pt; }
        .entete > div { display: table-cell; vertical-align: middle; }
        .entete img { max-height: 44pt; max-width: 130pt; }
        .employeur { font-size: 8.5pt; color: #333; }
        .employeur b { font-size: 11.5pt; color: #111; }
        h1 { text-align: center; font-size: 15pt; letter-spacing: 1px; text-transform: uppercase; margin: 6pt 0 18pt; }
        h1 small { display: block; font-size: 10pt; letter-spacing: 0; text-transform: none; font-weight: normal; color: #444; }
        p { margin: 0 0 9pt; text-align: justify; }
        table.t { width: 100%; border-collapse: collapse; margin: 6pt 0 12pt; font-size: 9.5pt; }
        table.t th, table.t td { border: 1px solid #555; padding: 3.5pt 6pt; }
        table.t th { background: #e8eef0; text-align: left; }
        table.t .n { text-align: right; white-space: nowrap; }
        table.t .c { text-align: center; }
        table.t tr.total th, table.t tr.total td { font-weight: bold; background: #f1f4f5; }
        .infos { display: table; width: 100%; font-size: 9.5pt; margin-bottom: 10pt; }
        .infos > div { display: table-row; }
        .infos span { display: table-cell; padding: 1.5pt 0; }
        .infos span:first-child { width: 38%; color: #555; }
        .cadre { border: 1px solid #555; padding: 6pt 9pt; margin-bottom: 10pt; }
        .deux { display: table; width: 100%; margin-top: 18pt; }
        .deux > div { display: table-cell; width: 50%; vertical-align: top; }
        .deux .sig { height: 55pt; }
        .case { display: inline-block; width: 9pt; height: 9pt; border: 1px solid #111; margin-right: 4pt; vertical-align: -1pt; }
        .coupe { border-top: 1px dashed #777; margin: 14pt 0; text-align: center; font-size: 8pt; color: #777; }
        .actions { position: fixed; top: 10px; right: 10px; font-family: Arial, sans-serif; font-size: 13px; background: #fff8e1; border: 1px solid #f0c36d; padding: 8px 12px; border-radius: 6px; z-index: 10; }
        .actions button, .actions a { padding: 5px 12px; margin-left: 6px; }
        [contenteditable]:focus { outline: 1px dashed #999; }
        @media print { .actions { display: none; } .feuille { padding: 0; } }
    </style>
</head>
<body>
    <div class="actions">Le texte peut être modifié avant l’impression. <button onclick="window.print()">Imprimer</button>@isset($retour)<a href="{{ $retour }}">Retour</a>@endisset</div>
    @yield('corps')
</body>
</html>
