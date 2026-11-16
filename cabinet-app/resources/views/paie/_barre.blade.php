{{-- Barre de menus et barre d'éditions du module Paie. Les entrées « bientôt » seront livrées au fil des étapes. --}}
@php
    $lien = fn ($t, $extra = []) => route('paie.index', ['tab' => $t, 'mois' => $mois] + $extra);
    $ed = fn ($e, $f = 'imprimer') => route('paie.edition', [$e, $f, 'mois' => $mois]);
    $bul = fn ($f) => route('paie.bulletins', ['mois' => $mois, 'format' => $f]);
    $peutSauver = $tab === 'calc' && ! $lock && count($R);
    $chef = auth()->user()->estChef();
    $reserve = 'Réservé au chef comptable';
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
        'Profil' => ['briefcase', [['l' => 'Profils de paie', 'i' => 'briefcase']]],
        'Rubrique' => ['list', [['l' => 'Rubriques de paie (gains, retenues)', 'i' => 'list']]],
        'Paie' => ['wallet', [
            ['l' => 'Calcul de la paie du mois', 'i' => 'wallet', 'href' => $lien('calc')],
            ['l' => 'Historique des paies', 'i' => 'clock', 'href' => $lien('hist')],
        ]],
        'Prime' => ['sparkles', [['l' => 'Saisie des primes du mois', 'i' => 'sparkles', 'href' => $lien('calc').'#primes']]],
        'Rappel' => ['clock', [['l' => 'Rappels de salaire', 'i' => 'clock']]],
        'Solde de tout compte' => ['receipt', [['l' => 'Solde de tout compte', 'i' => 'receipt']]],
        'Congé' => ['calendar', [['l' => 'Congés payés', 'i' => 'calendar']]],
        'Acompte' => ['bank', [['l' => 'Acomptes sur salaire', 'i' => 'bank']]],
        'Prêt' => ['scale', [['l' => 'Prêts au personnel', 'i' => 'scale']]],
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
        {{-- Édition : toutes les éditions, avec choix du format --}}
        <div class="relative" x-data="{ o: false }" @click.outside="o = false" @keydown.escape="o = false">
            <button type="button" @click="o = !o" :class="o && 'bg-slate-100 dark:bg-slate-800'" class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">
                <x-icon name="printer" class="h-4 w-4 text-brand-600"/> Edition
            </button>
            <div x-show="o" x-cloak x-transition.opacity class="absolute right-0 top-full z-30 mt-1 w-96 rounded-lg border border-slate-200 bg-white py-1 shadow-lg md:left-0 md:right-auto dark:border-slate-700 dark:bg-slate-900">
                @foreach($editions as $e)
                    <div class="flex items-center justify-between gap-2 px-4 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <a href="{{ $e[1] }}" target="_blank" class="text-slate-700 dark:text-slate-200">{{ $e[0] }}</a>
                        @isset($e[2])<span class="flex gap-1 text-xs">
                            <a href="{{ $ed($e[2], 'pdf') }}" target="_blank" class="rounded px-1.5 py-0.5 text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-700">PDF</a>
                            <a href="{{ $ed($e[2], 'xlsx') }}" class="rounded px-1.5 py-0.5 text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-700">Excel</a>
                            <a href="{{ $ed($e[2], 'docx') }}" class="rounded px-1.5 py-0.5 text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-700">Word</a>
                        </span>@endisset
                    </div>
                @endforeach
            </div>
        </div>
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

<x-modal name="aide-paie" title="Module Paie — mode d’emploi">
    <div class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
        <p><b>Période</b> : choisissez le mois dans la barre d’outils. Toutes les éditions portent sur ce mois (ou sur son trimestre pour la CNSS, son année pour la déclaration employeur).</p>
        <p><b>Employé</b> : fiche du salarié (situation familiale, salaire de base, matricule, mode de paiement, banque et RIB).</p>
        <p><b>Paie / Prime</b> : saisissez les primes du mois, puis <i>Sauvegarder</i> pour recalculer. <i>Clôturer et transmettre</i> fige la paie et l’envoie à la comptabilité.</p>
        <p><b>Éditions</b> : bulletins A4 ou A5, journal global, déclaration trimestrielle CNSS, récapitulatif CNSS, charges patronales, déclaration employeur (annexe 1), ordre de virement et bordereau d’émargement. Le menu <i>Edition</i> propose aussi les formats PDF, Excel et Word.</p>
        <p class="text-xs text-slate-400">Les menus marqués « bientôt » (profils, rubriques, rappels, solde de tout compte, congés, acomptes, prêts) seront ajoutés dans les prochaines étapes.</p>
    </div>
</x-modal>
