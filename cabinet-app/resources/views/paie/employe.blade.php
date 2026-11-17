{{-- Fiche employé : en-tête et six onglets (État civil, Informations spécifiques, Entrée/Sortie, Règlement, Pièces jointes, Détail contrats). --}}
@php
    $lock = $paie && $paie->statut !== 'brouillon';
    $G = \App\Models\Setting::paie();
    $v = fn ($c) => old($c, $e->$c instanceof \Carbon\Carbon ? $e->$c->format('Y-m-d') : $e->$c);
    $coche = fn ($c) => old('_form') === 'employe' ? (bool) old($c) : (bool) $e->$c;
    $req = '<span class="text-rose-600">*</span>';
    $onglets = [
        'civil' => ['adresse', 'ville', 'code_postal', 'tel_fixe', 'tel_mobile', 'cin', 'cin_date', 'date_naissance', 'nationalite', 'lieu_naissance', 'situation_familiale', 'enfants_detail', 'parents_charge'],
        'spec' => ['poste', 'regime', 'conges_acquis', 'conges_report', 'conges_report_annee', 'categorie', 'categorie_debut', 'categorie_fin', 'echelon', 'echelon_debut', 'echelon_fin', 'salaire_base', 'profil', 'profil_debut', 'profil_fin', 'cnss', 'nb_bulletin', 'assurance_vie', 'cea', 'interets_bancaires'],
        'es' => ['type_contrat', 'contrat_debut', 'contrat_fin', 'departement', 'etat_presence', 'date_sortie', 'motif_sortie', 'date_embauche', 'date_declaration_cnss'],
        'reg' => ['mode_paiement', 'banque', 'agence', 'rib'],
        'pj' => ['photo', 'cin1', 'cin2', 'contrat_fichier', 'contrat_nom', 'diplome_fichier', 'diplome_nom'],
    ];
    $depart = 'civil';
    if ($errors->any()) foreach ($onglets as $o => $champs) if ($errors->hasAny($champs)) { $depart = $o; break; }
    $enfants = array_values(old('_form') === 'employe' ? (array) old('enfants_detail', []) : (array) $e->enfants_detail);
    $k = $client->paie_config ?? [];
    $err = fn ($c) => $errors->first($c) ? '<p class="mt-1 text-xs text-rose-600">'.e($errors->first($c)).'</p>' : '';
    $docs = $e->exists ? $e->documents : collect();
    $img = fn ($type) => ($d = $docs->firstWhere('type', $type)) ? $d : null;
