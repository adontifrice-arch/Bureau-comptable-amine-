<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $acte->titre }}</title>
    {{-- Mise en page A4 alignée sur l'aperçu de l'éditeur (actes/edit) : marges, police, interlignes. --}}
    <style>
        @page { size: A4 portrait; margin: 20mm 20mm 22mm 20mm; }
        body { font-family: 'DejaVu Serif', serif; font-size: 11pt; line-height: 1.5; color: #111; text-align: justify; }
        p { margin: 0 0 6pt; }
        h1, h2, h3, h4 { font-weight: bold; margin: 12pt 0 6pt; line-height: 1.3; page-break-after: avoid; }
        h1 { font-size: 16pt; } h2 { font-size: 14pt; } h3 { font-size: 12pt; } h4 { font-size: 11pt; }
        ul, ol { margin: 0 0 6pt; padding-left: 18pt; }
        li { margin-bottom: 2pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; }
        td, th { vertical-align: top; padding: 3pt; }
        blockquote { margin: 0 0 6pt 18pt; }
    </style>
</head>
<body>{!! $contenu !!}</body>
</html>
