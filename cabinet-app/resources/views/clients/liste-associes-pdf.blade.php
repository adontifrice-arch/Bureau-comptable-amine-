<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Tableau des associés — {{ $client->nom }}</title>
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111; }
    h1 { text-align: center; font-size: 17px; margin: 0 0 14px; letter-spacing: .5px; }
    .ligne { font-size: 13px; font-weight: bold; margin: 0 0 8px; }
    .ligne2 { font-size: 11px; font-weight: bold; margin: 0 0 14px; }
    .ligne2 span { display: inline-block; min-width: 300px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #000; padding: 4px 5px; }
    th { font-weight: bold; text-align: center; vertical-align: middle; height: 34px; }
    td.n { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; }
    .pied { margin-top: 10px; font-size: 8px; color: #555; }
</style>
</head>
<body>
    <h1>TABLEAU DES ASSOCIES/ACTIONNAIRES</h1>
    <p class="ligne">IDENTIFIANT UNIQUE : {{ $identifiant }} &nbsp;&nbsp; {{ mb_strtoupper($client->nom) }}</p>
    <p class="ligne2"><span>CAPITAL SOCIAL : {{ $client->capital > 0 ? number_format((float) $client->capital, 3, ',', ' ') : '' }}</span>
        VALEUR NOMINAL : {{ $client->valeur_nominale !== null ? number_format((float) $client->valeur_nominale, 3, ',', ' ') : '' }}</p>
    <table>
        <thead><tr>@foreach(\App\Services\Exports\ListeAssocies::COLONNES as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($lignes as $l)
            <tr>
                <td>{{ $l['genre'] }}</td>
                <td>{{ $l['nom'] }}</td>
                <td>{{ $l['type'] }}</td>
                <td>{{ $l['numero'] }}</td>
                <td>{{ $l['naissance'] ? \Carbon\Carbon::parse($l['naissance'])->format('d/m/Y') : '' }}</td>
                <td>{{ $l['nationalite'] }}</td>
                <td class="n">{{ $total ? number_format($l['total'] / $total * 100, 2, ',', ' ').' %' : '' }}</td>
                <td class="n">{{ $l['numeraire'] }}</td>
                <td class="n">{{ $l['nature'] }}</td>
                <td class="n">{{ $l['total'] }}</td>
            </tr>
        @endforeach
        @if($lignes)
            <tr class="total">
                <td></td><td>TOTAL</td><td></td><td></td><td></td><td></td>
                <td class="n">{{ $total ? number_format(array_sum(array_column($lignes, 'total')) / $total * 100, 2, ',', ' ').' %' : '' }}</td>
                <td class="n">{{ array_sum(array_column($lignes, 'numeraire')) }}</td>
                <td class="n">{{ array_sum(array_column($lignes, 'nature')) }}</td>
                <td class="n">{{ array_sum(array_column($lignes, 'total')) }}</td>
            </tr>
        @endif
        </tbody>
    </table>
    <p class="pied">Établi d’après la fiche client le {{ now()->format('d/m/Y') }}.</p>
</body>
</html>
