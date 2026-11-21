<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>État récapitulatif CNSS – {{ $client->nom }} – T{{ $trimestre }} {{ $annee }}</title>
    {{-- Modèle CNSS : état récapitulatif des salaires et appointements, avec l'accusé de réception (A4 portrait). --}}
    <style>
        @page { size: A4 portrait; margin: 10mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Serif', Cambria, Georgia, serif; font-size: 7.5pt; color: #000; background: #fff; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }
        .cadre { border: 1.2px solid #000; }
        .b { border: 1px solid #000; }
        .bt { border-top: 1px solid #000; }
        .bb { border-bottom: 1px solid #000; }
        .bl { border-left: 1px solid #000; }
        .br { border-right: 1px solid #000; }
        .c { text-align: center; }
        .n { text-align: right; white-space: nowrap; }
        .m { vertical-align: middle; }
        .g { font-weight: bold; }
        .p { padding: 4pt 5pt; }
        h1 { font-size: 13pt; text-align: center; margin: 0; padding: 7pt 0; }
        .champs td { padding: 3.5pt 6pt; font-weight: bold; }
        .tiret { border-top: 1.2px dashed #000; }
        .actions { margin: 10px 0; font-family: Arial, sans-serif; }
        .actions button, .actions a { margin-right: 8px; padding: 6px 12px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
@include('paie.cnss._actions')
@php $ville_maj = mb_strtoupper((string) $ville); @endphp
<table class="cadre">
    <tr><td colspan="2" class="bb"><h1>ETAT RECAPITULATIF DES SALAIRES ET APPOINTEMENTS</h1></td></tr>

    {{-- Identification : caisse, trimestre, employeur ; effectifs. --}}
    <tr>
        <td class="br bb" style="width: 52%">
            <table>
                <tr>
                    <td rowspan="2" class="c p br" style="width: 44%; font-size: 6.5pt">République Tunisienne<br>Ministère des Affaires Sociales, de la Solidarité<br><br>
                        <span class="g" style="font-size: 8pt">CAISSE NATIONALE<br>DE SECURITE SOCIALE</span></td>
                    <td class="c g br bb" style="width: 9%; padding: 2pt">TR</td>
                    <td class="c g br bb" style="width: 13%; padding: 2pt">Année</td>
                    <td class="c g br bb" style="width: 28%; padding: 2pt">N° Employeur</td>
                    <td class="c g bb" style="padding: 2pt">BR</td>
                </tr>
                <tr>
                    <td class="c br" style="padding-top: 10pt; height: 52pt">{{ $trimestre }}</td>
                    <td class="c br" style="padding-top: 10pt">{{ $annee }}</td>
                    <td class="c br" style="padding-top: 10pt">{{ $employeur }}<br><span style="font-size: 7.5pt">Code d’exploitation : {{ $code }}</span></td>
                    <td class="c" style="padding-top: 10pt">{{ $bureau }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="bt">
                        <table>
                            <tr>
                                <td class="c g br bb p" style="width: 34%">Permanents</td>
                                <td class="c g br bb p" style="width: 30%">Occasionnels</td>
                                <td class="c g bb p">Nbre Tot. salariés</td>
                            </tr>
                            <tr>
                                <td class="br c m" style="height: 30pt">{{ $permanents }}</td>
                                <td class="br c m">{{ $occasionnels }}</td>
                                <td class="c m">{{ $effectif }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
        <td class="bb p" style="padding: 22pt 8pt 6pt">
            <div class="g" style="font-size: 9pt">{{ mb_strtoupper($client->nom) }}</div><br>
            {{ mb_strtoupper((string) $adresse) }}<br><br>
            {{ mb_strtoupper((string) $activite) }}<br>
            {{ $code_postal }}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{ $ville_maj }}
        </td>
    </tr>

    {{-- Règlement (à remplir à la main) ; cotisations dues. --}}
    <tr>
        <td class="br bb">
            <table class="champs">
                <tr><td>Date d'arrivée :</td></tr>
                <tr><td>Mode de Paiement par :</td></tr>
                <tr><td>Montant :</td></tr>
                <tr><td>Caisse le :</td></tr>
                <tr><td>Chèque N° : <span style="margin-left: 90pt">sur banque</span></td></tr>
                <tr><td>Virement bancaire sur :</td></tr>
                <tr><td>Mandat N° :</td></tr>
            </table>
        </td>
        <td class="bb">
            <table>
                <tr>
                    <td class="c g br bb p m" style="width: 18%">Nature</td>
                    <td class="c g br bb p m" style="width: 32%">Salaires déclarés</td>
                    <td class="c g br bb p m" style="width: 22%">Taux<br>Cotisations</td>
                    <td class="c g bb p m">Montant à payer</td>
                </tr>
                <tr>
                    <td class="c g br bb p m" style="font-size: 5.5pt">Sécurité<br>Sociale</td>
                    <td class="c g br bb p m" style="font-size: 9pt">{{ n3($base) }}</td>
                    <td class="c br bb p m">{{ number_format($taux_ss, 3, ',', ' ') }} &nbsp;%</td>
                    <td class="c bb p m">{{ n3($ss) }}</td>
                </tr>
                <tr>
                    <td class="c g br bb p m" style="font-size: 5.5pt">Accident<br>de travail</td>
                    <td class="c g br bb p m" style="font-size: 9pt">{{ n3($base) }}</td>
                    <td class="c br bb p m">{{ number_format($taux_at, 3, ',', ' ') }} &nbsp;%</td>
                    <td class="c bb p m">{{ n3($at) }}</td>
                </tr>
                <tr>
                    <td colspan="2" class="br bb p" style="height: 26pt"><span class="g">Pénalités</span> <span class="g" style="margin-left: 30pt">Nbre de jours</span></td>
                    <td class="br bb"></td>
                    <td class="bb"></td>
                </tr>
                <tr>
                    <td colspan="3" class="n g p m br" style="height: 26pt">Total à payer</td>
                    <td class="c g p m" style="font-size: 9pt">{{ n3($total) }}</td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td colspan="2" class="bb p" style="height: 40pt">
            <span class="g">La présente déclaration des salaires certifiée sincère et conforme à nos documents comptables est arrêtée à la somme de :</span><br>
            {{ $lettres_total }}
        </td>
    </tr>

    <tr>
        <td class="br c g p" style="height: 78pt; padding-top: 14pt">OBSERVATIONS
            @if(! empty($neant))<div style="font-size: 14pt; letter-spacing: 4pt; margin-top: 16pt">DÉCLARATION NÉANT</div>@endif
        </td>
        <td class="p" style="padding-top: 14pt">
            <span class="g">Fait à :</span>&nbsp; {{ $ville_maj }} <span style="margin-left: 70pt"><b>le</b>&nbsp; {{ $date }}</span>
            <div class="c g" style="margin-top: 22pt">Signature de l'Employeur</div>
        </td>
    </tr>

    {{-- Accusé de réception, rempli par la CNSS. --}}
    <tr><td colspan="2" class="tiret c g bb" style="padding: 8pt 0">ACCUSE DE RECEPTION</td></tr>
    <tr>
        <td class="br">
            <table>
                <tr>
                    <td class="c g br bb p" style="width: 15%">TR</td>
                    <td class="c g br bb p" style="width: 20%">Année</td>
                    <td class="c g br bb p" style="width: 40%">N° Employeur</td>
                    <td class="c g bb p">BR</td>
                </tr>
                <tr>
                    <td class="c br bb m" style="height: 34pt">{{ $trimestre }}</td>
                    <td class="c br bb m">{{ $annee }}</td>
                    <td class="c br bb m">{{ $employeur }}</td>
                    <td class="c bb m">{{ $bureau }}</td>
                </tr>
            </table>
            <table class="champs">
                <tr><td style="padding-top: 12pt">Chèque N° : ........................................................................</td></tr>
                <tr><td>sur .............................................................</td></tr>
                <tr><td>du <span style="margin-left: 100pt">déposé le</span></td></tr>
                <tr><td>Montant ...............................................................................</td></tr>
                <tr><td style="height: 30pt">Ce règlement est effectué sous réserve de toute vérification éventuelle et de tout autre dû</td></tr>
            </table>
        </td>
        <td class="p g" style="padding: 10pt 10pt">
            CAISSE NATIONALE DE SECURITE SOCIALE<br><br><br>
            Bureau Régional : ..............................................<br><br><br>
            <div class="c">le ......................................</div><br><br><br>
            L'agent de contrôle <span style="margin-left: 80pt">Cachet</span>
        </td>
    </tr>
</table>
</body>
</html>
