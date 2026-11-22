<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bulletin de paie — {{ $x['nom'] }} — {{ $x['_mois'] }}</title>
    {{-- Bulletin de paie A5 en PDF (Générer les bulletins, format A5) : même modèle que l'impression A5. --}}
    <style>@page { margin: 6mm; } body { margin: 0; }</style>
</head>
<body>
    @php $kc = $client->paie_config ?? []; @endphp
    @include('paie._bulletin_a5', ['x' => $x, 's' => $s, 'variante' => '', 'mois' => $x['_mois'], 'logoEmp' => null,
        'villeEmp' => trim(($kc['code_postal'] ?? '').' '.($kc['ville'] ?? ''))])
</body>
</html>
