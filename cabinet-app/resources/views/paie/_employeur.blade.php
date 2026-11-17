{{-- Fichier → Renseignements de l'employeur : quatre onglets comme la fenêtre du logiciel de paie. --}}
@php
    $k = $client->paie_config ?? [];
    $G = \App\Models\Setting::paie();
    $v = fn ($c, $def = null) => old($c, $k[$c] ?? $def);
    $coche = fn ($c, $def = false) => old('_form') === 'employeur' ? (bool) old($c) : (bool) ($k[$c] ?? $def);
    [$cnssMat, $cnssCle] = array_pad(explode('-', (string) $client->matricule_cnss, 2), 2, '');
    $statuts = \App\Http\Controllers\PaieDossierController::STATUTS;
    if ($client->forme && ! isset($statuts[$client->forme])) $statuts = [$client->forme => $client->forme] + $statuts;
    $onglets = [
        'r' => ['nom', 'adresse', 'ville', 'code_postal', 'telephone', 'fax', 'email', 'cnss_mat', 'cnss_cle', 'paie_activite', 'site_web', 'nb_employes', 'heures_semaine'],
        'g' => ['responsable_nom', 'responsable_qualification', 'forme', 'rne', 'matricule_fiscal', 'paie_banque', 'agence', 'paie_rib', 'cabinet_paie'],
        'l' => ['logo'],
    ];
    $depart = 'r';
    if ($errors->any() && old('_form') === 'employeur') {
        $depart = 's';
        foreach ($onglets as $o => $champs) if ($errors->hasAny($champs)) { $depart = $o; break; }
    }
    $req = '<span class="text-rose-600">*</span>';
@endphp
@if($errors->any() && old('_form') === 'employeur')
    <div x-data x-init="$nextTick(() => $dispatch('open-modal', 'paie-employeur'))"></div>
@endif
<x-modal name="paie-employeur" :title="'Renseignements de l’employeur — '.$client->nom" size="max-w-4xl">
    <form method="POST" action="{{ route('paie.employeur') }}" enctype="multipart/form-data" x-data="{ t: '{{ $depart }}', regIr: false, regPr: false }">@csrf @method('PUT')
        <input type="hidden" name="_form" value="employeur">
        @if($errors->any() && old('_form') === 'employeur')
            <div class="mb-4 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">Certaines mentions obligatoires sont manquantes ou invalides : {{ implode(' ', array_slice($errors->all(), 0, 4)) }}</div>
        @endif
        <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
            @foreach(['r' => 'Renseignements', 'g' => 'Informations générales', 's' => 'Informations spécifiques', 'l' => 'Logo'] as $o => $l)
                <button type="button" @click="t = '{{ $o }}'" class="tab" :class="t === '{{ $o }}' && 'tab-active'">{{ $l }}</button>
            @endforeach
        </div>

        {{-- Renseignements --}}
        <div x-show="t === 'r'" class="grid gap-4 md:grid-cols-6">
            <x-field :label="new \Illuminate\Support\HtmlString('Raison sociale '.$req)" name="nom" :value="$client->nom" span="md:col-span-6"/>
            <x-field :label="new \Illuminate\Support\HtmlString('Adresse '.$req)" name="adresse" :value="$client->adresse" span="md:col-span-3"/>
            <x-field :label="new \Illuminate\Support\HtmlString('Ville '.$req)" name="ville" :value="$k['ville'] ?? null" span="md:col-span-2"/>
            <x-field label="Code postal" name="code_postal" :value="$k['code_postal'] ?? null"/>
            <x-field label="Téléphone" name="telephone" :value="$client->telephone" span="md:col-span-2"/>
            <x-field label="Fax" name="fax" :value="$k['fax'] ?? null" span="md:col-span-2"/>
            <x-field label="E-mail" name="email" type="email" :value="$client->email" span="md:col-span-2"/>
            <div class="md:col-span-3"><label class="label">N° employeur (matricule – clé) {!! $req !!}</label>
                <div class="flex items-center gap-2"><input name="cnss_mat" value="{{ old('cnss_mat', $cnssMat) }}" class="input flex-1"><span class="text-slate-400">–</span><input name="cnss_cle" value="{{ old('cnss_cle', $cnssCle) }}" class="input w-20" maxlength="4"></div>
                @error('cnss_mat')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror @error('cnss_cle')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <x-field :label="new \Illuminate\Support\HtmlString('Activité '.$req)" name="paie_activite" :value="$client->paie_activite" span="md:col-span-3"/>
            <x-field label="Site web" name="site_web" :value="$k['site_web'] ?? null" span="md:col-span-3"/>
            <x-field label="Nombre d’employés" name="nb_employes" type="number" min="0" :value="$k['nb_employes'] ?? null" :placeholder="$salaries->count()" span="md:col-span-2"/>
            <x-field label="Heures / semaine" name="heures_semaine" type="number" step="0.5" min="0" :value="$k['heures_semaine'] ?? null" placeholder="48"/>
        </div>

        {{-- Informations générales --}}
        <div x-show="t === 'g'" x-cloak class="grid gap-4 md:grid-cols-6">
            <fieldset class="grid gap-4 rounded-lg border border-slate-200 p-4 md:col-span-6 md:grid-cols-2 dark:border-slate-700">
                <legend class="px-1 text-xs font-semibold uppercase text-slate-400">Responsable</legend>
                <x-field :label="new \Illuminate\Support\HtmlString('Nom et prénom '.$req)" name="responsable_nom" :value="$k['responsable_nom'] ?? $client->gerant_nom"/>
                <x-field :label="new \Illuminate\Support\HtmlString('Qualification '.$req)" name="responsable_qualification" :value="$k['responsable_qualification'] ?? ($client->gerant_nom ? 'Gérant' : null)"/>
            </fieldset>
            <div class="md:col-span-6"><label class="label">Statut {!! $req !!}</label>
                <select name="forme" class="input"><option value="">—</option>@foreach($statuts as $c => $l)<option value="{{ $c }}" @selected(old('forme', $client->forme) === $c)>{{ $l }}</option>@endforeach</select>
                @error('forme')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <x-field label="RNE" name="rne" :value="$k['rne'] ?? null" span="md:col-span-3"/>
            <x-field :label="new \Illuminate\Support\HtmlString('Matricule fiscal '.$req)" name="matricule_fiscal" :value="$client->matricule_fiscal" span="md:col-span-3"/>
            <x-field label="Banque" name="paie_banque" :value="$client->paie_banque" span="md:col-span-2"/>
            <x-field label="Agence" name="agence" :value="$k['agence'] ?? null" span="md:col-span-2"/>
            <x-field label="Compte n° (RIB)" name="paie_rib" :value="$client->paie_rib" span="md:col-span-2"/>
            <x-field label="Nom du cabinet responsable de la gestion de la paie" name="cabinet_paie" :value="$k['cabinet_paie'] ?? $cab['nom']" span="md:col-span-6"/>
        </div>

        {{-- Informations spécifiques --}}
        <div x-show="t === 's'" x-cloak class="space-y-4">
            <div class="flex flex-wrap items-end gap-x-8 gap-y-3">
                <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="fop_actif" value="1" @checked($coche('fop_actif', true))> FOPROLOS
                    <input type="number" name="fop" step="0.01" min="0" value="{{ $v('fop', $G['fop']) }}" class="input-sm w-20"> %</label>
                <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="tfp_actif" value="1" @checked($coche('tfp_actif', true))> TFP
                    <select name="tfp" class="input-sm w-20">@foreach([1, 2] as $t)<option value="{{ $t }}" @selected((int) $v('tfp', $G['tfp']) === $t)>{{ $t }}</option>@endforeach</select> %</label>
                <label class="flex items-center gap-2 text-sm font-semibold">Arrondissement à <input type="number" name="arrondi" min="0" max="3" value="{{ $v('arrondi', 3) }}" class="input-sm w-16"> <span class="text-xs font-normal text-slate-500">chiffres après la virgule</span></label>
                <label class="flex items-center gap-2 text-sm font-semibold">Majoration H. sup. <input type="number" name="majoration_hs" step="0.01" min="0" value="{{ $v('majoration_hs', 25) }}" class="input-sm w-20"> %</label>
                <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="notif_demarrage" value="1" @checked($coche('notif_demarrage'))> Afficher la notification au démarrage</label>
            </div>
            <p class="-mt-2 text-xs text-slate-400">L’arrondi s’applique aux retenues (CNSS, IRPP, CSS) et aux charges patronales ; le net est le brut diminué des retenues.</p>

            <fieldset class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                <legend class="px-1 text-xs font-semibold uppercase text-slate-400">Régularisation</legend>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <div class="flex items-center justify-between gap-3"><span class="text-sm font-semibold">Régularisation mensuelle de l’IRPP</span><button type="button" @click="regIr = !regIr" class="btn-secondary btn-sm">…</button></div>
                        <div x-show="regIr" x-cloak class="mt-2 space-y-2 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
                            <label class="flex items-start gap-2"><input type="radio" name="regul_irpp" value="0" class="mt-1" @checked(! $v('regul_irpp', false))> <span><b>Sans régularisation</b> : l’IRPP du mois est calculé sur le salaire du mois annualisé (× 12).</span></label>
                            <label class="flex items-start gap-2"><input type="radio" name="regul_irpp" value="1" class="mt-1" @checked((bool) $v('regul_irpp', false))> <span><b>Régularisation mensuelle</b> : l’IRPP est calculé chaque mois sur le cumul imposable de l’année, diminué des retenues déjà opérées.</span></label>
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between gap-3"><span class="text-sm font-semibold">Régularisation sur les primes / autres / rappel</span><button type="button" @click="regPr = !regPr" class="btn-secondary btn-sm">…</button></div>
                        <div x-show="regPr" x-cloak class="mt-2 space-y-2 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
                            <label class="flex items-start gap-2"><input type="radio" name="regul_primes" value="difference" class="mt-1" @checked($v('regul_primes', 'difference') !== 'regul')> <span><b>Sans régularisation</b> : l’IRPP d’une prime, d’une paie Autre ou d’un rappel est calculé par différence (impôt annuel avec et sans l’élément).</span></label>
                            <label class="flex items-start gap-2"><input type="radio" name="regul_primes" value="regul" class="mt-1" @checked($v('regul_primes', 'difference') === 'regul')> <span><b>Régularisation</b> : l’élément entre dans le cumul imposable de l’année ; l’IRPP dû sur le cumul annualisé est diminué des retenues déjà opérées.</span></label>
                            <p class="text-xs text-slate-400">Réglage par défaut des nouveaux employés : chaque salarié peut être coché ou décoché sur sa fiche ou dans Saisie Paie (colonnes Reg et RegP).</p>
                        </div>
                    </div>
                </div>
            </fieldset>

            <div class="grid gap-4 md:grid-cols-2">
                <fieldset class="grid grid-cols-2 gap-4 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                    <legend class="px-1 text-xs font-semibold uppercase text-slate-400">Taux de la CNSS</legend>
                    <div><label class="label">CNSS salariale {!! $req !!}</label><div class="flex items-center gap-2"><input name="cnssS" type="number" step="0.01" list="taux-cnss" value="{{ $v('cnssS', $G['cnssS']) }}" class="input"> %</div>
                        <datalist id="taux-cnss"><option value="9.68"><option value="9.18"></datalist>@error('cnssS')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                    <div><label class="label">Accident de travail {!! $req !!}</label><div class="flex items-center gap-2"><input name="at" type="number" step="0.01" min="0" value="{{ $v('at', $G['at'] ?? 0.5) }}" class="input"> %</div>@error('at')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                    <p class="col-span-2 text-xs text-slate-400">CNSS patronale appliquée : {{ number_format($G['cnssP'] - ($G['at'] ?? 0), 2, ',', ' ') }} % + accident de travail.</p>
                </fieldset>
                <fieldset class="space-y-3 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                    <legend class="px-1 text-xs font-semibold uppercase text-slate-400">Options</legend>
                    <div class="grid grid-cols-2 items-center gap-2"><label class="text-sm font-semibold">Calcul solde congé</label>
                        <select name="solde_conge" class="input-sm"><option value="">—</option><option value="dernier" @selected($v('solde_conge') === 'dernier')>Sur le dernier salaire</option><option value="moyenne" @selected($v('solde_conge') === 'moyenne')>Moyenne des 12 derniers mois</option></select></div>
                    <div class="grid grid-cols-2 items-center gap-2"><label class="text-sm font-semibold">Trier par {!! $req !!}</label>
                        <select name="tri" class="input-sm"><option value="nom" @selected($v('tri', 'nom') === 'nom')>Nom</option><option value="matricule" @selected($v('tri') === 'matricule')>Matricule</option></select></div>
                    <div class="grid grid-cols-2 items-center gap-2"><label class="text-sm font-semibold">Format fiche de paie</label>
                        <select name="format_fiche" class="input-sm"><option value="">—</option><option value="a4" @selected($v('format_fiche') === 'a4')>A4</option><option value="a5" @selected($v('format_fiche') === 'a5')>A5</option></select></div>
                </fieldset>
            </div>

            <fieldset class="grid gap-4 rounded-lg border border-slate-200 p-4 md:grid-cols-3 dark:border-slate-700">
                <legend class="px-1 text-xs font-semibold uppercase text-slate-400">SMIG</legend>
                <x-field :label="new \Illuminate\Support\HtmlString('SMIG mensuel '.$req)" name="smig_mensuel" type="number" step="0.001" min="0" :value="$k['smig_mensuel'] ?? null"/>
                <x-field :label="new \Illuminate\Support\HtmlString('SMIG horaire '.$req)" name="smig_horaire" type="number" step="0.001" min="0" :value="$k['smig_horaire'] ?? null"/>
                <x-field label="Plafond SMIG régime complémentaire (× 6)" name="plafond_rc" type="number" step="0.001" min="0" :value="$k['plafond_rc'] ?? null"/>
                <x-field label="Régime complémentaire : taux salarié (%)" name="rc_taux_s" type="number" step="0.001" min="0" :value="$k['rc_taux_s'] ?? null"/>
                <x-field label="Régime complémentaire : taux employeur (%)" name="rc_taux_p" type="number" step="0.001" min="0" :value="$k['rc_taux_p'] ?? null"/>
            </fieldset>
        </div>

        {{-- Logo --}}
        <div x-show="t === 'l'" x-cloak class="space-y-4">
            <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="afficher_logo" value="1" @checked($coche('afficher_logo'))> Afficher le logo dans les états</label>
            <div class="flex flex-wrap items-center gap-6">
                <div class="flex h-28 w-48 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                    @if(!empty($k['logo']))<img src="{{ asset('storage/'.$k['logo']) }}" alt="Logo" class="max-h-24 max-w-44">@else<span class="text-xs text-slate-400">Aucun logo</span>@endif
                </div>
                <div class="space-y-3">
                    <label class="btn-secondary cursor-pointer"><x-icon name="plus" class="h-4 w-4"/> Ajouter logo<input type="file" name="logo" accept="image/*" class="hidden" @change="$refs.nomLogo.textContent = $event.target.files[0]?.name ?? ''"></label>
                    @if(!empty($k['logo']))<button name="retirer_logo" value="1" class="btn-secondary" onclick="return confirm('Supprimer le logo ?')"><x-icon name="trash" class="h-4 w-4"/> Supprimer logo</button>@endif
                    <div x-ref="nomLogo" class="text-xs text-slate-500"></div>
                    @error('logo')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <p class="text-xs text-slate-400">Le logo remplace celui du cabinet sur les états de paie (journal, déclarations, ordre de virement…) et apparaît sur les bulletins.</p>
        </div>

        <div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
            <span class="text-xs text-rose-600">* Mention obligatoire</span>
            <div class="flex gap-2">
                <button type="button" @click="$dispatch('close-modal')" class="btn-secondary"><x-icon name="x" class="h-4 w-4"/> Annuler</button>
                <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Valider</button>
            </div>
        </div>
    </form>
</x-modal>
