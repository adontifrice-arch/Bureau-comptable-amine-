{{--
    Menu Prime → Saisie Prime : paie d'une prime (rubrique de prime du dossier) déclarée avec un mois.
    Montant par défaut d'après la rubrique (montant fixe, pourcentage ou proportion du salaire de base ; 0 en saisie manuelle),
    CNSS et IRPP selon les cases « non cotisable » et « non imposable » de la rubrique, IRPP et CSS par différence sur l'année.
--}}
@php
    $kc = $client->paie_config ?? [];
    $lockP = $prime && $prime->exists && $prime->statut !== 'brouillon';
    $nomsMois = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    $pt = fn ($id, $k) => (($prime?->pointage ?? [])[$id][$k] ?? null);
    $nb = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $parId = collect($RP)->keyBy('salarie_id');
    $rub = $prime ? $rubriquesPrime->firstWhere('id', $prime->rubrique_id) : null;
    $decl = $prime?->mois ?? $mois;
    $fin = \Carbon\Carbon::parse($decl.'-01')->endOfMonth();
    $lignes = $salaries->filter(fn ($e) => (! $e->date_embauche || $e->date_embauche->lte($fin)) && \App\Services\PaieCalc::payeLeMois($e, $decl) && (! $rub || $rub->concerne($e)))->values();
    $etat = ! $prime?->exists ? ['Prime non encore enregistrée', 'text-rose-600'] : ($lockP ? ['Prime clôturée — '.(['transmise' => 'transmise à la comptabilité', 'val' => 'comptabilisée'][$prime->statut] ?? ''), 'text-emerald-700'] : ['Enregistrée (brouillon)', 'text-amber-600']);
    $ident = $prime?->exists ? ['paie_id' => $prime->id] : ['type' => 'prime', 'mois' => $decl, 'rubrique_id' => $prime?->rubrique_id];
@endphp

@if(! $prime)
    <div class="card"><x-empty icon="sparkles" title="Aucune rubrique de prime dans le dossier">Créez d’abord la prime dans Rubrique → Ajouter : les primes (ou complétez avec la liste standard).</x-empty></div>
