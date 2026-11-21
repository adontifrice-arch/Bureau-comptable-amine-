<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Déclaration trimestrielle CNSS – {{ $client->nom }} – T{{ $trimestre }} {{ $annee }}</title>
    {{-- Modèle CNSS : déclaration trimestrielle des salariés et des salaires (A4 paysage, une page par bloc d'assurés). --}}
    <style>
        @page { size: A4 landscape; margin: 10mm 12mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Serif', Cambria, Georgia, serif; font-size: 8.5pt; color: #000; background: #fff; margin: 0; }
        .pied { text-align: right; font-size: 7pt; margin-top: 16pt; }
        .saut { page-break-after: always; }
        h1 { font-size: 10pt; margin: 0 0 12pt; padding-bottom: 4pt; border-bottom: 1.5px solid #000; }
        table { border-collapse: collapse; }
        .entete { width: 100%; margin-bottom: 12pt; }
        .entete > tbody > tr > td { vertical-align: top; padding: 0; }
        .boite { border: 1.5px solid #000; width: 100%; }
        .boite td { padding: 5pt 7pt; vertical-align: top; }
        .sep-b { border-bottom: 1px solid #000; }
        .sep-d { border-right: 1px solid #000; }
        .grille { width: 100%; border: 1.5px solid #000; }
        .grille th { font-weight: bold; text-align: center; vertical-align: middle; border: 1px solid #000; padding: 5pt 4pt; }
        .grille td { border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 3px double #000; padding: 4pt 5pt; height: 17pt; vertical-align: middle; }
        .c { text-align: center; }
        .n { text-align: right; white-space: nowrap; }
        .g { font-weight: bold; }
        .grille tr.total td { border-top: 1px solid #000; border-bottom: 1.5px solid #000; height: auto; padding: 5pt; }
        .certif { width: 100%; margin-top: 18pt; }
        .certif td { vertical-align: top; padding: 0; }
        .actions { margin: 10px 0; font-family: Arial, sans-serif; }
        .actions button, .actions a { margin-right: 8px; padding: 6px 12px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
@include('paie.cnss._actions')
@php $nb = count($pages); @endphp
@foreach($pages as $i => $page)
    <div class="page {{ $i + 1 < $nb ? 'saut' : '' }}">
        <h1>Déclaration trimestrielle des salaries et des salaires</h1>

        <table class="entete">
            <tr>
                <td style="width: 37%; padding-right: 14pt">
                    <table class="boite">
                        <tr>
                            <td class="sep-b sep-d" style="width: 64%"><b>N° employeur:</b>&nbsp;&nbsp; {{ $employeur }}</td>
                            <td rowspan="2" class="c"><b>Matricule fiscal:</b><br><br>{{ $matricule_fiscal }}</td>
                        </tr>
                        <tr>
                            <td class="sep-d" style="height: 62pt">
                                <b>Trimestre</b>&nbsp;&nbsp; <b>{{ $libelle_trimestre }}</b><br><br>
                                <b>Br :</b> {{ $bureau }}<span style="margin-left: 30pt"><b>Code d’exploitation :</b> {{ $code }}</span><br><br>
                                <b>Page :</b>&nbsp; <b>{{ $i + 1 }}</b>
                                <span style="margin-left: 40pt"><b>Année :{{ $annee }}</b></span>
                            </td>
                        </tr>
                    </table>
                </td>
                <td>
                    <table class="boite">
                        <tr>
                            <td style="width: 58%; height: 98pt">
                                <b>{{ mb_strtoupper($client->nom) }}</b><br><br>
                                {{ mb_strtoupper((string) $adresse) }}<br><br>
                                {{ $code_postal }}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{ mb_strtoupper((string) $ville) }}
                            </td>
                            <td>{{ mb_strtoupper((string) $activite) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="grille">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 6%">N°<br>ordre</th>
                    <th rowspan="2" style="width: 12.5%">Matricule de<br>l'assuré</th>
                    <th rowspan="2">Identité du salarié</th>
                    <th rowspan="2" style="width: 6.5%">N° chez<br>l'emp</th>
                    <th colspan="3">Rémunération mensuelle</th>
                    <th rowspan="2" style="width: 11%">TOTAL<br>BRUT</th>
                </tr>
                <tr>
                    <th style="width: 11%">Brut 1</th><th style="width: 9.5%">Brut 2</th><th style="width: 9.5%">Brut 3</th>
                </tr>
            </thead>
            <tbody>
                @foreach($page['lignes'] as $l)
                    <tr>
                        <td class="c">{{ $l['ordre'] }}</td>
                        <td class="c">{{ $l['assure'] }}</td>
                        <td>{{ $l['nom'] }}</td>
                        <td class="c">{{ $l['matricule'] }}</td>
                        @foreach(array_slice($l['brut'], 0, 3) as $v)<td class="n">{{ n3($v) }}</td>@endforeach
                        <td class="n g">{{ n3($l['brut'][3]) }}</td>
                    </tr>
                @endforeach
                @if(! empty($neant))
                    {{-- Déclaration néant : aucun salaire versé ce trimestre --}}
                    <tr><td colspan="8" class="c g" style="font-size: 16pt; letter-spacing: 6pt; height: 60pt">NÉANT</td></tr>
                @endif
                <tr class="total">
                    <td></td><td></td><td class="n g">Total page</td><td></td>
                    @foreach(array_slice($page['total'], 0, 3) as $v)<td class="n">{{ n3($v) }}</td>@endforeach
                    <td class="n g">{{ n3($page['total'][3]) }}</td>
                </tr>
                @if($i + 1 === $nb)
                    <tr class="total">
                        <td colspan="6" class="n g" style="border-right: 0">Totaux</td>
                        <td style="border-left: 0"></td>
                        <td class="n g">{{ n3($totaux[3]) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        @if($i + 1 === $nb)
            <table class="certif">
                <tr>
                    <td style="width: 68%; padding-right: 30pt">Certifié sincère et conforme à nos documents comptables et arrêté à la somme de : {{ $lettres_salaires }}</td>
                    <td>Fait à {{ mb_strtoupper((string) $ville) }} le {{ $date }}<br>Cachet et signature de l'Employeur</td>
                </tr>
            </table>
        @endif

        <div class="pied">Page {{ $i + 1 }} sur {{ $nb }}</div>
    </div>
@endforeach
</body>
</html>
