{{--
    Documents RH du menu Employé. Page autonome (CSS en ligne) : impression depuis le navigateur ou PDF (dompdf).
    $pages : une page par employé (le certificat de retenue peut être établi pour tous les employés en une fois).
--}}
@php
    $resp = $k['responsable_nom'] ?? $client->gerant_nom ?? '……………………';
    $respQ = $k['responsable_qualification'] ?? 'Gérant';
    $ville = $k['ville'] ?? '……………';
    $logo = null;
    if (!empty($k['afficher_logo']) && !empty($k['logo']) && is_file(storage_path('app/public/'.$k['logo']))) {
        $logo = $pdf ? 'data:image/'.pathinfo($k['logo'], PATHINFO_EXTENSION).';base64,'.base64_encode(file_get_contents(storage_path('app/public/'.$k['logo']))) : asset('storage/'.$k['logo']);
    }
    $d = fn ($x) => $x ? $x->format('d/m/Y') : '……/……/…………';
    $m = fn ($v) => number_format((float) $v, 3, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}{{ count($pages) === 1 ? ' — '.$pages[0]['e']->nomComplet() : '' }}</title>
    <style>
        @page { size: A4; margin: 18mm 18mm 20mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11pt; color: #111; line-height: 1.55; margin: 0; background: #fff; }
        .feuille { max-width: 180mm; margin: 0 auto; padding: 8mm 0; }
        .feuille.suite { page-break-before: always; break-before: page; }
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
        .deux { display: table; width: 100%; margin-top: 26pt; }
        .deux > div { display: table-cell; width: 50%; vertical-align: top; }
        table.t { width: 100%; border-collapse: collapse; margin: 10pt 0 16pt; font-size: 10pt; }
        table.t th, table.t td { border: 1px solid #555; padding: 5pt 7pt; }
        table.t th { background: #e8eef0; text-align: left; }
        table.t td.n, table.t th.n { text-align: right; white-space: nowrap; }
        table.t td.c, table.t th.c { text-align: center; }
        .bloc { border: 1px solid #555; padding: 6pt 9pt; margin-bottom: 10pt; font-size: 10pt; }
        .bloc h3 { margin: 0 0 4pt; font-size: 10pt; text-transform: uppercase; }
        .article { font-weight: bold; margin: 14pt 0 4pt; }
        .cnss-entete { display: table; width: 100%; font-size: 9pt; margin-bottom: 14pt; }
        .cnss-entete > div { display: table-cell; vertical-align: top; }
        .cnss-titre { text-align: center; border: 2px solid #111; padding: 6pt; margin: 6pt 0 14pt; }
        .cnss-titre b { font-size: 15pt; letter-spacing: 1px; }
        .grille { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 10pt; }
        .grille td { border: 1px solid #555; padding: 4pt 6pt; }
        .grille td.l { width: 38%; background: #f1f4f5; }
        .actions { position: fixed; top: 10px; right: 10px; font-family: Arial, sans-serif; font-size: 13px; background: #fff8e1; border: 1px solid #f0c36d; padding: 8px 12px; border-radius: 6px; }
        .actions button { padding: 5px 12px; margin-left: 6px; }
        [contenteditable]:focus { outline: 1px dashed #999; }
        @media print { .actions { display: none; } .feuille { padding: 0; } }
    </style>
</head>
<body>
@unless($pdf)
    <div class="actions">Le texte peut être modifié avant l’impression.@if(count($pages) > 1) {{ count($pages) }} documents, un par page.@endif <button onclick="window.print()">Imprimer</button></div>
@endunless
@foreach($pages as $pg)
    @include('paie.documents._rh_page', $pg + ['suite' => ! $loop->first])
@endforeach
</body>
</html>
