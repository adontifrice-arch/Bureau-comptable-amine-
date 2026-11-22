{{-- Une page de document RH pour l'employé $e (variables communes : client, k, type, resp, respQ, ville, logo, pdf, d, m). --}}
@php
    $fem = in_array($e->titre, ['Mme', 'Mlle'], true);
    $g = fn ($ma, $fe) => $e->titre ? ($fem ? $fe : $ma) : $ma.'(e)';
    $civ = ['M.' => 'Monsieur', 'Mme' => 'Madame', 'Mlle' => 'Mademoiselle'][$e->titre] ?? '';
    $nom = trim($civ.' '.$e->nomComplet());
    $poste = $e->poste ?: '……………';
    $interesse = $fem ? 'l’intéressée' : 'l’intéressé';
    $adresseEmployeur = trim($client->adresse.' '.trim(($k['code_postal'] ?? '').' '.($k['ville'] ?? '')));
@endphp
<div class="feuille{{ $suite ? ' suite' : '' }}" @unless($pdf) contenteditable="true" spellcheck="false" @endunless>
    @if(! in_array($type, ['demission', 'attestation-cnss'], true))
        <div class="entete">
            <div class="employeur">
                <b>{{ $client->nom }}</b><br>
                {{ $adresseEmployeur }}<br>
                @if($client->matricule_fiscal)Matricule fiscal : {{ $client->matricule_fiscal }}@endif
                @if($client->matricule_cnss) · N° employeur CNSS : {{ $client->matricule_cnss }}@endif
                @if($client->telephone)<br>Tél. : {{ $client->telephone }}@endif
            </div>
            @if($logo)<div style="text-align: right"><img src="{{ $logo }}" alt=""></div>@endif
        </div>
    @endif

    @switch($type)
        @case('attestation-travail')
            <h1>Attestation de travail</h1>
            <p>Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }} de la société <b>{{ $client->nom }}</b>, atteste que
                <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>{{ $e->cin_date ? ' délivrée le '.$d($e->cin_date) : '' }},
                est {{ $g('employé', 'employée') }} au sein de notre société depuis le <b>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</b> en qualité de <b>{{ $poste }}</b>@if($e->type_contrat), dans le cadre d’un contrat {{ \App\Models\Salarie::CONTRATS[$e->type_contrat] ?? $e->type_contrat }}@endif, et qu’{{ $fem ? 'elle' : 'il' }} fait toujours partie de notre personnel à ce jour.</p>
            <p>Cette attestation est délivrée à {{ $interesse }}, sur sa demande, pour servir et valoir ce que de droit.</p>
            @break

        @case('attestation-salaire')
            <h1>Attestation de salaire mensuelle</h1>
            <p>Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }} de la société <b>{{ $client->nom }}</b>, atteste que <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>,
                est {{ $g('employé', 'employée') }} au sein de notre société depuis le <b>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</b> en qualité de <b>{{ $poste }}</b>
                et perçoit, pour le mois de {{ mois_fr($mois) }}, la rémunération suivante :</p>
            <table class="t">
                <tr><th>Salaire brut</th><td class="n">{{ $m($paie['brut'] ?? $e->salaire_base) }} TND</td></tr>
                <tr><th>Retenue CNSS</th><td class="n">{{ $m($paie['cnssS'] ?? 0) }} TND</td></tr>
                <tr><th>Retenue IRPP et CSS</th><td class="n">{{ $m($paie['ras'] ?? 0) }} TND</td></tr>
                <tr><th>Salaire net</th><td class="n"><b>{{ $m($paie['net'] ?? 0) }} TND</b></td></tr>
            </table>
            <p>Cette attestation est délivrée à {{ $interesse }}, sur sa demande, pour servir et valoir ce que de droit.</p>
            @break

        @case('attestation-salaire-annuelle')
            @php
                $brutM = (float) ($paie['brut'] ?? $e->salaire_base);
                $lignes = ['Salaire brut' => $brutM, 'Retenue CNSS' => (float) ($paie['cnssS'] ?? 0), 'Retenue IRPP et CSS' => (float) ($paie['ras'] ?? 0)];
                $netM = $paie ? (float) $paie['net'] + (float) ($paie['acompte'] ?? 0) + (float) ($paie['pret_societe'] ?? 0) + (float) ($paie['pret_cnss'] ?? 0) : null;
            @endphp
            <h1>Attestation de salaire annuelle</h1>
            <p>Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }} de la société <b>{{ $client->nom }}</b>, atteste que <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>,
                est {{ $g('employé', 'employée') }} au sein de notre société depuis le <b>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</b> en qualité de <b>{{ $poste }}</b>
                et perçoit une rémunération annuelle brute de <b>{{ $m($brutM * 12) }} TND</b>, établie sur la base de son dernier salaire mensuel ({{ mois_fr($mois) }}) multiplié par douze :</p>
            <table class="t">
                <tr><th></th><th class="n">Mensuel</th><th class="n">Annuel (× 12)</th></tr>
                @foreach($lignes as $l => $v)<tr><th>{{ $l }}</th><td class="n">{{ $m($v) }}</td><td class="n">{{ $m($v * 12) }}</td></tr>@endforeach
                @if($netM !== null)<tr><th>Salaire net</th><td class="n"><b>{{ $m($netM) }}</b></td><td class="n"><b>{{ $m($netM * 12) }}</b></td></tr>@endif
            </table>
            <p style="font-size: 10pt">Montants en dinars tunisiens. Soit une rémunération annuelle brute de {{ mb_strtolower(montant_lettres($brutM * 12)) }}.</p>
            <p>Cette attestation est délivrée à {{ $interesse }}, sur sa demande, pour servir et valoir ce que de droit.</p>
            @break

        @case('certificat-travail')
            <h1>Certificat de travail</h1>
            <p>Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }} de la société <b>{{ $client->nom }}</b>, certifie que <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>,
                a été {{ $g('employé', 'employée') }} dans notre société du <b>{{ $d($e->date_embauche ?? $e->contrat_debut) }}</b> au <b>{{ $d($e->date_sortie) }}</b> en qualité de <b>{{ $poste }}</b>.</p>
            <p>{{ $fem ? 'Elle' : 'Il' }} nous quitte libre de tout engagement.</p>
            <p>Ce certificat est délivré à {{ $interesse }} pour servir et valoir ce que de droit.</p>
            @break

        @case('certificat-retenue')
            <h1 style="font-size: 14pt">Certificat de retenue d’impôt sur le revenu<br><span style="font-size: 11pt; text-transform: none">Traitements et salaires — année {{ $annee }}</span></h1>
            <div class="bloc"><h3>A. Personne ou organisme payeur</h3>
                Raison sociale : <b>{{ $client->nom }}</b><br>Matricule fiscal : <b>{{ $client->matricule_fiscal ?: '……………' }}</b><br>
                Adresse : {{ $adresseEmployeur }}</div>
            <div class="bloc"><h3>B. Bénéficiaire</h3>
                Nom et prénom : <b>{{ $e->nomComplet() }}</b><br>N° CIN / carte de séjour : <b>{{ $e->cin ?: '……………' }}</b><br>
                Adresse : {{ trim($e->adresse.' '.$e->code_postal.' '.$e->ville) ?: '……………' }}<br>Qualité : {{ $poste }}</div>
            <table class="t">
                <tr><th>Nature des montants payés</th><th class="n">Montant brut</th><th class="n">Retenue CNSS</th><th class="n">Retenue IRPP</th><th class="n">CSS</th><th class="n">Montant net servi</th></tr>
                <tr><td>Traitements et salaires</td><td class="n">{{ $m($cumul['brut']) }}</td><td class="n">{{ $m($cumul['cnssS']) }}</td><td class="n">{{ $m($cumul['ir']) }}</td><td class="n">{{ $m($cumul['css']) }}</td><td class="n">{{ $m($cumul['net']) }}</td></tr>
                <tr><th>Total</th><th class="n">{{ $m($cumul['brut']) }}</th><th class="n">{{ $m($cumul['cnssS']) }}</th><th class="n">{{ $m($cumul['ir']) }}</th><th class="n">{{ $m($cumul['css']) }}</th><th class="n">{{ $m($cumul['net']) }}</th></tr>
            </table>
            <p style="font-size: 10pt">Montants en dinars tunisiens. Je soussigné(e) certifie exacts les renseignements figurant sur le présent certificat et m’expose aux sanctions prévues par la loi pour toute inexactitude.</p>
            @break

        @case('attestation-cnss')
            @php $totalAn = r3(array_sum(array_column($trimestres, 'total'))); @endphp
            <div class="cnss-entete">
                <div><b>RÉPUBLIQUE TUNISIENNE</b><br>Ministère des Affaires Sociales<br><b>Caisse Nationale de Sécurité Sociale</b></div>
                <div style="text-align: right">Année : <b>{{ $annee }}</b></div>
            </div>
            <div class="cnss-titre"><b>ATTESTATION DE SALAIRES</b><br>Salaires déclarés à la Caisse Nationale de Sécurité Sociale</div>
            <table class="grille">
                <tr><td colspan="2" style="background: #dfe7ea; font-weight: bold">I. EMPLOYEUR</td></tr>
                <tr><td class="l">N° d’affiliation de l’employeur</td><td><b>{{ $client->matricule_cnss ?: '……………………' }}</b></td></tr>
                <tr><td class="l">Raison sociale / Nom</td><td><b>{{ $client->nom }}</b></td></tr>
                <tr><td class="l">Adresse</td><td>{{ $adresseEmployeur ?: '……………………' }}</td></tr>
                <tr><td class="l">Activité</td><td>{{ $client->paie_activite ?: ($client->activite ?? '……………………') }}</td></tr>
                <tr><td colspan="2" style="background: #dfe7ea; font-weight: bold">II. ASSURÉ SOCIAL</td></tr>
                <tr><td class="l">Nom et prénom</td><td><b>{{ $e->nomComplet() }}</b>{{ $e->prenom_pere ? ' — fils/fille de '.$e->prenom_pere : '' }}</td></tr>
                <tr><td class="l">N° d’immatriculation CNSS</td><td><b>{{ $e->cnss ?: '……………………' }}</b></td></tr>
                <tr><td class="l">N° CIN / carte de séjour</td><td>{{ $e->cin ?: '……………………' }}</td></tr>
                <tr><td class="l">Date et lieu de naissance</td><td>{{ $d($e->date_naissance) }}{{ $e->lieu_naissance ? ' à '.$e->lieu_naissance : '' }}</td></tr>
                <tr><td class="l">Emploi occupé</td><td>{{ $poste }}</td></tr>
                <tr><td class="l">Date d’entrée / date de sortie</td><td>{{ $d($e->date_embauche ?? $e->contrat_debut) }} / {{ $e->date_sortie ? $d($e->date_sortie) : '—' }}</td></tr>
            </table>
            <div style="background: #dfe7ea; font-weight: bold; font-size: 9.5pt; border: 1px solid #555; border-bottom: 0; padding: 4pt 6pt">III. SALAIRES DÉCLARÉS</div>
            <table class="t" style="margin-top: 0">
                <tr><th class="c">Trimestre</th><th class="c">Mois</th><th class="n">Salaire déclaré</th><th class="n">Total du trimestre</th></tr>
                @foreach($trimestres as $q => $t)
                    @foreach($t['mois'] as $mm => $v)
                        <tr>
                            @if($loop->first)<td class="c" rowspan="{{ count($t['mois']) }}">{{ $q }}{{ $q === 1 ? 'er' : 'e' }} trimestre {{ $annee }}</td>@endif
                            <td style="text-transform: capitalize">{{ mois_fr($mm) }}</td><td class="n">{{ $v ? $m($v) : '—' }}</td>
                            @if($loop->first)<td class="n" rowspan="{{ count($t['mois']) }}"><b>{{ $m($t['total']) }}</b></td>@endif
                        </tr>
                    @endforeach
                @endforeach
                <tr><th colspan="3">Total de l’année {{ $annee }}</th><th class="n">{{ $m($totalAn) }}</th></tr>
            </table>
            <p style="font-size: 10pt">Arrêtée à la somme de : <b>{{ $totalAn > 0 ? montant_lettres($totalAn) : 'NÉANT' }}</b>.</p>
            <p style="font-size: 10pt">Je soussigné(e), <b>{{ $resp }}</b>, {{ $respQ }}, certifie sincères et véritables les renseignements portés sur la présente attestation et que les salaires ci-dessus ont été déclarés à la Caisse Nationale de Sécurité Sociale.</p>
            @break

        @case('recu-vetements')
            <h1>Reçu de vêtements de travail</h1>
            <p>Je soussigné(e), <b>{{ $nom }}</b>, titulaire de la carte d’identité nationale n° <b>{{ $e->cin ?: '……………' }}</b>@if($e->matricule), matricule {{ $e->matricule }}@endif,
                {{ $g('employé', 'employée') }} de la société <b>{{ $client->nom }}</b> en qualité de <b>{{ $poste }}</b>, reconnais avoir reçu ce jour de mon employeur,
                au titre de la dotation de l’année <b>{{ $annee }}</b>, les vêtements et équipements de travail suivants :</p>
            <table class="t">
                <tr><th style="width: 55%">Désignation</th><th class="c" style="width: 12%">Quantité</th><th>Taille / observations</th></tr>
                @foreach(['Tenue de travail (bleu de travail ou blouse)' => 2, 'Chaussures de sécurité' => 1, 'Gants de protection' => '', '' => '', ' ' => ''] as $lib => $q)
                    <tr><td style="height: 16pt">{{ trim($lib) }}</td><td class="c">{{ $q }}</td><td></td></tr>
                @endforeach
            </table>
            <p>Ces vêtements et équipements me sont remis gratuitement, conformément aux dispositions du Code du travail relatives à la santé et à la sécurité au travail et à la convention collective sectorielle applicable.</p>
            <p>Je m’engage à les porter pendant les heures de travail, à les maintenir en bon état et à respecter les consignes d’hygiène et de sécurité de l’entreprise. Ils demeurent affectés à l’usage professionnel et seront restitués à l’employeur lors de leur renouvellement ou à mon départ de l’entreprise, sauf usure normale.</p>
            <p>Fait à {{ $ville }}, le {{ now()->format('d/m/Y') }}, en deux exemplaires.</p>
            <div class="deux"><div><b>Le salarié</b><br><span style="font-size: 9pt">(signature précédée de « Reçu conforme »)</span></div><div style="text-align: right"><b>L’employeur</b><br><span style="font-size: 9pt">{{ $resp }}, {{ $respQ }}</span></div></div>
            <div style="height: 60pt"></div>
            @break

        @case('contrat')
            @php $cdd = $e->type_contrat === 'CDD'; @endphp
            <h1>Contrat de travail {{ $cdd ? 'à durée déterminée' : ($e->type_contrat && $e->type_contrat !== 'CDI' ? '('.(\App\Models\Salarie::CONTRATS[$e->type_contrat] ?? $e->type_contrat).')' : 'à durée indéterminée') }}</h1>
            <p><b>Entre les soussignés :</b></p>
            <p>La société <b>{{ $client->nom }}</b>, {{ \App\Http\Controllers\PaieDossierController::STATUTS[$client->forme] ?? $client->forme }}, matricule fiscal {{ $client->matricule_fiscal ?: '……………' }}, dont le siège est situé {{ $client->adresse }} {{ $k['ville'] ?? '' }},
                représentée par <b>{{ $resp }}</b> en sa qualité de {{ $respQ }}, ci-après dénommée « l’employeur »,</p>
            <p><b>Et :</b></p>
            <p><b>{{ $nom }}</b>, {{ $g('né', 'née') }} le {{ $d($e->date_naissance) }} à {{ $e->lieu_naissance ?: '……………' }}, de nationalité {{ $e->nationalite ?: '……………' }}, titulaire de la CIN n° {{ $e->cin ?: '……………' }},
                demeurant {{ trim($e->adresse.' '.$e->code_postal.' '.$e->ville) ?: '……………' }}, ci-après dénommé(e) « le salarié »,</p>
            <p><b>Il a été convenu ce qui suit :</b></p>
            <div class="article">Article 1 — Engagement et fonctions</div>
            <p>L’employeur engage le salarié en qualité de <b>{{ $poste }}</b>@if($e->categorie), catégorie {{ $e->categorie }}@endif @if($e->echelon), échelon {{ $e->echelon }}@endif, à compter du <b>{{ $d($e->contrat_debut) }}</b>.</p>
            <div class="article">Article 2 — Durée</div>
            <p>@if($cdd) Le présent contrat est conclu pour une durée déterminée, du {{ $d($e->contrat_debut) }} au <b>{{ $d($e->contrat_fin) }}</b>, pour le motif suivant : ……………………………………. @else Le présent contrat est conclu pour une durée indéterminée. @endif
                Il est soumis à une période d’essai de …… mois.</p>
            <div class="article">Article 3 — Rémunération</div>
            <p>En contrepartie de son travail, le salarié perçoit un salaire de base {{ $e->regime === 'horaire' ? 'horaire' : 'mensuel' }} brut de <b>{{ $m($e->salaire_base) }} TND</b>{{ $e->regime === 'horaire' ? ' de l’heure' : '' }}, payable par {{ mb_strtolower(\App\Models\Salarie::MODES[$e->mode_paiement] ?? 'virement') }}, sous déduction des retenues légales.</p>
            <div class="article">Article 4 — Durée du travail</div>
            <p>La durée hebdomadaire de travail est fixée à {{ $k['heures_semaine'] ?? 48 }} heures, selon l’horaire en vigueur dans l’entreprise.</p>
            <div class="article">Article 5 — Congés</div>
            <p>Le salarié bénéficie des congés payés prévus par le Code du travail et la convention collective applicable.</p>
            <div class="article">Article 6 — Dispositions générales</div>
            <p>Pour tout ce qui n’est pas prévu au présent contrat, les parties se réfèrent au Code du travail et à la convention collective sectorielle applicable.</p>
            <p>Fait en deux exemplaires originaux, à {{ $ville }}, le {{ now()->format('d/m/Y') }}.</p>
            <div class="deux"><div><b>Le salarié</b><br><span style="font-size: 9pt">(lu et approuvé)</span></div><div style="text-align: right"><b>L’employeur</b></div></div>
            @break

        @case('demission')
            <div style="margin-bottom: 26pt">{{ $nom }}<br>{{ trim($e->adresse.' '.$e->code_postal.' '.$e->ville) }}<br>@if($e->tel_mobile)Tél. : {{ $e->tel_mobile }}@endif</div>
            <div style="margin-left: 55%; margin-bottom: 26pt">À l’attention de {{ $resp }}<br>{{ $respQ }}<br><b>{{ $client->nom }}</b><br>{{ $client->adresse }} {{ $k['ville'] ?? '' }}<br><br>{{ $ville }}, le {{ now()->format('d/m/Y') }}</div>
            <p><b>Objet : démission</b></p>
            <p>Madame, Monsieur,</p>
            <p>Par la présente, je vous informe de ma décision de démissionner du poste de <b>{{ $poste }}</b> que j’occupe au sein de votre société depuis le {{ $d($e->date_embauche ?? $e->contrat_debut) }}.</p>
            <p>Conformément aux dispositions applicables, j’effectuerai mon préavis ; mon dernier jour de travail sera le <b>{{ $d($e->date_sortie) }}</b>, sauf accord entre nous pour une autre date.</p>
            <p>Je vous saurais gré de bien vouloir me remettre, à l’issue de mon contrat, mon certificat de travail ainsi que mon solde de tout compte.</p>
            <p>Je vous prie d’agréer, Madame, Monsieur, l’expression de mes salutations distinguées.</p>
            <div class="signature"><div class="cadre">{{ $nom }}<div></div></div></div>
            @break
    @endswitch

    @if(! in_array($type, ['contrat', 'demission', 'recu-vetements'], true))
        <p style="margin-top: 22pt">Fait à {{ $ville }}, le {{ now()->format('d/m/Y') }}.</p>
        <div class="signature"><div class="cadre">{{ $resp }}<br>{{ $respQ }}<div></div>{{ $type === 'attestation-cnss' ? 'Cachet et signature de l’employeur' : 'Signature et cachet de l’employeur' }}</div></div>
    @endif
</div>
