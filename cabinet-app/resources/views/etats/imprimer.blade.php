<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $etat->titre }} – {{ $etat->client }}</title>
    {{-- État imprimable (spécification §8) : texte sombre sur fond blanc, A4 portrait ou paysage, en-tête répété. --}}
    <style>
        @page { size: A4 {{ $etat->paysage ? 'landscape' : 'portrait' }}; margin: 12mm 12mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8pt; color: #111; background: #fff; margin: 0; }
        .entete { display: table; width: 100%; margin-bottom: 8pt; border-bottom: 1px solid #333; padding-bottom: 6pt; }
        .entete > div { display: table-cell; vertical-align: middle; }
        .entete img { height: 34pt; margin-right: 8pt; }
        h1 { font-size: 13pt; margin: 0 0 2pt; }
        .meta { color: #444; font-size: 8.5pt; }
        .droite { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background: #e2e8f0; font-weight: bold; text-align: left; padding: 3pt 4pt; border-bottom: 1px solid #333; vertical-align: bottom; }
        td { padding: 2.5pt 4pt; border-bottom: 1px solid #ddd; vertical-align: top; }
        th.num, td.num { text-align: right; white-space: nowrap; }
        tr { page-break-inside: avoid; }
        tr.groupe td { background: #f1f5f9; font-weight: bold; border-bottom: 1px solid #999; }
        tr.soustotal td { font-weight: bold; border-top: 1px solid #666; }
        tr.total td { font-weight: bold; border-top: 2px double #111; background: #f8fafc; }
        table.grille td, table.grille th { border: 1px solid #777; }
        table.grille td { height: 20pt; vertical-align: middle; }
        .actions { margin: 10px 0; font-family: Arial, sans-serif; }
        .actions button { padding: 6px 12px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    @if($impression)
        @php $route = request()->route(); $autres = $route && $route->hasParameter('format') ? ['pdf' => 'PDF', 'xlsx' => 'Excel', 'docx' => 'Word'] : []; @endphp
        <div class="actions"><button onclick="window.print()">Imprimer</button> <button onclick="history.back()">Retour</button>
            @foreach($autres as $f => $l) <a href="{{ route($route->getName(), ['format' => $f] + $route->parameters() + request()->query()) }}" style="margin-left: 8px">{{ $l }}</a>@endforeach
        </div>
    @endif
    <div class="entete">
        <div>
            @if(!empty($cab['logo']) && is_file(storage_path('app/public/'.$cab['logo'])))
                <img src="{{ $impression ? asset('storage/'.$cab['logo']) : 'data:image/'.pathinfo($cab['logo'], PATHINFO_EXTENSION).';base64,'.base64_encode(file_get_contents(storage_path('app/public/'.$cab['logo']))) }}" alt="">
            @endif
        </div>
        <div>
            <h1>{{ $etat->titre }}</h1>
            <div class="meta">{{ $cab['nom'] }}{{ !empty($cab['mf']) ? ' · MF '.$cab['mf'] : '' }}</div>
            <div class="meta">Dossier : <b>{{ $etat->client }}</b> · {{ $etat->periode }}</div>
        </div>
        <div class="droite meta">Édité le {{ now()->format('d/m/Y à H:i') }}<br>Montants en TND</div>
    </div>
    <table class="{{ $etat->grille ? 'grille' : '' }}">
        <thead><tr>@foreach($etat->colonnes as [$libelle, $type])<th class="{{ $type === 'num' ? 'num' : '' }}">{{ $libelle }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($etat->lignes as $l)
            <tr class="{{ $l['style'] }}">
                @foreach($etat->colonnes as $k => [, $type])<td class="{{ $type === 'num' ? 'num' : '' }}">{{ $etat->texte($l['cellules'][$k] ?? null, $type) }}</td>@endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($etat->colonnes) }}">Aucune donnée.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