@endphp
<x-layouts.app :title="$e->exists ? 'Fiche employé — '.$e->nomComplet() : 'Nouvel employé'">
    <x-page-header title="Paie" :subtitle="$client->nom.' · fiche employé'">
        <x-client-picker :clients="$clients" :current="$client" :route="route('paie.index')" :params="['tab' => 'sal', 'mois' => $mois]"/>
    </x-page-header>
    @include('paie._barre')

    <div class="mb-3 flex items-center gap-2 text-sm">
        <a href="{{ route('paie.index', ['tab' => 'sal', 'mois' => $mois]) }}" class="text-slate-500 hover:text-brand-700">Employés</a>
        <x-icon name="chevron-right" class="h-3.5 w-3.5 text-slate-400"/>
        <span class="font-medium">{{ $e->exists ? $e->nomComplet() : 'Nouvel employé' }}</span>
    </div>

    <form method="POST" action="{{ $e->exists ? route('paie.salaries.update', $e) : route('paie.salaries.store') }}" enctype="multipart/form-data" class="card" x-data="{ t: '{{ $depart }}', etat: @js($v('etat_presence') ?? 'actif'), mode: @js($v('mode_paiement') ?? 'virement'), regime: @js($v('regime') ?? 'mensuel') }">
        @csrf @if($e->exists) @method('PUT') @endif
        <input type="hidden" name="_form" value="employe">

        {{-- En-tête --}}
        <div class="flex flex-wrap items-start gap-6 border-b border-slate-100 p-5 dark:border-slate-800">
            <div class="grid flex-1 gap-3 md:grid-cols-6">
                <div class="md:col-span-2"><label class="label">Matricule {!! $req !!}</label><input name="matricule" value="{{ $v('matricule') }}" class="input font-mono">{!! $err('matricule') !!}</div>
                <div><label class="label">Titre</label><select name="titre" class="input"><option value="">—</option>@foreach(\App\Models\Salarie::TITRES as $c => $l)<option value="{{ $c }}" @selected($v('titre') === $c)>{{ $l }}</option>@endforeach</select></div>
                <div class="md:col-span-3"><label class="label">Matricule pointeuse</label><input name="matricule_pointeuse" value="{{ $v('matricule_pointeuse') }}" class="input"></div>
                <div class="md:col-span-3"><label class="label">Nom {!! $req !!}</label><input name="nom" value="{{ $v('nom') }}" class="input">{!! $err('nom') !!}</div>
                <div class="md:col-span-3"><label class="label">Prénom {!! $req !!}</label><input name="prenom" value="{{ $v('prenom') }}" class="input">{!! $err('prenom') !!}</div>
            </div>
            <div class="flex flex-col items-end gap-2">
                <div class="flex flex-wrap gap-2">
                    <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
                    @if($e->exists)
                        <button type="submit" form="supprimer-employe" class="btn-secondary text-rose-700"><x-icon name="trash" class="h-4 w-4"/> Supprimer</button>
                        <a href="{{ route('paie.employes.imprimer', $e) }}" target="_blank" class="btn-secondary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</a>
                    @endif
                </div>
                <span class="text-xs text-rose-600">* Mention obligatoire</span>
            </div>
        </div>
        @if($errors->any())<div class="mx-5 mt-4 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">Certaines mentions obligatoires sont manquantes ou invalides : {{ implode(' ', array_slice($errors->all(), 0, 4)) }}</div>@endif

        <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-5 pt-3 dark:border-slate-800">
            @foreach(['civil' => 'État civil', 'spec' => 'Informations spécifiques', 'es' => 'Entrée / Sortie', 'reg' => 'Règlement', 'pj' => 'Pièces jointes', 'hist' => 'Détail contrats'] as $o => $l)
                <button type="button" @click="t = '{{ $o }}'" class="tab" :class="t === '{{ $o }}' && 'tab-active'">{{ $l }}</button>
            @endforeach
        </div>

        <div class="p-5">
            {{-- État civil --}}
            <div x-show="t === 'civil'" class="grid gap-5 lg:grid-cols-2">
                <div class="space-y-5">
                    <fieldset class="grid gap-3 rounded-lg border border-slate-200 p-4 md:grid-cols-3 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Adresse</legend>
                        <div class="md:col-span-3"><label class="label">Adresse {!! $req !!}</label><input name="adresse" value="{{ $v('adresse') }}" class="input">{!! $err('adresse') !!}</div>
                        <div class="md:col-span-2"><label class="label">Ville {!! $req !!}</label><input name="ville" value="{{ $v('ville') }}" class="input">{!! $err('ville') !!}</div>
                        <div><label class="label">CP</label><input name="code_postal" value="{{ $v('code_postal') }}" class="input"></div>
                    </fieldset>
                    <fieldset class="grid gap-3 rounded-lg border border-slate-200 p-4 md:grid-cols-2 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Téléphone</legend>
                        <div><label class="label">Fixe</label><input name="tel_fixe" value="{{ $v('tel_fixe') }}" class="input"></div>
                        <div><label class="label">Mobile</label><input name="tel_mobile" value="{{ $v('tel_mobile') }}" class="input"></div>
                    </fieldset>
                    <fieldset class="grid gap-3 rounded-lg border border-slate-200 p-4 md:grid-cols-2 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Civilité</legend>
                        <div><label class="label">N° CIN / carte de séjour {!! $req !!}</label><input name="cin" value="{{ $v('cin') }}" class="input font-mono">{!! $err('cin') !!}</div>
                        <div><label class="label">Date CIN / carte de séjour</label><div class="flex items-center gap-3"><input type="date" name="cin_date" value="{{ $v('cin_date') }}" class="input">
                            <label class="flex items-center gap-1.5 whitespace-nowrap text-xs"><input type="checkbox" name="carte_sejour" value="1" @checked($coche('carte_sejour'))> Carte de séjour</label></div></div>
                        <div><label class="label">Date de naissance</label><input type="date" name="date_naissance" value="{{ $v('date_naissance') }}" class="input">{!! $err('date_naissance') !!}</div>
                        <div><label class="label">Nationalité</label><input name="nationalite" value="{{ $v('nationalite') }}" class="input"></div>
                        <div class="md:col-span-2"><label class="label">Lieu de naissance</label><input name="lieu_naissance" value="{{ $v('lieu_naissance') }}" class="input"></div>
                    </fieldset>
                </div>
                <fieldset class="space-y-4 rounded-lg border border-slate-200 p-4 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Situation et enfants</legend>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><label class="label">Situation familiale {!! $req !!}</label><select name="situation_familiale" class="input"><option value="">—</option>@foreach(\App\Models\Salarie::SITUATIONS as $c => $l)<option value="{{ $c }}" @selected($v('situation_familiale') === $c)>{{ $l }}</option>@endforeach</select>{!! $err('situation_familiale') !!}</div>
                        <label class="flex items-center gap-2 self-end pb-2 text-sm font-semibold"><input type="checkbox" name="chef_famille" value="1" @checked($coche('chef_famille'))> Chef de famille</label>
                        <div><label class="label">Nombre d’enfants à charge</label><input value="{{ count(array_filter($enfants, fn ($x) => !empty($x['a_charge']) || !empty($x['etudiant']) || !empty($x['infirme']))) }}" class="input bg-slate-50 dark:bg-slate-800" disabled></div>
                        <div><label class="label">Parents à charge</label><input type="number" name="parents_charge" min="0" max="4" value="{{ $v('parents_charge') ?? 0 }}" class="input"></div>
                    </div>
                    <div class="space-y-2">
                        @foreach(range(0, 3) as $i)
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
                                <span class="w-16 text-xs font-semibold text-slate-500">Ordre {{ $i + 1 }}</span>
                                @foreach(['a_charge' => ['À charge', $G['enf']], 'etudiant' => ['Étudiant', $G['enfEtu'] ?? 1000], 'infirme' => ['Infirme', $G['enfInf'] ?? 2000]] as $c => [$l, $montant])
                                    <label class="flex items-center gap-1.5"><input type="checkbox" name="enfants_detail[{{ $i }}][{{ $c }}]" value="1" @checked(!empty($enfants[$i][$c]))> {{ $l }} <span class="text-xs text-slate-400">({{ number_format($montant, 0, ',', ' ') }})</span></label>
                                @endforeach
                            </div>
                        @endforeach
                        <p class="text-xs text-slate-400">Déductions annuelles de l’IRPP par enfant (4 premiers enfants), selon les paramètres de paie.</p>
                    </div>
                </fieldset>
            </div>

            {{-- Informations spécifiques --}}
            <div x-show="t === 'spec'" x-cloak class="grid gap-5 lg:grid-cols-3">
                <div class="grid gap-3 md:grid-cols-3 lg:col-span-2">
                    <div><label class="label">Qualification {!! $req !!}</label><input name="poste" value="{{ $v('poste') }}" class="input">{!! $err('poste') !!}</div>
                    <div><label class="label">Régime salarial {!! $req !!}</label><select name="regime" x-model="regime" class="input"><option value="mensuel">Mensuel</option><option value="horaire">Horaire</option></select></div>
                    <div><label class="label">Acquis congé / mois {!! $req !!} <span class="text-xs font-normal text-slate-400" x-text="regime === 'horaire' ? '(heures)' : '(jours)'"></span></label><input type="number" step="0.01" min="0" name="conges_acquis" value="{{ $v('conges_acquis') ?? 2 }}" class="input">{!! $err('conges_acquis') !!}</div>
                    <div><label class="label">Report de congés <span class="text-xs font-normal text-slate-400">(jours restant dus au 1er janvier de)</span></label>
                        <div class="flex gap-2"><input type="number" step="0.5" name="conges_report" value="{{ $v('conges_report') }}" placeholder="0" class="input">
                            <input type="number" min="1990" max="2100" name="conges_report_annee" value="{{ $v('conges_report_annee') }}" placeholder="{{ now()->year }}" class="input w-28"></div>
                        {!! $err('conges_report') !!}{!! $err('conges_report_annee') !!}
                        @if($e->exists)@php $sc = \App\Services\PaieCalc::soldeConges($e, now()->format('Y-m')); $jj = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ','); @endphp
                            <p class="mt-1 text-xs text-slate-500">Solde à ce jour : report {{ $jj($sc['report']) }} j + acquis {{ now()->year }} {{ $jj($sc['acquis']) }} j − pris {{ $jj($sc['pris']) }} j = <b>{{ $jj($sc['reste']) }} j</b></p>@endif
                    </div>
                    <div><label class="label">Catégorie {!! $req !!}</label><input name="categorie" list="l-categories" value="{{ $v('categorie') }}" class="input">{!! $err('categorie') !!}</div>
                    <div><label class="label">Début catégorie</label><input type="date" name="categorie_debut" value="{{ $v('categorie_debut') }}" class="input"></div>
                    <div><label class="label">Fin catégorie</label><input type="date" name="categorie_fin" value="{{ $v('categorie_fin') }}" class="input">{!! $err('categorie_fin') !!}</div>
                    <div><label class="label">Échelon {!! $req !!}</label><input name="echelon" list="l-echelons" value="{{ $v('echelon') }}" class="input">{!! $err('echelon') !!}</div>
                    <div><label class="label">Début échelon</label><input type="date" name="echelon_debut" value="{{ $v('echelon_debut') }}" class="input"></div>
                    <div><label class="label">Fin échelon</label><input type="date" name="echelon_fin" value="{{ $v('echelon_fin') }}" class="input">{!! $err('echelon_fin') !!}</div>
                    <div><label class="label"><span x-text="regime === 'horaire' ? 'Taux horaire' : 'Salaire de base'"></span> {!! $req !!}</label><input type="number" step="0.001" min="0" name="salaire_base" value="{{ $v('salaire_base') }}" class="input">{!! $err('salaire_base') !!}</div>
                    <div><label class="label">Profil</label><input name="profil" list="l-profils" value="{{ $v('profil') }}" class="input"></div>
                    <div class="grid grid-cols-2 gap-2"><div><label class="label">Début profil</label><input type="date" name="profil_debut" value="{{ $v('profil_debut') }}" class="input"></div><div><label class="label">Fin profil</label><input type="date" name="profil_fin" value="{{ $v('profil_fin') }}" class="input"></div></div>
                    <div><label class="label">N° d’affiliation CNSS</label><input name="cnss" value="{{ $v('cnss') }}" class="input font-mono"></div>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm font-semibold"><input type="checkbox" name="cnss_assujetti" value="1" @checked(old('_form') === 'employe' ? (bool) old('cnss_assujetti') : ($e->cnss_assujetti ?? true))> Assujetti à la CNSS</label>
                    <div><label class="label">Nombre de bulletins {!! $req !!}</label><select name="nb_bulletin" class="input">@foreach(range(1, 5) as $n)<option value="{{ $n }}" @selected((int) ($v('nb_bulletin') ?? 1) === $n)>{{ $n }}</option>@endforeach</select></div>
                    <fieldset class="grid gap-3 rounded-lg border border-slate-200 p-4 md:col-span-3 md:grid-cols-3 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Déductions annuelles de l’assiette de l’IRPP (montants déductibles)</legend>
                        <div><label class="label">Assurance vie</label><input type="number" step="0.001" min="0" name="assurance_vie" value="{{ $v('assurance_vie') }}" class="input" placeholder="0,000"></div>
                        <div><label class="label">CEA</label><input type="number" step="0.001" min="0" name="cea" value="{{ $v('cea') }}" class="input" placeholder="0,000"></div>
                        <div><label class="label">Intérêts bancaires</label><input type="number" step="0.001" min="0" name="interets_bancaires" value="{{ $v('interets_bancaires') }}" class="input" placeholder="0,000"></div>
                    </fieldset>
                    <label class="flex items-center gap-2 text-sm font-semibold md:col-span-3"><input type="checkbox" name="stc_solde_total" value="1" @checked($coche('stc_solde_total'))> Solde des congés total pour le STC</label>
                </div>
                <div class="space-y-3 rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                    <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="retenue_etranger" value="1" @checked($coche('retenue_etranger'))> Retenue à la source étranger</label>
                    <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="cnss_rc" value="1" @checked($coche('cnss_rc'))> CNSS régime complémentaire</label>
                    @php $regDefaut = fn ($c, $dossier) => old($c, $e->exists && $e->$c !== null ? $e->$c : $dossier); @endphp
                    <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="regul_irpp" value="1" @checked($regDefaut('regul_irpp', ! empty($k['regul_irpp'])))> Régularisation mensuelle de l’IRPP</label>
                    <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="regul_primes" value="1" @checked($regDefaut('regul_primes', ($k['regul_primes'] ?? '') === 'regul'))> Régularisation sur les primes / autres / rappel</label>
                    <p class="text-xs text-slate-400">Régularisation : l’IRPP est calculé sur le cumul imposable de l’année annualisé, diminué des retenues déjà opérées. Sur les primes / autres / rappel : la prime entre dans ce cumul ; sinon son IRPP est calculé par différence. Ces cases se cochent aussi dans Paie → Saisie Paie (colonnes Reg et RegP). « Retenue à la source étranger » et « CNSS régime complémentaire » sont enregistrés sur la fiche ; leur calcul sera ajouté avec les rubriques de paie.</p>
                </div>
            </div>

            {{-- Entrée / Sortie --}}
            <div x-show="t === 'es'" x-cloak class="grid max-w-4xl gap-4 md:grid-cols-3">
                <div><label class="label">Contrat</label><select name="type_contrat" class="input"><option value="">—</option>@foreach(\App\Models\Salarie::CONTRATS as $c => $l)<option value="{{ $c }}" @selected($v('type_contrat') === $c)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="label">Date début {!! $req !!}</label><input type="date" name="contrat_debut" value="{{ $v('contrat_debut') }}" class="input">{!! $err('contrat_debut') !!}</div>
                <div><label class="label">Date fin</label><input type="date" name="contrat_fin" value="{{ $v('contrat_fin') }}" class="input">{!! $err('contrat_fin') !!}</div>
                <div><label class="label">Département</label><input name="departement" list="l-departements" value="{{ $v('departement') }}" class="input" placeholder="Choisir ou saisir un nouveau département"></div>
                <div><label class="label">État de présence {!! $req !!}</label><select name="etat_presence" x-model="etat" class="input">@foreach(\App\Models\Salarie::ETATS as $c => $l)<option value="{{ $c }}">{{ $l }}</option>@endforeach</select></div>
                <div></div>
                <template x-if="etat === 'sorti'"><div class="grid gap-4 rounded-lg bg-amber-50 p-3 md:col-span-3 md:grid-cols-3 dark:bg-amber-950/30">
                    <div><label class="label">Date de sortie {!! $req !!}</label><input type="date" name="date_sortie" value="{{ $v('date_sortie') }}" class="input">{!! $err('date_sortie') !!}</div>
                    <div><label class="label">Motif de sortie</label><select name="motif_sortie" class="input"><option value="">—</option>@foreach(\App\Models\Salarie::MOTIFS_SORTIE as $c => $l)<option value="{{ $c }}" @selected($v('motif_sortie') === $c)>{{ $l }}</option>@endforeach</select></div>
                </div></template>
                <div><label class="label">Date d’embauche</label><input type="date" name="date_embauche" value="{{ $v('date_embauche') }}" class="input"><p class="mt-1 text-xs text-slate-400">Par défaut, la date de début du contrat.</p></div>
                <div><label class="label">1re date de déclaration CNSS</label><input type="date" name="date_declaration_cnss" value="{{ $v('date_declaration_cnss') }}" class="input"></div>
            </div>

            {{-- Règlement --}}
            <fieldset x-show="t === 'reg'" x-cloak class="grid max-w-xl gap-4 rounded-lg border border-slate-200 p-4 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Règlement</legend>
                <div><label class="label">Mode</label><select name="mode_paiement" x-model="mode" class="input">@foreach(\App\Models\Salarie::MODES as $c => $l)<option value="{{ $c }}">{{ $l }}</option>@endforeach</select></div>
                <div><label class="label">Banque</label><input name="banque" value="{{ $v('banque') }}" class="input"></div>
                <div><label class="label">Agence</label><input name="agence" value="{{ $v('agence') }}" class="input"></div>
                <div><label class="label">RIB</label><input name="rib" value="{{ $v('rib') }}" maxlength="30" class="input font-mono"></div>
            </fieldset>

            {{-- Pièces jointes --}}
            <div x-show="t === 'pj'" x-cloak class="grid gap-4 lg:grid-cols-4">
                <fieldset class="rounded-lg border border-slate-200 p-4 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Photo employé</legend>
                    <div class="mb-3 flex h-40 items-center justify-center overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                        @if($p = $img('photo'))<img src="{{ route('paie.employes.piece', $p) }}" alt="Photo" class="h-full object-cover">@else<x-icon name="users" class="h-16 w-16 text-slate-300"/>@endif
                    </div>
                    <input type="file" name="photo" accept="image/*" class="input-sm">{!! $err('photo') !!}
                    @if($p)<button type="submit" form="piece-{{ $p->id }}" class="mt-2 text-xs text-rose-600 hover:underline">Supprimer la photo</button>@endif
                </fieldset>
                <fieldset class="space-y-3 rounded-lg border border-slate-200 p-4 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">Carte d’identité nationale</legend>
                    @foreach(['cin1' => '1re face', 'cin2' => '2e face'] as $c => $l)
                        <div>
                            <div class="mb-1 text-xs font-semibold text-slate-500">{{ $l }}</div>
                            @if($p = $img($c))
                                <div class="mb-1 flex items-center gap-2">
                                    @if(str_starts_with((string) $p->mime, 'image/'))<a href="{{ route('paie.employes.piece', $p) }}" target="_blank"><img src="{{ route('paie.employes.piece', $p) }}" alt="{{ $l }}" class="h-16 rounded border border-slate-200"></a>
                                    @else<a href="{{ route('paie.employes.piece', $p) }}" target="_blank" class="text-xs text-brand-700 underline">Voir le fichier</a>@endif
                                    <button type="submit" form="piece-{{ $p->id }}" class="text-xs text-rose-600 hover:underline">Supprimer</button>
                                </div>
                            @endif
                            <input type="file" name="{{ $c }}" accept="image/*,.pdf" class="input-sm">{!! $err($c) !!}
                        </div>
                    @endforeach
                </fieldset>
                @foreach(['contrat' => 'Liste des contrats', 'diplome' => 'Liste des diplômes'] as $type => $l)
                    <fieldset class="space-y-3 rounded-lg border border-slate-200 p-4 dark:border-slate-700"><legend class="px-1 text-xs font-semibold uppercase text-slate-400">{{ $l }}</legend>
                        <div class="min-h-24 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm dark:divide-slate-800 dark:border-slate-700">
                            @forelse($docs->where('type', $type) as $p)
                                <div class="flex items-center justify-between gap-2 px-3 py-1.5"><a href="{{ route('paie.employes.piece', $p) }}" target="_blank" class="truncate text-brand-700 hover:underline">{{ $p->nom }}</a>
                                    <button type="submit" form="piece-{{ $p->id }}" class="text-xs text-rose-600 hover:underline">Supprimer</button></div>
                            @empty <div class="px-3 py-6 text-center text-xs text-slate-400">Aucun fichier</div> @endforelse
                        </div>
                        @if($type === 'contrat')
                            <div><label class="label">Nom du contrat</label><input name="contrat_nom" list="l-noms-contrats" value="{{ old('contrat_nom') }}" class="input-sm" placeholder="Contrat CDI, avenant…"></div>
                        @else
                            <div><label class="label">Nom du diplôme</label><input name="diplome_nom" value="{{ old('diplome_nom') }}" class="input-sm">{!! $err('diplome_nom') !!}</div>
                        @endif
                        <input type="file" name="{{ $type }}_fichier" class="input-sm">{!! $err($type.'_fichier') !!}
                    </fieldset>
                @endforeach
                <p class="text-xs text-slate-400 lg:col-span-4">Les fichiers choisis sont ajoutés en cliquant sur « Enregistrer ». Ils sont conservés dans un espace privé, accessible aux seuls utilisateurs du cabinet.</p>
            </div>

            {{-- Détail contrats : changements de situation --}}
            <div x-show="t === 'hist'" x-cloak>
                <h3 class="mb-2 text-sm font-semibold text-slate-600 dark:text-slate-300">Changement de situation</h3>
                <div class="max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"><tr><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Modification</th><th class="px-3 py-2 text-left">Avant</th><th class="px-3 py-2 text-left">Après</th><th class="px-3 py-2 text-left">Par</th></tr></thead>
                        <tbody>
                            @forelse($e->exists ? $e->historique : [] as $h)
                                <tr class="border-t border-slate-100 dark:border-slate-800"><td class="whitespace-nowrap px-3 py-1.5 text-xs">{{ $h->created_at->format('d/m/Y H:i') }}</td><td class="px-3 py-1.5">{{ $h->libelle }}</td>
                                    <td class="px-3 py-1.5 text-slate-500">{{ $h->ancien ?? '—' }}</td><td class="px-3 py-1.5 font-medium">{{ $h->nouveau ?? '—' }}</td><td class="px-3 py-1.5 text-xs text-slate-500">{{ $h->user?->name ?? '—' }}</td></tr>
                            @empty <tr><td colspan="5" class="px-3 py-10 text-center text-slate-400">Aucun changement enregistré.</td></tr> @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>

    <datalist id="l-departements">@foreach($departements as $d)<option value="{{ $d }}">@endforeach</datalist>
    <datalist id="l-profils">@foreach($profils as $d)<option value="{{ $d }}">@endforeach</datalist>
    <datalist id="l-categories">@foreach($categories as $d)<option value="{{ $d }}">@endforeach</datalist>
    <datalist id="l-echelons">@foreach($echelons as $d)<option value="{{ $d }}">@endforeach</datalist>
    <datalist id="l-noms-contrats">@foreach(\App\Models\Salarie::CONTRATS as $l)<option value="Contrat {{ $l }}">@endforeach<option value="Avenant"></datalist>
    @if($e->exists)
        <form id="supprimer-employe" method="POST" action="{{ route('paie.salaries.destroy', $e) }}" onsubmit="return confirm('Supprimer définitivement la fiche de {{ addslashes($e->nomComplet()) }} et ses pièces jointes ?')">@csrf @method('DELETE')</form>
        @foreach($docs as $p)<form id="piece-{{ $p->id }}" method="POST" action="{{ route('paie.employes.piece.destroy', $p) }}" onsubmit="return confirm('Supprimer {{ addslashes($p->nom) }} ?')">@csrf @method('DELETE')</form>@endforeach
    @endif
</x-layouts.app>
