{{-- Barre de menus et barre d'éditions du module Paie. Les entrées « bientôt » seront livrées au fil des étapes. --}}
@php
    $lien = fn ($t, $extra = []) => route('paie.index', ['tab' => $t, 'mois' => $mois] + $extra);
    $ed = fn ($e, $f = 'imprimer') => route('paie.edition', [$e, $f, 'mois' => $mois]);
    $bul = fn ($f) => route('paie.bulletins', ['mois' => $mois, 'format' => $f]);
    $peutSauver = $tab === 'calc' && ! $lock && count($R);
    $chef = auth()->user()->estChef();
    // Fichier de virement au modèle de la banque de l'employeur, pour la paie $extra (mois, paie).
    $fichiersBanque = fn (array $extra) => collect(\App\Services\Exports\FichiersVirement::MODELES)
        ->map(fn ($m, $k) => ['l' => $m['nom'], 'i' => 'download', 'href' => route('paie.virement.banque', [$k] + $extra)])->values()->all();
    $reserve = 'Réservé au chef comptable';
    // Menus Prime et Rappel : saisie, ouverture et éditions de la paie séparée ouverte ($po).
    $menuSepare = function (?\App\Models\Paie $po, array $t) use ($mois, $fichiersBanque) {
        $sans = $t['sans'];
        $ed = fn ($e, $extra = [], $f = 'imprimer') => $po ? ['href' => route('paie.edition', [$e, $f, 'mois' => $po->mois, 'paie' => $po->id] + $extra), 'blank' => true] : ['off' => $sans];
        $bul = fn ($f) => $po ? ['href' => route('paie.bulletins', ['paie' => $po->id, 'format' => $f]), 'blank' => true] : ['off' => $sans];
        $lien = fn ($route) => $po ? ['href' => route($route, ['mois' => $po->mois, 'paie' => $po->id]), 'blank' => true] : ['off' => $sans];
        return [
            ['l' => $t['saisie'], 'i' => 'users', 'href' => route('paie.index', ['tab' => $t['tab'], $t['nouveau'] => 1, 'mois' => $mois])],
            ['l' => $t['ouvrir'], 'i' => 'users', 'modal' => $t['modal']],
            ...($po ? [['l' => $t['ouverte'].' : '.$po->nomType().' ('.mois_fr($po->mois).')', 'i' => 'check', 'href' => route('paie.index', ['tab' => $t['tab'], $t['param'] => $po->id, 'mois' => $mois])]] : []),
            ['sep' => true],
            ['l' => 'Bulletin', 'i' => 'file-text', 'sub' => [
                ['l' => 'A4', 'i' => 'settings'] + $bul('a4'), ['l' => 'A5 Double', 'i' => 'settings'] + $bul('a5d'),
                ['l' => 'Matricielle', 'i' => 'settings', 'sub' => [['l' => 'Bulletin 80 colonnes', 'i' => 'settings'] + $bul('mat')]],
                ['l' => 'A5 Double CIN', 'i' => 'settings'] + $bul('a5cin'),
            ]],
            ['l' => 'Journal', 'i' => 'book', 'sub' => collect(\App\Services\Exports\EtatsPaie::JOURNAUX)->except('variable')->map(fn ($l, $m) => ['l' => $l, 'i' => 'book', 'sub' =>
                collect(\App\Services\Exports\EtatsPaie::GROUPES)->map(fn ($gl, $g) => ['l' => $gl, 'i' => 'settings'] + $ed('journal', ['modele' => $m, 'groupe' => $g]))->values()->all()])->values()->all()],
            ['l' => 'Bordereau payeur', 'i' => 'list', 'sub' => [
                ['l' => 'Tous', 'i' => 'settings'] + $ed('bordereau'), ['l' => 'Espèce', 'i' => 'settings'] + $ed('bordereau', ['mode' => 'especes']),
                ['l' => 'Département', 'i' => 'settings'] + $ed('bordereau', ['groupe' => 'departement']),
            ]],
            ['l' => 'Ordre de virement', 'i' => 'bank', 'sub' => [
                ['l' => 'Intégral', 'i' => 'settings'] + $ed('virement'),
                ['l' => 'Récapitulatif de virement', 'i' => 'settings', 'sub' => collect(\App\Services\Exports\EtatsPaie::BANQUES)->map(fn ($b, $k) => ['l' => $b['nom'], 'i' => 'bank'] + $ed('virement', ['banque' => $k]))->values()->all()],
                ['l' => 'Par Employé', 'i' => 'settings'] + $lien('paie.virement.employes'),
                ['l' => 'Fichier de virement .txt', 'i' => 'download'] + ($po ? ['href' => route('paie.virement.fichier', ['txt', 'mois' => $po->mois, 'paie' => $po->id])] : ['off' => $sans]),
                ['l' => 'Fichier pour la banque', 'i' => 'bank'] + ($po ? ['sub' => $fichiersBanque(['mois' => $po->mois, 'paie' => $po->id])] : ['off' => $sans]),
            ]],
            ['l' => $t['enveloppe'], 'i' => 'receipt'] + $lien('paie.enveloppes'),
            ['l' => $t['recap'], 'i' => 'list'] + $ed('recap'),
            ...($t['extra'] ?? fn () => [])($po, $sans),
        ];
    };
    $menus = [
        'Fichier' => ['settings', [
            ['l' => 'Ouvrir dossier', 'i' => 'folder', 'modal' => 'paie-ouvrir'],
            ['l' => 'Nouveau dossier', 'i' => 'plus', 'href' => route('clients.create')],
            ['l' => 'Sauvegarde', 'i' => 'download', 'href' => route('paie.sauvegarde')],
            ['l' => 'Mettre à jour le dossier', 'i' => 'copy', 'post' => route('paie.mettre-a-jour'), 'confirm' => 'Recalculer les paies en brouillon avec les taux et le barème en vigueur ? Les paies clôturées ne changent pas.'],
            ['l' => 'Renseignements employeur', 'i' => 'briefcase', 'modal' => 'paie-employeur'],
            ['l' => 'Importation', 'i' => 'upload', 'sub' => [
                ['l' => 'Liste des employés', 'i' => 'upload', 'modal' => 'paie-import'],
                ['l' => 'Historique du pointage de la paie', 'i' => 'upload', 'modal' => 'paie-import-historique'],
                ['l' => 'Pointage mensuel de la paie', 'i' => 'upload', 'modal' => 'paie-import-pointage'],
                ['l' => 'Modèle de paie', 'i' => 'eye', 'href' => route('paie.import.modele-paie', ['mois' => $mois])],
                ['l' => 'Modèle du personnel', 'i' => 'eye', 'href' => route('paie.import.modele')],
                ['sep' => true],
                ['l' => 'Restaurer une sauvegarde', 'i' => 'upload', 'modal' => 'paie-restaurer', 'off' => $chef ? null : $reserve],
            ]],
            ['l' => 'Exportation', 'i' => 'download', 'modal' => 'paie-export'],
            ['l' => 'Sécurité', 'i' => 'lock', 'sub' => [
                ['l' => 'Rouvrir une paie clôturée', 'i' => 'lock', 'modal' => 'paie-rouvrir', 'off' => $chef ? null : $reserve],
                ['l' => 'Journal des opérations de paie', 'i' => 'list', 'modal' => 'paie-journal'],
                ['l' => 'Utilisateurs et droits', 'i' => 'users', 'href' => route('parametres.index', ['tab' => 'users']), 'off' => $chef ? null : $reserve],
                ['l' => 'Changer mon mot de passe', 'i' => 'lock', 'href' => route('parametres.index', ['tab' => 'compte'])],
            ]],
            ['l' => 'Notifications', 'i' => 'alert', 'modal' => 'paie-notifications', 'badge' => $nbNotifs ?: null],
            ['l' => 'Mettre à jour l’application', 'i' => 'download', 'off' => 'Les mises à jour sont installées par le cabinet'],
            ['l' => 'Calculatrice', 'i' => 'scale', 'modal' => 'paie-calculatrice'],
            ['l' => 'Chemin du dossier', 'i' => 'book', 'modal' => 'paie-chemin'],
            ['sep' => true],
            ['l' => 'Paramètres de paie (taux, barème IRPP)', 'i' => 'settings', 'href' => $lien('par')],
        ]],
        'Employé' => ['users', (function () use ($mois) {
            $et = fn ($etat, $statut = 'tous') => route('paie.employes.etat', [$etat, 'imprimer', 'statut' => $statut, 'mois' => $mois]);
            $groupes = fn ($statut) => [
                ['l' => 'Tous', 'i' => 'users', 'href' => $et('liste', $statut), 'blank' => true],
                ['l' => 'Par salarié', 'i' => 'settings', 'href' => $et('par-salarie', $statut), 'blank' => true],
                ['l' => 'Par département', 'i' => 'settings', 'href' => $et('par-departement', $statut), 'blank' => true],
                ['l' => 'Par année', 'i' => 'settings', 'href' => $et('par-annee', $statut), 'blank' => true],
                ['l' => 'Par profil', 'i' => 'settings', 'href' => $et('par-profil', $statut), 'blank' => true],
            ];
            $doc = fn ($cle, $l) => ['l' => $l, 'i' => 'file-text', 'modal' => 'paie-rh-'.$cle];
            return [
                ['l' => 'Nouvel employé', 'i' => 'plus', 'href' => route('paie.employes.create')],
                ['l' => 'Afficher employé', 'i' => 'eye', 'modal' => 'paie-afficher'],
                ['l' => 'Liste des employés', 'i' => 'users', 'sub' => [
                    ['l' => 'Tous', 'i' => 'settings', 'href' => $et('liste'), 'blank' => true],
                    ['l' => 'Actifs', 'i' => 'settings', 'sub' => $groupes('actifs')],
                    ['l' => 'Inactifs', 'i' => 'settings', 'sub' => $groupes('inactifs')],
                    ...array_slice($groupes('tous'), 1),
                ]],
                ['l' => 'Historique des employés', 'i' => 'users', 'sub' => [
                    ['l' => 'Entrées de l’année '.substr($mois, 0, 4), 'i' => 'plus', 'href' => $et('entrees'), 'blank' => true],
                    ['l' => 'Sorties de l’année '.substr($mois, 0, 4), 'i' => 'logout', 'href' => $et('sorties'), 'blank' => true],
                ]],
                ['l' => 'Historique des modifications', 'i' => 'clock', 'href' => $et('modifications'), 'blank' => true],
                ['l' => 'État des contrats', 'i' => 'users', 'href' => $et('contrats'), 'blank' => true],
                ['l' => 'État des profils', 'i' => 'users', 'href' => $et('profils'), 'blank' => true],
                ['l' => 'État des catégories et échelons', 'i' => 'users', 'href' => $et('categories-echelons'), 'blank' => true],
                ['l' => 'État historique contrat', 'i' => 'users', 'href' => $et('historique-contrat'), 'blank' => true],
                ['sep' => true],
                $doc('attestation-travail', 'Attestation de travail'),
                $doc('attestation-salaire', 'Attestation de salaire'),
                $doc('certificat-travail', 'Certificat de travail'),
                $doc('certificat-retenue', 'Certificat de retenue'),
                $doc('certificat-retenue-pdf', 'Certificat de retenue PDF'),
                $doc('contrat', 'Contrat de travail'),
                $doc('demission', 'Démission'),
            ];
        })()],
        'Profil' => ['briefcase', [
            ['l' => 'Liste des profils', 'i' => 'briefcase', 'href' => route('paie.profils', ['mois' => $mois])],
            ['l' => 'Nouveau profil', 'i' => 'plus', 'href' => route('paie.profils', ['mois' => $mois, 'nouveau' => 1])],
            ['sep' => true],
            ['l' => 'Liste des employés par profil', 'i' => 'users', 'href' => route('paie.employes.etat', ['par-profil', 'imprimer', 'mois' => $mois]), 'blank' => true],
            ['l' => 'État des profils', 'i' => 'users', 'href' => route('paie.employes.etat', ['profils', 'imprimer', 'mois' => $mois]), 'blank' => true],
        ]],
        'Rubrique' => ['list', [
            ['l' => 'Liste des rubriques', 'i' => 'list', 'href' => route('paie.rubriques', ['mois' => $mois])],
            ['sep' => true],
            ...collect(\App\Models\PaieRubrique::CATEGORIES)->map(fn ($l, $c) => ['l' => 'Ajouter : '.mb_strtolower($l), 'i' => 'plus', 'href' => route('paie.rubriques', ['categorie' => $c, 'mois' => $mois])])->values()->all(),
            ['sep' => true],
            ['l' => 'Saisie des rubriques du mois', 'i' => 'wallet', 'href' => $lien('variable')],
        ]],
        'Paie' => ['wallet', (function () use ($mois, $lien, $ed, $bul, $fichiersBanque) {
            $formats = fn ($e, $t = 'Imprimer') => [
                ['l' => $t, 'i' => 'printer', 'href' => $ed($e), 'blank' => true],
                ['l' => 'PDF', 'i' => 'file-text', 'href' => $ed($e, 'pdf'), 'blank' => true],
                ['l' => 'Excel', 'i' => 'download', 'href' => $ed($e, 'xlsx')],
                ['l' => 'Word', 'i' => 'download', 'href' => $ed($e, 'docx')],
            ];
            $bord = fn ($extra) => route('paie.edition', ['bordereau', 'imprimer', 'mois' => $mois] + $extra);
            // Bulletin : chaque critère propose les modes d'impression ; « Tous les bulletins » s'ouvre directement, les autres critères
            // passent par la fenêtre de choix (employé, régime, département, profil, année ou période).
            $modes = fn (callable $item) => [
                $item('A4', 'a4'),
                $item('A5 Double', 'a5d'),
                ['l' => 'Matricielle', 'i' => 'settings', 'sub' => [$item('Bulletin 80 colonnes', 'mat')]],
                $item('A5 Double en jrs', 'a5j'),
                $item('A5 Double reste congé', 'a5c'),
                $item('A5 Double CIN', 'a5cin'),
            ];
            $choix = fn ($critere) => $modes(fn ($l, $f) => ['l' => $l, 'i' => 'settings', 'modal' => 'paie-bulletins-choix', 'detail' => ['critere' => $critere, 'format' => $f]]);
            $tous = $modes(fn ($l, $f) => ['l' => $l, 'i' => 'settings', 'href' => $bul($f), 'blank' => true]);
            $vir = fn ($f, $extra = []) => route('paie.virement.fichier', [$f, 'mois' => $mois] + $extra);
            $banques = collect(\App\Services\Exports\EtatsPaie::BANQUES)->map(fn ($b, $k) => $b['formats']
                ? ['l' => $b['nom'], 'i' => 'bank', 'sub' => [
                    ['l' => 'Récapitulatif imprimé', 'i' => 'printer', 'href' => route('paie.edition', ['virement', 'imprimer', 'mois' => $mois, 'banque' => $k]), 'blank' => true],
                    ...array_map(fn ($f) => ['l' => '.'.$f, 'i' => $f === 'txt' ? 'file-text' : 'download', 'href' => $vir($f, ['banque' => $k])], $b['formats'])]]
                : ['l' => $b['nom'], 'i' => 'bank', 'href' => route('paie.edition', ['virement', 'imprimer', 'mois' => $mois, 'banque' => $k]), 'blank' => true])->values()->all();
            $journal = fn ($modele) => collect(\App\Services\Exports\EtatsPaie::GROUPES)->map(fn ($l, $g) => ['l' => $l, 'i' => 'settings', 'blank' => true,
                'href' => route('paie.edition', ['journal', 'imprimer', 'mois' => $mois, 'modele' => $modele, 'groupe' => $g])])->values()->all();
            return [
                ['l' => 'Saisie Paie', 'i' => 'wallet', 'href' => $lien('calc')],
                ['l' => 'Saisie Autre', 'i' => 'list', 'href' => $lien('autre')],
                ['l' => 'Bulletin', 'i' => 'file-text', 'sub' => [
                    ['l' => 'Tous les bulletins', 'i' => 'file-text', 'sub' => $tous],
                    ['l' => 'Par matricule', 'i' => 'file-text', 'sub' => $choix('matricule')],
                    ['l' => 'Par Nom et Prénom', 'i' => 'file-text', 'sub' => $choix('nom')],
                    ['l' => 'Par régime salarial', 'i' => 'file-text', 'sub' => $choix('regime')],
                    ['l' => 'Par Nom/ Année', 'i' => 'file-text', 'sub' => $choix('nom-annee')],
                    ['l' => 'Par département', 'i' => 'file-text', 'sub' => $choix('departement')],
                    ['l' => 'Par profil', 'i' => 'file-text', 'sub' => $choix('profil')],
                    ['l' => 'Par période', 'i' => 'file-text', 'sub' => $choix('periode')],
                    ['l' => 'Générer les bulletins', 'i' => 'download', 'sub' => $choix('generer')],
                    ['l' => 'Générer les bulletins CIN', 'i' => 'download', 'sub' => $choix('generer-cin')],
                ]],
                ['l' => 'Journal', 'i' => 'book', 'sub' => [
                    ...collect(\App\Services\Exports\EtatsPaie::JOURNAUX)->map(fn ($l, $m) => ['l' => $l, 'i' => 'book', 'sub' => $journal($m)])->values()->all(),
                    ['sep' => true],
                    ['l' => 'Récapitulatif annuel des paies', 'i' => 'book', 'sub' => $formats('annuel')],
                ]],
                ['l' => 'Bordereau payeur', 'i' => 'list', 'sub' => [
                    ['l' => 'Tous', 'i' => 'settings', 'href' => $bord([]), 'blank' => true],
                    ['l' => 'Espèce', 'i' => 'settings', 'href' => $bord(['mode' => 'especes']), 'blank' => true],
                    ['l' => 'Département', 'i' => 'settings', 'href' => $bord(['groupe' => 'departement']), 'blank' => true],
                ]],
                ['l' => 'Ordre de virement', 'i' => 'bank', 'sub' => [
                    ['l' => 'Intégral', 'i' => 'settings', 'href' => $ed('virement'), 'blank' => true],
                    ['l' => 'Récapitulatif de virement', 'i' => 'settings', 'sub' => $banques],
                    ['l' => 'Par Employé', 'i' => 'settings', 'href' => route('paie.virement.employes', ['mois' => $mois]), 'blank' => true],
                    ['l' => 'Fichier pour la banque', 'i' => 'bank', 'sub' => $fichiersBanque(['mois' => $mois])],
                ]],
                ['l' => 'Enveloppe Paie', 'i' => 'receipt', 'href' => route('paie.enveloppes', ['mois' => $mois]), 'blank' => true],
                ['l' => 'Récapitulation de paie', 'i' => 'list', 'href' => $ed('recap'), 'blank' => true],
                ['l' => 'Etat vierge de saisie', 'i' => 'file-text', 'href' => $ed('vierge'), 'blank' => true],
                ['l' => 'Éditions des paies spéciales', 'i' => 'sparkles', 'sub' => [
                    ['l' => 'Paie du mois et paies spéciales déclarées avec '.mois_fr($mois), 'i' => 'alert', 'off' => 'Choisissez une édition ci-dessous'],
                    ['l' => 'Journal', 'i' => 'book', 'href' => route('paie.edition', ['journal', 'imprimer', 'mois' => $mois, 'paie' => 'tous']), 'blank' => true],
                    ['l' => 'Journal détaillé', 'i' => 'book', 'href' => route('paie.edition', ['journal', 'imprimer', 'mois' => $mois, 'paie' => 'tous', 'modele' => 'detaille']), 'blank' => true],
                    ['l' => 'Bordereau payeur', 'i' => 'list', 'href' => route('paie.edition', ['bordereau', 'imprimer', 'mois' => $mois, 'paie' => 'tous']), 'blank' => true],
                    ['l' => 'Ordre de virement', 'i' => 'bank', 'href' => route('paie.edition', ['virement', 'imprimer', 'mois' => $mois, 'paie' => 'tous']), 'blank' => true],
                    ['l' => 'Enveloppes', 'i' => 'receipt', 'href' => route('paie.enveloppes', ['mois' => $mois, 'paie' => 'tous']), 'blank' => true],
                    ['l' => 'Récapitulation de paie', 'i' => 'list', 'href' => route('paie.edition', ['recap', 'imprimer', 'mois' => $mois, 'paie' => 'tous']), 'blank' => true],
                    ['sep' => true],
                    ['l' => 'Une paie spéciale (Saisie Autre)…', 'i' => 'sparkles', 'href' => $lien('autre')],
                ]],
                ['sep' => true],
                ['l' => 'Historique des paies', 'i' => 'clock', 'href' => $lien('hist')],
            ];
        })()],
        // Prime et Rappel : paies séparées ; les éditions portent sur la prime ou le rappel ouvert (Ouvrir … Saisie).
        'Prime' => ['sparkles', $menuSepare($primeOuverte, ['tab' => 'prime', 'param' => 'prime', 'nouveau' => 'nouvelle', 'saisie' => 'Saisie Prime', 'ouvrir' => 'Ouvrir Prime Saisie',
            'modal' => 'paie-ouvrir-prime', 'ouverte' => 'Prime ouverte', 'sans' => 'Ouvrez d’abord une prime saisie (Ouvrir Prime Saisie)', 'enveloppe' => 'Enveloppe Prime', 'recap' => 'Récapitulation de Prime'])],
        'Rappel' => ['clock', $menuSepare($rappelOuvert, ['tab' => 'rappel', 'param' => 'rappel', 'nouveau' => 'nouveau', 'saisie' => 'Saisie Rappel', 'ouvrir' => 'Ouvrir rappel Saisi',
            'modal' => 'paie-ouvrir-rappel', 'ouverte' => 'Rappel ouvert', 'sans' => 'Ouvrez d’abord un rappel saisi (Ouvrir rappel Saisi)', 'enveloppe' => 'Enveloppe Rappel', 'recap' => 'Récapitulation de rappel'])],
        'Solde de tout compte' => ['receipt', $menuSepare($stcOuvert, ['tab' => 'stc', 'param' => 'stc', 'nouveau' => 'nouveau', 'saisie' => 'Saisie Solde de tout compte', 'ouvrir' => 'Ouvrir STC Saisi',
            'modal' => 'paie-ouvrir-stc', 'ouverte' => 'STC ouvert', 'sans' => 'Ouvrez d’abord un solde de tout compte saisi (Ouvrir STC Saisi)', 'enveloppe' => 'Enveloppe STC', 'recap' => 'Récapitulation STC',
            'extra' => fn ($po, $sans) => [['l' => 'Quittance de Solde de tout Compte', 'i' => 'file-text', 'sub' => [
                ['l' => 'Reçu pour solde de tout compte', 'i' => 'settings'] + ($po ? ['href' => route('paie.stc.quittance', $po), 'blank' => true] : ['off' => $sans]),
            ]]]])],
        'Congé' => ['calendar', [
            ['l' => 'Saisie congé antérieur', 'i' => 'users', 'href' => $lien('conges-ant')],
            ['l' => 'Saisie des congés', 'i' => 'users', 'href' => $lien('conges')],
            ['l' => 'Etat des Congés', 'i' => 'file-text', 'sub' => collect(\App\Services\Exports\EtatsPaie::CONGES)->map(fn ($l, $k) => ['l' => $l, 'i' => 'settings', 'sub' => [
                ['l' => 'Imprimer', 'i' => 'printer', 'href' => $ed($k), 'blank' => true],
                ['l' => 'PDF', 'i' => 'file-text', 'href' => $ed($k, 'pdf'), 'blank' => true],
                ['l' => 'Excel', 'i' => 'download', 'href' => $ed($k, 'xlsx')],
            ]])->values()->all()],
        ]],
        'Acompte' => ['bank', [
            ['l' => 'Saisie des acomptes', 'i' => 'upload', 'href' => $lien('acomptes')],
            ['l' => 'Etat des acomptes', 'i' => 'file-text', 'sub' => collect(\App\Services\Exports\EtatsPaie::ACOMPTES)->map(fn ($l, $k) => ['l' => $l, 'i' => 'settings', 'sub' => [
                ['l' => 'Imprimer', 'i' => 'printer', 'href' => $ed($k), 'blank' => true],
                ['l' => 'PDF', 'i' => 'file-text', 'href' => $ed($k, 'pdf'), 'blank' => true],
                ['l' => 'Excel', 'i' => 'download', 'href' => $ed($k, 'xlsx')],
            ]])->values()->all()],
        ]],
        'Prêt' => ['scale', collect(\App\Models\Pret::TYPES)->map(fn ($l, $k) => ['l' => $l, 'i' => 'upload', 'sub' => [
            ['l' => 'Saisie des prêts', 'i' => 'users', 'href' => $lien('prets', ['type' => $k])],
            ...collect(\App\Services\Exports\EtatsPaie::PRETS)->map(fn ($el, $e) => ['l' => $el, 'i' => 'file-text', 'sub' => [
                ['l' => 'Imprimer', 'i' => 'printer', 'href' => route('paie.edition', [$e, 'imprimer', 'mois' => $mois, 'type' => $k]), 'blank' => true],
                ['l' => 'PDF', 'i' => 'file-text', 'href' => route('paie.edition', [$e, 'pdf', 'mois' => $mois, 'type' => $k]), 'blank' => true],
                ['l' => 'Excel', 'i' => 'download', 'href' => route('paie.edition', [$e, 'xlsx', 'mois' => $mois, 'type' => $k])],
            ]])->values()->all(),
        ]])->values()->all()],
        // Edition : éditions « Global » (paie du mois, primes, paies Autre, rappels et STC du mois), déclarations, envoi par mail.
        'Edition' => ['printer', (function () use ($mois, $fichiersBanque) {
            $G = fn ($e, $extra = [], $f = 'imprimer') => route('paie.edition', [$e, $f, 'mois' => $mois] + $extra);
            $fmt = fn ($e, $extra = []) => [
                ['l' => 'Imprimer', 'i' => 'printer', 'href' => $G($e, $extra), 'blank' => true],
                ['l' => 'PDF', 'i' => 'file-text', 'href' => $G($e, $extra, 'pdf'), 'blank' => true],
                ['l' => 'Excel', 'i' => 'download', 'href' => $G($e, $extra, 'xlsx')],
                ['l' => 'Word', 'i' => 'download', 'href' => $G($e, $extra, 'docx')],
            ];
            $tous = ['paie' => 'tous'];
            return [
                ['l' => 'Journal Global', 'i' => 'book', 'sub' => collect(\App\Services\Exports\EtatsPaie::JOURNAUX)->map(fn ($l, $m) => ['l' => $l, 'i' => 'book', 'sub' =>
                    collect(\App\Services\Exports\EtatsPaie::GROUPES)->map(fn ($gl, $g) => ['l' => $gl, 'i' => 'settings', 'href' => $G('journal', $tous + ['modele' => $m, 'groupe' => $g]), 'blank' => true])->values()->all()])->values()->all()],
                ['l' => 'Bordereau payeur Global', 'i' => 'list', 'sub' => [
                    ['l' => 'Tous', 'i' => 'settings', 'href' => $G('bordereau', $tous), 'blank' => true],
                    ['l' => 'Espèce', 'i' => 'settings', 'href' => $G('bordereau', $tous + ['mode' => 'especes']), 'blank' => true],
                    ['l' => 'Département', 'i' => 'settings', 'href' => $G('bordereau', $tous + ['groupe' => 'departement']), 'blank' => true],
                ]],
                ['l' => 'Ordre de virement Global', 'i' => 'bank', 'sub' => [
                    ['l' => 'Intégral', 'i' => 'settings', 'href' => $G('virement', $tous), 'blank' => true],
                    ['l' => 'Récapitulatif de virement', 'i' => 'settings', 'sub' => collect(\App\Services\Exports\EtatsPaie::BANQUES)->map(fn ($b, $k) => ['l' => $b['nom'], 'i' => 'bank', 'href' => $G('virement', $tous + ['banque' => $k]), 'blank' => true])->values()->all()],
                    ['l' => 'Par Employé', 'i' => 'settings', 'href' => route('paie.virement.employes', ['mois' => $mois] + $tous), 'blank' => true],
                    ['l' => 'Fichier de virement .txt', 'i' => 'download', 'href' => route('paie.virement.fichier', ['txt', 'mois' => $mois] + $tous)],
                    ['l' => 'Fichier pour la banque', 'i' => 'bank', 'sub' => $fichiersBanque(['mois' => $mois] + $tous)],
                ]],
                ['l' => 'Déclaration CNSS', 'i' => 'file-text', 'sub' => [
                    ['l' => 'Déclaration trimestrielle des salaires', 'i' => 'settings', 'sub' => $fmt('dcnss')],
                    ['l' => 'Récapitulatif CNSS du trimestre', 'i' => 'settings', 'sub' => $fmt('recap-cnss')],
                ]],
                ['l' => 'Déclaration du régime complémentaire', 'i' => 'file-text', 'sub' => $fmt('drc')],
                ['l' => 'Déclaration Employeur', 'i' => 'file-text', 'sub' => [
                    ['l' => 'Annexe 1 : traitements et salaires', 'i' => 'settings', 'sub' => $fmt('demp')],
                    ['l' => 'Récapitulatif annuel des paies', 'i' => 'settings', 'sub' => $fmt('annuel')],
                    ['l' => 'Déclaration employeur complète (annexes 1 à 7)', 'i' => 'book', 'href' => route('declarations.index', ['type' => 'emp', 'periode' => substr($mois, 0, 4)])],
                ]],
                ['l' => 'Enveloppe Paie', 'i' => 'receipt', 'href' => route('paie.enveloppes', ['mois' => $mois] + $tous), 'blank' => true],
                ['l' => 'Récapitulation Global', 'i' => 'list', 'href' => $G('recap', $tous), 'blank' => true],
                ['l' => 'Déclaration CNSS Patronnal', 'i' => 'file-text', 'href' => $G('dcnss-patronal'), 'blank' => true],
                ['l' => 'Envoi par mail', 'i' => 'upload', 'modal' => 'paie-envoi-mail'],
            ];
        })()],
        // Bibliographie : réglementations et publications de paie-tunisie.com (config/bibliographie.php).
        'Bibliographie' => ['book', collect(config('bibliographie.groupes'))->map(fn ($g) => ['l' => $g['titre'], 'i' => 'book', 'sub' =>
            collect($g['sections'])->map(fn ($s, $k) => ['l' => $s['titre'], 'i' => 'file-text', 'href' => $lien('biblio', ['section' => $k])])->values()->all()])->values()->all()
            + [99 => ['sep' => true], 100 => ['l' => 'Site paie-tunisie.com', 'i' => 'eye', 'href' => config('bibliographie.site'), 'blank' => true]]],
    ];
    $editions = [
        ['Bulletin A4', $bul('a4')], ['Bulletin A5', $bul('a5')],
        ...collect(\App\Services\Exports\EtatsPaie::EDITIONS)->map(fn ($l, $k) => [$l, $ed($k), $k])->values()->all(),
    ];
@endphp

<div class="no-print mb-6 rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    {{-- Barre de menus --}}
    <nav class="flex flex-wrap items-center gap-0.5 border-b border-slate-200 px-2 py-1 text-sm dark:border-slate-800">
        @foreach($menus as $titre => [$icone, $items])
            <div class="relative" x-data="{ o: false }" @click.outside="o = false" @keydown.escape="o = false">
                <button type="button" @click="o = !o" :class="o && 'bg-slate-100 dark:bg-slate-800'" class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">
                    <x-icon :name="$icone" class="h-4 w-4 text-brand-600"/> {{ $titre }}
                    @if($titre === 'Fichier' && $nbNotifs)<span class="rounded-full bg-amber-500 px-1.5 text-[10px] font-bold text-white">{{ $nbNotifs }}</span>@endif
                </button>
                <div x-show="o" x-cloak x-transition.opacity class="absolute left-0 top-full z-30 mt-1 min-w-64 rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                    @foreach($items as $it)<x-paie.menu-item :it="$it"/>@endforeach
                </div>
            </div>
        @endforeach
        <button type="button" x-data @click="$dispatch('open-modal', 'aide-paie')" class="ml-1 flex h-7 w-7 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white hover:bg-brand-700" title="Aide">?</button>
    </nav>

    {{-- Barre d'outils : période, actions, éditions --}}
    <div class="flex flex-wrap items-center gap-1 px-2 py-1.5 text-sm">
        <form class="mr-2 flex items-center gap-2"><input type="hidden" name="tab" value="{{ $tab }}">
            <label class="text-xs font-medium text-slate-500">Période</label>
            <input type="month" name="mois" value="{{ $mois }}" class="input-sm w-40" onchange="this.form.submit()">
        </form>
        <span class="mx-1 h-6 w-px bg-slate-200 dark:bg-slate-700"></span>
        <button type="button" x-data @click="$dispatch('open-modal', 'paie-ouvrir')" class="btn-ghost btn-sm" title="Ouvrir un dossier"><x-icon name="folder" class="h-4 w-4"/> Ouvrir</button>
        <a href="{{ route('paie.employes.create') }}" class="btn-ghost btn-sm" title="Nouvel employé"><x-icon name="plus" class="h-4 w-4"/> Nouveau</a>
        @if($peutSauver)<button form="form-primes" class="btn-ghost btn-sm" title="Enregistrer les primes et recalculer"><x-icon name="check" class="h-4 w-4"/> Sauvegarder</button>
        @else<span class="btn-ghost btn-sm cursor-not-allowed opacity-40" title="{{ $lock ? 'Paie clôturée' : 'Disponible dans le calcul du mois' }}"><x-icon name="check" class="h-4 w-4"/> Sauvegarder</span>@endif
        <span class="mx-1 h-6 w-px bg-slate-200 dark:bg-slate-700"></span>
        @foreach($editions as $e)
            <a href="{{ $e[1] }}" target="_blank" class="btn-ghost btn-sm"><x-icon name="file-text" class="h-4 w-4 text-brand-600"/> {{ $e[0] }}</a>
        @endforeach
    </div>
</div>

@include('paie._fichier')
@include('paie._employe_menu')

<x-modal name="paie-envoi-mail" title="Envoi par mail">
    <form method="POST" action="{{ route('paie.envoi-mail') }}" class="space-y-4 text-sm">@csrf
        <input type="hidden" name="mois" value="{{ $mois }}">
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Destinataire</label><input type="email" name="email" value="{{ $client->email }}" class="input" required></div>
            <div><label class="label">Copie à</label><input type="email" name="cc" class="input"></div>
        </div>
        <div><div class="label">Pièces jointes — paie de {{ mois_fr($mois) }} (en PDF)</div>
            <div class="grid gap-1 sm:grid-cols-2">
                @foreach(\App\Http\Controllers\PaieController::ENVOIS as $k => $l)
                    <label class="flex items-center gap-2"><input type="checkbox" name="pieces[]" value="{{ $k }}" @checked(in_array($k, ['journal', 'recap', 'bulletins'], true))> {{ $l }}</label>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-slate-400">Les éditions « global » reprennent la paie du mois avec les primes, paies Autre, rappels et STC déclarés ce mois-ci.</p></div>
        <div><label class="label">Message <span class="font-normal text-slate-400">(vide : message type)</span></label><textarea name="message" rows="4" class="input"></textarea></div>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Envoyer</button></div>
    </form>
</x-modal>

<x-modal name="paie-ouvrir-stc" title="Ouvrir un solde de tout compte saisi">
    <div x-data="{ q: '' }" class="space-y-3">
        <input type="search" x-model="q" placeholder="Salarié ou mois…" class="input">
        <div class="max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
            @forelse($stcs as $p)
                <a href="{{ route('paie.index', ['tab' => 'stc', 'stc' => $p->id, 'mois' => $mois]) }}" x-show="@js(mb_strtolower($p->libelle.' '.mois_fr($p->mois))).includes(q.toLowerCase())"
                   class="flex items-center justify-between border-b border-slate-100 px-4 py-2 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800">
                    <span><b>{{ $p->nomType() }}</b> <span class="text-sm text-slate-500">— déclaré avec {{ mois_fr($p->mois) }}</span></span>
                    <span class="{{ ['brouillon' => 'badge-gray', 'transmise' => 'badge-amber', 'val' => 'badge-green'][$p->statut] ?? 'badge-gray' }}">{{ ['brouillon' => 'Brouillon', 'transmise' => 'Clôturé', 'val' => 'Comptabilisé'][$p->statut] ?? $p->statut }}</span>
                </a>
            @empty <div class="px-4 py-6 text-center text-sm text-slate-400">Aucun solde de tout compte saisi. Utilisez Solde de tout compte → Saisie.</div> @endforelse
        </div>
    </div>
</x-modal>

<x-modal name="paie-ouvrir-rappel" title="Ouvrir un rappel saisi">
    <div x-data="{ q: '' }" class="space-y-3">
        <input type="search" x-model="q" placeholder="Rappel ou mois…" class="input">
        <div class="max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
            @forelse($rappels as $p)
                <a href="{{ route('paie.index', ['tab' => 'rappel', 'rappel' => $p->id, 'mois' => $mois]) }}" x-show="@js(mb_strtolower($p->libelle.' '.mois_fr($p->mois))).includes(q.toLowerCase())"
                   class="flex items-center justify-between border-b border-slate-100 px-4 py-2 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800">
                    <span><b>{{ $p->nomType() }}</b> <span class="text-sm text-slate-500">— {{ ($m = $p->moisRappeles()) ? mois_fr($m[0]).' → '.mois_fr(end($m)).' · ' : '' }}déclaré avec {{ mois_fr($p->mois) }}</span></span>
                    <span class="{{ ['brouillon' => 'badge-gray', 'transmise' => 'badge-amber', 'val' => 'badge-green'][$p->statut] ?? 'badge-gray' }}">{{ ['brouillon' => 'Brouillon', 'transmise' => 'Clôturé', 'val' => 'Comptabilisé'][$p->statut] ?? $p->statut }}</span>
                </a>
            @empty <div class="px-4 py-6 text-center text-sm text-slate-400">Aucun rappel saisi. Utilisez Rappel → Saisie Rappel.</div> @endforelse
        </div>
    </div>
</x-modal>

<x-modal name="paie-ouvrir-prime" title="Ouvrir une prime saisie">
    <div x-data="{ q: '' }" class="space-y-3">
        <input type="search" x-model="q" placeholder="Prime ou mois…" class="input">
        <div class="max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
            @forelse($primes as $p)
                <a href="{{ route('paie.index', ['tab' => 'prime', 'prime' => $p->id, 'mois' => $mois]) }}" x-show="@js(mb_strtolower($p->libelle.' '.mois_fr($p->mois))).includes(q.toLowerCase())"
                   class="flex items-center justify-between border-b border-slate-100 px-4 py-2 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800">
                    <span><b>{{ $p->libelle }}</b> <span class="text-sm text-slate-500">— déclarée avec {{ mois_fr($p->mois) }}</span></span>
                    <span class="{{ ['brouillon' => 'badge-gray', 'transmise' => 'badge-amber', 'val' => 'badge-green'][$p->statut] ?? 'badge-gray' }}">{{ ['brouillon' => 'Brouillon', 'transmise' => 'Clôturée', 'val' => 'Comptabilisée'][$p->statut] ?? $p->statut }}</span>
                </a>
            @empty <div class="px-4 py-6 text-center text-sm text-slate-400">Aucune prime saisie. Utilisez Prime → Saisie Prime.</div> @endforelse
        </div>
    </div>
</x-modal>

@php
    // Fenêtre de choix des bulletins (Paie → Bulletin → critère → format).
    $parMatricule = \App\Services\PaieCalc::trier($salaries, 'matricule');
    $parNom = \App\Services\PaieCalc::trier($salaries, 'nom');
    $departements = $salaries->pluck('departement')->map(fn ($v) => trim((string) $v))->filter()->unique()->sort()->values();
    $profilsDossier = $salaries->pluck('profil')->map(fn ($v) => trim((string) $v))->filter()->unique()->sort()->values();
    $titresChoix = ['matricule' => 'Bulletin par matricule', 'nom' => 'Bulletin par nom et prénom', 'regime' => 'Bulletins par régime salarial', 'nom-annee' => 'Bulletins d’un employé sur une année',
        'departement' => 'Bulletins par département', 'profil' => 'Bulletins par profil', 'periode' => 'Bulletins par période', 'generer' => 'Générer les bulletins (PDF nommés par nom)', 'generer-cin' => 'Générer les bulletins (PDF nommés par CIN)'];
@endphp
<x-modal name="paie-bulletins-choix" title="Bulletins de paie">
    <form method="GET" class="space-y-4" x-data="{ critere: 'matricule', format: 'a4', titres: @js($titresChoix), modes: @js(\App\Http\Controllers\PaieController::FORMATS_BULLETIN), get zip() { return this.critere.startsWith('generer') } }"
          @paie-choix.window="critere = $event.detail.critere; format = $event.detail.format"
          :action="zip ? @js(route('paie.bulletins.zip')) : @js(route('paie.bulletins'))" :target="zip ? '_self' : '_blank'">
        <div class="font-semibold text-slate-800 dark:text-slate-100"><span x-text="titres[critere]"></span> — <span x-text="modes[format] || format"></span></div>
        <input type="hidden" name="format" :value="format">
        <template x-if="critere === 'generer-cin'"><input type="hidden" name="nommage" value="cin"></template>

        <template x-if="critere === 'matricule'"><div><label class="label">Employé (par matricule)</label>
            <select name="salarie" class="input" required>@foreach($parMatricule as $s)<option value="{{ $s->id }}">{{ $s->matricule ?: '—' }} · {{ $s->nomComplet() }}</option>@endforeach</select></div></template>
        <template x-if="critere === 'nom' || critere === 'nom-annee'"><div><label class="label">Employé</label>
            <select name="salarie" class="input" required>@foreach($parNom as $s)<option value="{{ $s->id }}">{{ $s->nomComplet() }}{{ $s->matricule ? ' ('.$s->matricule.')' : '' }}</option>@endforeach</select></div></template>
        <template x-if="critere === 'regime'"><div><label class="label">Régime salarial</label>
            <select name="regime" class="input"><option value="mensuel">Mensuel</option><option value="horaire">Horaire</option></select></div></template>
        <template x-if="critere === 'departement'"><div><label class="label">Département</label>
            <select name="departement" class="input" required>@forelse($departements as $v)<option>{{ $v }}</option>@empty<option value="">Aucun département renseigné sur les fiches</option>@endforelse</select></div></template>
        <template x-if="critere === 'profil'"><div><label class="label">Profil</label>
            <select name="profil" class="input" required>@forelse($profilsDossier as $v)<option>{{ $v }}</option>@empty<option value="">Aucun profil renseigné sur les fiches</option>@endforelse</select></div></template>

        <template x-if="critere === 'nom-annee'"><div><label class="label">Année</label><input type="number" name="annee" min="2000" max="2100" value="{{ substr($mois, 0, 4) }}" class="input w-32" required></div></template>
        <template x-if="critere === 'periode' || zip"><div class="grid grid-cols-2 gap-3">
            <div><label class="label">Du mois</label><input type="month" name="du" value="{{ $mois }}" class="input" required></div>
            <div><label class="label">Au mois</label><input type="month" name="au" value="{{ $mois }}" class="input" required></div></div></template>
        <template x-if="['matricule', 'nom', 'regime', 'departement', 'profil'].includes(critere)"><div><label class="label">Mois</label><input type="month" name="mois" value="{{ $mois }}" class="input w-48" required></div></template>

        <p class="text-xs text-slate-400" x-show="zip">Un fichier PDF par bulletin, réunis dans une archive ZIP (24 mois au plus).</p>
        <p class="text-xs text-slate-400" x-show="!zip">Les bulletins s’ouvrent dans un nouvel onglet, prêts à imprimer.</p>
        <div class="flex justify-end"><button class="btn-primary"><span x-show="!zip"><x-icon name="printer" class="h-4 w-4"/></span><span x-show="zip"><x-icon name="download" class="h-4 w-4"/></span> <span x-text="zip ? 'Générer' : 'Afficher'"></span></button></div>
    </form>
</x-modal>

<x-modal name="aide-paie" title="Module Paie — mode d’emploi">
    <div class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
        <p><b>Période</b> : choisissez le mois dans la barre d’outils. Toutes les éditions portent sur ce mois (ou sur son trimestre pour la CNSS, son année pour la déclaration employeur).</p>
        <p><b>Employé</b> : fiche du salarié (situation familiale, salaire de base, matricule, mode de paiement, banque et RIB).</p>
        <p><b>Profil</b> : Ordinaire, CIVP, DIGNITE, Exo 2 ans, Gérant… Chaque profil fixe le taux de CNSS patronale, la TFP et le FOPROLOS, la présence dans les déclarations CNSS et employeur, et les rubriques que reçoivent ses employés. Le profil de chaque employé se choisit sur sa fiche.</p>
        <p><b>Rubrique</b> : primes, indemnités, avantages en nature et retenues du dossier (montant fixe, pourcentage, proportion ou saisie mensuelle), applicables à tous les employés ou à certains profils.</p>
        <p><b>Paie / Prime</b> : <i>Saisie Paie</i> pour le pointage (jours, heures supplémentaires) et les primes, <i>Saisie Autre</i> pour les rubriques à saisir du mois, puis <i>Sauvegarder</i> pour recalculer. <i>Clôturer et transmettre</i> fige la paie et l’envoie à la comptabilité.
            Le menu Paie propose aussi les bulletins (tous ou un seul employé), le journal, le bordereau payeur par mode de paiement, l’ordre de virement, les enveloppes de paie avec le billetage des paiements en espèces, la récapitulation de paie et un état vierge à remplir à la main avant la saisie.</p>
        <p><b>Éditions</b> : bulletins A4 ou A5, journal global, déclaration trimestrielle CNSS, récapitulatif CNSS, charges patronales, déclaration employeur (annexe 1), ordre de virement et bordereau d’émargement. Le menu <i>Edition</i> propose aussi les formats PDF, Excel et Word.</p>
        <p class="text-xs text-slate-400">Les menus marqués « bientôt » (rappels, solde de tout compte, congés, acomptes, prêts) seront ajoutés dans les prochaines étapes.</p>
    </div>
</x-modal>