@else
<div x-data="{ q: '' }">
    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <div class="card card-b">
            <div class="mb-2 flex items-center justify-between"><span class="text-xs font-semibold uppercase text-slate-400">Prime</span>
                <a href="{{ route('paie.index', ['tab' => 'prime', 'nouvelle' => 1, 'mois' => $mois]) }}" class="text-xs text-brand-600 hover:underline">+ Nouvelle prime</a></div>
            @if($prime->exists)
                <div class="text-base font-semibold">{{ $prime->libelle }}</div>
                <div class="text-sm text-slate-500">Déclarée avec {{ mois_fr($prime->mois) }}</div>
            @else
                <div class="flex flex-wrap items-center gap-3" x-data="{ r: {{ (int) $prime->rubrique_id }}, m: {{ (int) substr($decl, 5, 2) }}, a: {{ (int) substr($decl, 0, 4) }},
                        aller() { location.href = @js(route('paie.index', ['tab' => 'prime', 'nouvelle' => 1])) + '&rubrique=' + this.r + '&decl=' + this.a + '-' + String(this.m).padStart(2, '0') } }">
                    <label class="text-sm font-semibold">Prime*</label>
                    <select x-model.number="r" @change="aller()" class="input-sm w-64">
                        @foreach($rubriquesPrime->groupBy('categorie') as $cat => $grp)
                            <optgroup label="{{ \App\Models\PaieRubrique::CATEGORIES[$cat] ?? $cat }}">@foreach($grp as $rb)<option value="{{ $rb->id }}">{{ $rb->nom }}</option>@endforeach</optgroup>
                        @endforeach
                    </select>
                    <label class="text-sm font-semibold">À déclarer avec*</label>
                    <select x-model.number="m" @change="aller()" class="input-sm w-32">@foreach($nomsMois as $i => $n)<option value="{{ $i + 1 }}">{{ $n }}</option>@endforeach</select>
                    <input type="number" x-model.number="a" @change="aller()" min="2000" max="2100" class="input-sm w-24">
                </div>
            @endif
            @if($rub)<div class="mt-2 text-xs text-slate-500">Valeur de la rubrique : {{ $rub->libelleValeur($rubriques) }}{{ $rub->non_cotisable ? ' · non cotisable' : '' }}{{ $rub->non_imposable ? ' · non imposable' : '' }}</div>@endif
            <div class="mt-3"><input type="search" x-model="q" placeholder="Rechercher (nom, matricule)…" class="input-sm w-60"></div>
        </div>
        <div class="card card-b" x-data="{ jo: @js($nb($prime->jours_ouvrables ?? $PP['jours_mois'])), hj: @js($nb($prime->heures_jour ?? $PP['heures_jour'])), ho: @js($nb($prime->heures_ouvrables ?? $PP['heures_mois'])) }">
            <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Jours</div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. jrs ouv.* <input form="form-prime" type="number" step="0.5" min="0" max="31" name="jours_ouvrables" x-model="jo" @input="ho = +(jo * hj).toFixed(2)" class="input-sm w-20 text-right" @disabled($lockP) required></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. hrs/jr <input form="form-prime" type="number" step="0.25" min="0" max="24" name="heures_jour" x-model="hj" @input="ho = +(jo * hj).toFixed(2)" class="input-sm w-20 text-right" @disabled($lockP)></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. hrs ouv.* <input form="form-prime" type="number" step="0.01" min="0" name="heures_ouvrables" x-model="ho" class="input-sm w-20 text-right" @disabled($lockP) required></label>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="text-rose-500">* Mention obligatoire</span>
                <span class="flex items-center gap-1 font-semibold {{ $etat[1] }}"><x-icon name="alert" class="h-4 w-4"/> {{ $etat[0] }}</span>
            </div>
        </div>
    </div>

    <p class="mb-2 text-xs text-slate-500">Le montant vaut la valeur de la rubrique, sauf montant saisi ; saisissez 0 pour un salarié qui n’en bénéficie pas. IRPP et CSS sont calculés par différence sur l’année avec le salaire de {{ mois_fr($decl) }}.</p>

    <form method="POST" action="{{ route('paie.calcul') }}" id="form-prime" class="card overflow-x-auto">@csrf
        @foreach($ident as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <input type="hidden" name="parties[]" value="saisie">
        <table class="tbl text-xs">
            <thead><tr>
                <th>N°</th><th>Matricule</th><th>Employé</th><th class="text-right">Net</th><th class="text-right">S. Brut</th><th class="text-right">S. Base</th>
                <th class="text-right" title="Taux journalier : salaire de base ÷ jours ouvrables">Taux</th>
                <th class="text-right" title="Montant de la prime — vide : valeur de la rubrique">Montant</th><th class="text-right" title="Acompte déjà versé, déduit du net">Acom</th>
                <th class="text-right">CNSS</th><th class="text-right">IRPP</th><th class="text-right">CSS</th>
            </tr></thead>
            <tbody>
            @forelse($lignes as $i => $e)
                @php $x = $parId[$e->id] ?? null; $id = $e->id; $base = $e->regime === 'horaire' ? (float) $e->salaire_base * (float) $PP['heures_mois'] : (float) $e->salaire_base; @endphp
                <tr x-show="@js(mb_strtolower($e->nomComplet().' '.$e->matricule)).includes(q.toLowerCase())" class="{{ $x ? '' : 'text-slate-400' }}">
                    <td>{{ $i + 1 }}</td><td class="font-mono">{{ $e->matricule }}</td><td class="whitespace-nowrap font-medium">{{ $e->nomComplet() }}</td>
                    <td class="num font-semibold text-emerald-700 dark:text-emerald-400">{{ $x ? n3($x['net']) : '' }}</td><td class="num">{{ $x ? n3($x['brut']) : '' }}</td>
                    <td class="num">{{ n3($base) }}</td><td class="num text-slate-500">{{ n3($PP['jours_mois'] > 0 ? $base / $PP['jours_mois'] : 0) }}</td>
                    @if($lockP)
                        <td class="num">{{ $x ? n3($x['salaire']) : '—' }}</td><td class="num">{{ $x && $x['acompte'] ? n3($x['acompte']) : '—' }}</td>
                    @else
                        <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][montant]" value="{{ $nb($pt($id, 'montant')) }}" placeholder="{{ $nb(\App\Services\PaieCalc::montantPrime($rub, $base)) }}" class="input-sm ml-auto w-28 text-right"></td>
                        <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][acompte]" value="{{ $nb($pt($id, 'acompte')) }}" class="input-sm ml-auto w-24 text-right"></td>
                    @endif
                    <td class="num">{{ $x ? n3($x['cnssS']) : '' }}</td><td class="num">{{ $x ? n3($x['ir']) : '' }}</td><td class="num">{{ $x ? n3($x['css']) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="12"><x-empty icon="users" title="Aucun salarié concerné par cette prime en {{ mois_fr($decl) }}"/></td></tr>
            @endforelse
            </tbody>
            @if(count($RP))
                <tfoot class="border-t-2 border-slate-200 font-semibold"><tr>
                    <td colspan="3">Total ({{ count($RP) }})</td><td class="num">{{ n3($TP['net']) }}</td><td class="num">{{ n3($TP['brut']) }}</td><td colspan="4"></td>
                    <td class="num">{{ n3($TP['cnssS']) }}</td><td class="num">{{ n3($TP['ir']) }}</td><td class="num">{{ n3($TP['css']) }}</td>
                </tr></tfoot>
            @endif
        </table>
    </form>

    @if($prime->exists)
        <form id="form-prime-supprimer" method="POST" action="{{ route('paie.supprimer') }}" onsubmit="return confirm('Supprimer cette prime ?')">@csrf<input type="hidden" name="paie_id" value="{{ $prime->id }}"></form>
        <form id="form-prime-cloture" method="POST" action="{{ route('paie.cloturer') }}" onsubmit="return confirm('Clôturer cette prime et la transmettre à la comptabilité ?')">@csrf<input type="hidden" name="paie_id" value="{{ $prime->id }}"></form>
    @endif
    <div class="mt-3 flex flex-wrap items-stretch gap-3">
        <div class="card card-b grid grid-cols-[auto_6rem] items-center gap-x-3 gap-y-1.5 text-sm">
            <span class="font-semibold">SMIG mensuel</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ isset($kc['smig_mensuel']) ? n3($kc['smig_mensuel']) : '—' }}</span>
            <span class="font-semibold">CNSS salariale</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $PP['cnssS'] }} %</span>
            <span class="font-semibold">Arrondissement</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $PP['arrondi'] }}</span>
        </div>
        <div class="card card-b grid grid-cols-2 gap-2">
            <button form="form-prime" class="btn-primary btn-sm justify-start" @disabled($lockP)><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
            <button form="form-prime" class="btn-secondary btn-sm justify-start" @disabled($lockP)><x-icon name="copy" class="h-4 w-4"/> Recalculer</button>
            <button form="form-prime-cloture" class="btn-secondary btn-sm justify-start" @disabled($lockP || ! $prime->exists || ! count($RP))><x-icon name="lock" class="h-4 w-4"/> Clôture</button>
            <button form="form-prime-supprimer" class="btn-secondary btn-sm justify-start text-rose-600" @disabled($lockP || ! $prime->exists)><x-icon name="x" class="h-4 w-4"/> Supprimer</button>
            @if($prime->exists)<a href="{{ route('paie.bulletins', ['paie' => $prime->id, 'format' => 'a4']) }}" target="_blank" class="btn-secondary btn-sm col-span-2 justify-start"><x-icon name="printer" class="h-4 w-4"/> Imprimer les bulletins</a>@endif
        </div>
        @if($lockP && $prime->statut === 'transmise' && auth()->user()->estChef())
            <form method="POST" action="{{ route('paie.rouvrir') }}" class="flex items-center" onsubmit="return confirm('Rouvrir cette prime ?')">@csrf
                <input type="hidden" name="paie_id" value="{{ $prime->id }}"><input type="hidden" name="mois" value="{{ $prime->mois }}">
                <button class="btn-secondary"><x-icon name="lock" class="h-4 w-4"/> Rouvrir</button></form>
        @endif
        @unless($prime->exists)<p class="self-center text-xs text-slate-400">Enregistrez la prime pour pouvoir la clôturer et l’éditer (menu Prime).</p>@endunless
    </div>
</div>
@endif
