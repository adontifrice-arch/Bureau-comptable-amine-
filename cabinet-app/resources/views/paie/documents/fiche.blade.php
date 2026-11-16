{{-- Fiche employé imprimable (bouton « Imprimer » de la fiche). --}}
@php
    $d = fn ($x) => $x ? $x->format('d/m/Y') : '—';
    $val = fn ($x) => ($x === null || $x === '') ? '—' : $x;
    $photo = $e->documents->firstWhere('type', 'photo');
    $enfants = array_values((array) $e->enfants_detail);
    $sections = [
        'État civil' => [
            'Adresse' => trim($e->adresse.' '.$e->code_postal.' '.$e->ville), 'Téléphone' => trim($e->tel_fixe.' '.$e->tel_mobile),
            'N° CIN / carte de séjour' => $e->cin.($e->carte_sejour ? ' (carte de séjour)' : ''), 'Date CIN' => $d($e->cin_date),
            'Date et lieu de naissance' => $d($e->date_naissance).($e->lieu_naissance ? ' à '.$e->lieu_naissance : ''), 'Nationalité' => $e->nationalite,
            'Situation familiale' => \App\Models\Salarie::SITUATIONS[$e->situation_familiale] ?? null, 'Chef de famille' => $e->chef_famille ? 'Oui' : 'Non',
            'Enfants à charge' => $e->enfants.($enfants ? ' ('.collect($enfants)->map(fn ($x, $i) => ($i + 1).' : '.(! empty($x['infirme']) ? 'infirme' : (! empty($x['etudiant']) ? 'étudiant' : 'à charge')))->implode(', ').')' : ''),
            'Parents à charge' => $e->parents_charge,
        ],
        'Informations spécifiques' => [
            'Qualification' => $e->poste, 'Régime salarial' => $e->regime === 'horaire' ? 'Horaire' : 'Mensuel',
            ($e->regime === 'horaire' ? 'Taux horaire' : 'Salaire de base') => number_format((float) $e->salaire_base, 3, ',', ' ').' TND',
            'Acquis congé / mois' => $e->conges_acquis !== null ? rtrim(rtrim(number_format((float) $e->conges_acquis, 2, ',', ''), '0'), ',').($e->regime === 'horaire' ? ' h' : ' j') : null,
            'Catégorie' => trim($e->categorie.' '.($e->categorie_debut ? '(du '.$d($e->categorie_debut).($e->categorie_fin ? ' au '.$d($e->categorie_fin) : '').')' : '')),
            'Échelon' => trim($e->echelon.' '.($e->echelon_debut ? '(du '.$d($e->echelon_debut).($e->echelon_fin ? ' au '.$d($e->echelon_fin) : '').')' : '')),
            'Profil' => trim($e->profil.' '.($e->profil_debut ? '(du '.$d($e->profil_debut).($e->profil_fin ? ' au '.$d($e->profil_fin) : '').')' : '')),
            'N° CNSS' => $e->cnss.($e->cnss_assujetti ? '' : ' (non assujetti)'), 'Nombre de bulletins' => $e->nb_bulletin,
            'Déductions annuelles' => collect(['Assurance vie' => $e->assurance_vie, 'CEA' => $e->cea, 'Intérêts bancaires' => $e->interets_bancaires])->filter(fn ($v) => (float) $v > 0)->map(fn ($v, $l) => $l.' '.number_format((float) $v, 3, ',', ' '))->implode(' · '),
        ],
        'Entrée / sortie' => [
            'Contrat' => \App\Models\Salarie::CONTRATS[$e->type_contrat] ?? $e->type_contrat, 'Début du contrat' => $d($e->contrat_debut), 'Fin du contrat' => $d($e->contrat_fin),
            'Département' => $e->departement, 'État de présence' => \App\Models\Salarie::ETATS[$e->etat_presence] ?? null,
            'Sortie' => $e->date_sortie ? $d($e->date_sortie).($e->motif_sortie ? ' — '.(\App\Models\Salarie::MOTIFS_SORTIE[$e->motif_sortie] ?? '') : '') : null,
            'Date d’embauche' => $d($e->date_embauche), '1re déclaration CNSS' => $d($e->date_declaration_cnss),
        ],
        'Règlement' => ['Mode' => \App\Models\Salarie::MODES[$e->mode_paiement] ?? null, 'Banque' => trim($e->banque.' '.($e->agence ? '— '.$e->agence : '')), 'RIB' => $e->rib],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche employé — {{ $e->nomComplet() }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5pt; color: #111; margin: 0; }
        .feuille { max-width: 185mm; margin: 0 auto; padding: 6mm 0; }
        .entete { display: table; width: 100%; border-bottom: 2px solid #1f4e5f; padding-bottom: 6pt; margin-bottom: 10pt; }
        .entete > div { display: table-cell; vertical-align: top; }
        h1 { font-size: 15pt; margin: 0; }
        h2 { font-size: 10.5pt; background: #e8eef0; padding: 3pt 6pt; margin: 12pt 0 4pt; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2.5pt 6pt; border-bottom: 1px solid #e2e2e2; vertical-align: top; }
        td.l { width: 34%; color: #555; }
        .photo { width: 80pt; height: 95pt; object-fit: cover; border: 1px solid #ccc; }
        .actions { margin: 10px; font-family: Arial, sans-serif; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Imprimer</button></div>
<div class="feuille">
    <div class="entete">
        <div>
            <div style="color: #555">{{ $client->nom }}</div>
            <h1>Fiche employé</h1>
            <div style="font-size: 12pt; margin-top: 4pt"><b>{{ trim($e->titre.' '.$e->nomComplet()) }}</b></div>
            <div>Matricule : <b>{{ $e->matricule }}</b>@if($e->matricule_pointeuse) · pointeuse : {{ $e->matricule_pointeuse }}@endif</div>
        </div>
        @if($photo)<div style="text-align: right"><img class="photo" src="{{ route('paie.employes.piece', $photo) }}" alt=""></div>@endif
    </div>
    @foreach($sections as $titre => $lignes)
        <h2>{{ $titre }}</h2>
        <table>@foreach($lignes as $l => $x)<tr><td class="l">{{ $l }}</td><td>{{ $val($x) }}</td></tr>@endforeach</table>
    @endforeach
    @if($e->documents->whereIn('type', ['contrat', 'diplome'])->isNotEmpty())
        <h2>Pièces jointes</h2>
        <table>@foreach($e->documents->whereIn('type', ['contrat', 'diplome']) as $p)<tr><td class="l">{{ \App\Models\SalarieDocument::TYPES[$p->type] }}</td><td>{{ $p->nom }}</td></tr>@endforeach</table>
    @endif
    @if($e->historique->isNotEmpty())
        <h2>Changements de situation</h2>
        <table>@foreach($e->historique->take(25) as $h)<tr><td class="l">{{ $h->created_at->format('d/m/Y') }} — {{ $h->libelle }}</td><td>{{ $h->ancien ?? '—' }} → {{ $h->nouveau ?? '—' }}</td></tr>@endforeach</table>
    @endif
    <p style="margin-top: 14pt; color: #777; font-size: 8pt">Édité le {{ now()->format('d/m/Y à H:i') }}</p>
</div>
</body>
</html>
