{{--
    Paie → Saisie Autre : paies spéciales (sur le modèle de l'écran « Saisie de la paie » du logiciel de référence).
    Mois : Congé, 13ème… 18ème mois ; Année ; À déclarer avec : mois dans lequel la paie est déclarée (CNSS, retenues, déclaration employeur).
    13ème à 18ème mois : montant = salaire de base par défaut. Congé : jours de congé × taux journalier.
    IRPP et CSS calculés par différence sur l'année (voir PaieCalc::speciale).
--}}
@php
    $kc = $client->paie_config ?? [];
    $lockS = $speciale && $speciale->statut !== 'brouillon';
    $conge = $type === 'conge';
    $nomsMois = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    $pt = fn ($id, $k) => (($speciale?->pointage ?? [])[$id][$k] ?? null);
    $nb = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $parId = collect($RS)->keyBy('salarie_id');
    // Tous les salariés présents le mois de déclaration : ceux qui ne sont pas encore payés apparaissent avec une saisie vide.
    $fin = \Carbon\Carbon::parse($decl.'-01')->endOfMonth();
    $lignes = $salaries->filter(fn ($e) => (! $e->date_embauche || $e->date_embauche->lte($fin)) && \App\Services\PaieCalc::payeLeMois($e, $decl))->values();
    $etat = ! $speciale ? ['Mois non encore enregistré', 'text-rose-600'] : ($lockS ? ['Paie clôturée — '.(['transmise' => 'transmise à la comptabilité', 'val' => 'comptabilisée'][$speciale->statut] ?? ''), 'text-emerald-700'] : ['Enregistré (brouillon)', 'text-amber-600']);
    $cle = ['type' => $type, 'mois' => $decl];
    $aller = fn ($t, $a, $d = null) => route('paie.index', ['tab' => 'autre', 'type' => $t, 'annee' => $a, 'decl' => $d, 'mois' => $mois]);
@endphp

<div x-data="{ q: '' }">
    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <div class="card card-b">
            <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Exercice</div>
            <div class="flex flex-wrap items-center gap-3" x-data="{ t: @js($type), a: {{ $annee }}, d: {{ (int) substr($decl, 5, 2) }},
                    aller(garderDecl) { location.href = @js(route('paie.index', ['tab' => 'autre'])) + '&type=' + this.t + '&annee=' + this.a + (garderDecl ? '&decl=' + this.a + '-' + String(this.d).padStart(2, '0') : '') } }">
                <label class="text-sm font-semibold">Mois*</label>
                <select x-model="t" @change="aller(false)" class="input-sm w-36">@foreach(\App\Models\Paie::SPECIALES as $t)<option value="{{ $t }}">{{ \App\Models\Paie::TYPES[$t] }}</option>@endforeach</select>
                <label class="text-sm font-semibold">Année*</label>
                <input type="number" x-model.number="a" @change="aller(false)" min="2000" max="2100" class="input-sm w-24">
                <label class="text-sm font-semibold">À déclarer avec*</label>
                <select x-model.number="d" @change="aller(true)" class="input-sm w-36">@foreach($nomsMois as $i => $n)<option value="{{ $i + 1 }}">{{ $n }}</option>@endforeach</select>
            </div>
            @if($existantes->count() > 1 || ($existantes->count() === 1 && ! $speciale))
                <div class="mt-2 flex flex-wrap gap-1 text-xs"><span class="text-slate-500">{{ \App\Models\Paie::TYPES[$type] }} {{ $annee }} déjà saisis :</span>
                    @foreach($existantes as $p)<a href="{{ $aller($type, $annee, $p->mois) }}" class="rounded px-1.5 {{ $p->mois === $decl ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300' }}">{{ mois_fr($p->mois) }}</a>@endforeach</div>
            @endif
            <div class="mt-3"><input type="search" x-model="q" placeholder="Rechercher (nom, matricule)…" class="input-sm w-60"></div>
        </div>
        <div class="card card-b" x-data="{ jo: @js($nb($speciale?->jours_ouvrables ?? $PS['jours_mois'])), hj: @js($nb($speciale?->heures_jour ?? $PS['heures_jour'])), ho: @js($nb($speciale?->heures_ouvrables ?? $PS['heures_mois'])) }">
            <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Jours</div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. jrs ouv.* <input form="form-autre" type="number" step="0.5" min="0" max="31" name="jours_ouvrables" x-model="jo" @input="ho = +(jo * hj).toFixed(2)" class="input-sm w-20 text-right" @disabled($lockS) required></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. jr férié <input form="form-autre" type="number" step="0.5" min="0" max="31" name="jours_feries" value="{{ $nb($speciale?->jours_feries) }}" class="input-sm w-20 text-right" @disabled($lockS)></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. hrs/jr <input form="form-autre" type="number" step="0.25" min="0" max="24" name="heures_jour" x-model="hj" @input="ho = +(jo * hj).toFixed(2)" class="input-sm w-20 text-right" @disabled($lockS)></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. hrs ouv.* <input form="form-autre" type="number" step="0.01" min="0" name="heures_ouvrables" x-model="ho" class="input-sm w-20 text-right" @disabled($lockS) required></label>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="text-rose-500">* Mention obligatoire</span>
                <span class="flex items-center gap-1 font-semibold {{ $etat[1] }}"><x-icon name="alert" class="h-4 w-4"/> {{ $etat[0] }}</span>
            </div>
        </div>
    </div>

    <p class="mb-2 text-xs text-slate-500">
        <b>{{ \App\Models\Paie::TYPES[$type] }} {{ $annee }}</b>, déclaré avec <b>{{ mois_fr($decl) }}</b>.
        @if($conge) Saisissez les jours de congé payés : le montant vaut jours × taux journalier (salaire de base ÷ jours ouvrables), sauf montant saisi.
        @else Le montant vaut le salaire de base, sauf montant saisi ; saisissez 0 pour un salarié qui n’en bénéficie pas. @endif
        IRPP et CSS sont calculés par différence sur l’année avec le salaire de {{ mois_fr($decl) }}.
    </p>

    <form method="POST" action="{{ route('paie.calcul') }}" id="form-autre" class="card overflow-x-auto">@csrf
        <input type="hidden" name="mois" value="{{ $decl }}"><input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="parties[]" value="saisie">
        <table class="tbl text-xs">
            <thead><tr>
                <th>N°</th><th>Matricule</th><th>Employé</th><th class="text-right">Net</th><th class="text-right">S. Brut</th><th class="text-right">S. Base</th>
                <th class="text-right" title="Taux journalier : salaire de base ÷ jours ouvrables">Taux</th>
                @if($conge)<th class="text-right" title="Jours de congé payés">Congé (j)</th>@endif
                <th class="text-right" title="Brut de la paie spéciale — vide : valeur par défaut">Montant</th>
                <th class="text-right">Primes</th><th class="text-right" title="Acompte déjà versé, déduit du net">Acom</th>
                <th class="text-right">CNSS</th><th class="text-right">IRPP</th><th class="text-right">CSS</th>
            </tr></thead>
            <tbody>
            @forelse($lignes as $i => $e)
                @php $x = $parId[$e->id] ?? null; $id = $e->id; $base = $e->regime === 'horaire' ? (float) $e->salaire_base * (float) $PS['heures_mois'] : (float) $e->salaire_base; @endphp
                <tr x-show="@js(mb_strtolower($e->nomComplet().' '.$e->matricule)).includes(q.toLowerCase())" class="{{ $x ? '' : 'text-slate-400' }}">
                    <td>{{ $i + 1 }}</td><td class="font-mono">{{ $e->matricule }}</td>
                    <td class="whitespace-nowrap font-medium">{{ $e->nomComplet() }}</td>
                    <td class="num font-semibold text-emerald-700 dark:text-emerald-400">{{ $x ? n3($x['net']) : '' }}</td>
                    <td class="num">{{ $x ? n3($x['brut']) : '' }}</td>
                    <td class="num">{{ n3($base) }}</td>
                    <td class="num text-slate-500">{{ n3($PS['jours_mois'] > 0 ? $base / $PS['jours_mois'] : 0) }}</td>
                    @if($lockS)
                        @if($conge)<td class="num">{{ $nb($x['pt_conge'] ?? null) ?: '—' }}</td>@endif
                        <td class="num">{{ $x ? n3($x['salaire']) : '—' }}</td><td class="num">{{ $x && $x['prime'] ? n3($x['prime']) : '—' }}</td><td class="num">{{ $x && $x['acompte'] ? n3($x['acompte']) : '—' }}</td>
                    @else
                        @if($conge)<td><input type="number" step="0.5" min="0" name="pointage[{{ $id }}][jours]" value="{{ $nb($pt($id, 'jours')) }}" class="input-sm ml-auto w-16 text-right"></td>@endif
                        <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][montant]" value="{{ $nb($pt($id, 'montant')) }}" placeholder="{{ $conge ? '' : $nb($base) }}" class="input-sm ml-auto w-28 text-right"></td>
                        <td><input type="number" step="0.001" name="primes[{{ $id }}]" value="{{ $nb(($speciale?->primes ?? [])[$id] ?? null) }}" class="input-sm ml-auto w-24 text-right"></td>
                        <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][acompte]" value="{{ $nb($pt($id, 'acompte')) }}" class="input-sm ml-auto w-24 text-right"></td>
                    @endif
                    <td class="num">{{ $x ? n3($x['cnssS']) : '' }}</td><td class="num">{{ $x ? n3($x['ir']) : '' }}</td><td class="num">{{ $x ? n3($x['css']) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="14"><x-empty icon="users" title="Aucun salarié présent en {{ mois_fr($decl) }}"/></td></tr>
            @endforelse
            </tbody>
            @if(count($RS))
                <tfoot class="border-t-2 border-slate-200 font-semibold"><tr>
                    <td colspan="3">Total ({{ count($RS) }})</td><td class="num">{{ n3($TS['net']) }}</td><td class="num">{{ n3($TS['brut']) }}</td><td colspan="{{ $conge ? 6 : 5 }}"></td>
                    <td class="num">{{ n3($TS['cnssS']) }}</td><td class="num">{{ n3($TS['ir']) }}</td><td class="num">{{ n3($TS['css']) }}</td>
                </tr></tfoot>
            @endif
        </table>
    </form>

    <form id="form-autre-supprimer" method="POST" action="{{ route('paie.supprimer') }}" onsubmit="return confirm('Supprimer cette paie spéciale ?')">@csrf<input type="hidden" name="mois" value="{{ $decl }}"><input type="hidden" name="type" value="{{ $type }}"></form>
    <form id="form-autre-cloture" method="POST" action="{{ route('paie.cloturer') }}" onsubmit="return confirm('Clôturer cette paie spéciale et la transmettre à la comptabilité ?')">@csrf<input type="hidden" name="mois" value="{{ $decl }}"><input type="hidden" name="type" value="{{ $type }}"></form>
    <div class="mt-3 flex flex-wrap items-stretch gap-3">
        <div class="card card-b grid grid-cols-[auto_6rem] items-center gap-x-3 gap-y-1.5 text-sm">
            <span class="font-semibold">SMIG mensuel</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ isset($kc['smig_mensuel']) ? n3($kc['smig_mensuel']) : '—' }}</span>
            <span class="font-semibold">SMIG horaire</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ isset($kc['smig_horaire']) ? n3($kc['smig_horaire']) : '—' }}</span>
            <span class="font-semibold">CNSS salariale</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $PS['cnssS'] }} %</span>
            <span class="font-semibold">Arrondissement</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $PS['arrondi'] }}</span>
        </div>
        <div class="card card-b flex flex-col justify-center gap-2">
            <button type="button" x-data @click="$dispatch('open-modal', 'paie-calculatrice'); $dispatch('paie-net-brut')" class="btn-secondary btn-sm justify-start"><x-icon name="arrow-left" class="h-4 w-4"/> À l’envers</button>
            <button form="form-autre" class="btn-secondary btn-sm justify-start" @disabled($lockS)><x-icon name="copy" class="h-4 w-4"/> Recalculer</button>
        </div>
        <div class="card card-b grid grid-cols-2 gap-2">
            <button form="form-autre" class="btn-primary btn-sm justify-start" @disabled($lockS)><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
            <button form="form-autre-cloture" class="btn-secondary btn-sm justify-start" @disabled($lockS || ! count($RS))><x-icon name="lock" class="h-4 w-4"/> Clôture</button>
            <button form="form-autre-supprimer" class="btn-secondary btn-sm justify-start text-rose-600" @disabled($lockS || ! $speciale)><x-icon name="x" class="h-4 w-4"/> Supprimer</button>
            <a href="{{ route('paie.bulletins', ['type' => $type, 'mois' => $decl, 'format' => 'a4']) }}" target="_blank" class="btn-secondary btn-sm justify-start"><x-icon name="printer" class="h-4 w-4"/> Imprimer</a>
        </div>
        @php
            $edS = fn ($e, $extra = [], $f = 'imprimer') => route('paie.edition', [$e, $f, 'mois' => $decl, 'paie' => $type] + $extra);
            $editionsS = [
                ['Bulletins A5 Double', route('paie.bulletins', ['type' => $type, 'mois' => $decl, 'format' => 'a5d'])],
                ['Journal', $edS('journal')], ['Journal détaillé', $edS('journal', ['modele' => 'detaille'])],
                ['Bordereau payeur', $edS('bordereau')], ['Bordereau espèces', $edS('bordereau', ['mode' => 'especes'])],
                ['Ordre de virement', $edS('virement')], ['Virement par employé', route('paie.virement.employes', ['mois' => $decl, 'paie' => $type])],
                ['Fichier de virement .txt', route('paie.virement.fichier', ['txt', 'mois' => $decl, 'paie' => $type]), false],
                ['Enveloppes', route('paie.enveloppes', ['mois' => $decl, 'paie' => $type])], ['Récapitulation', $edS('recap')],
            ];
        @endphp
        <div class="card card-b">
            <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Éditions — {{ \App\Models\Paie::TYPES[$type] }} {{ $annee }}</div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                @foreach($editionsS as $ed)<a href="{{ $ed[1] }}" @if($ed[2] ?? true) target="_blank" @endif class="text-brand-700 hover:underline dark:text-brand-300">{{ $ed[0] }}</a>@endforeach
            </div>
        </div>
        @if($lockS && $speciale->statut === 'transmise' && auth()->user()->estChef())
            <form method="POST" action="{{ route('paie.rouvrir') }}" class="flex items-center" onsubmit="return confirm('Rouvrir cette paie spéciale ?')">@csrf
                <input type="hidden" name="mois" value="{{ $decl }}"><input type="hidden" name="type" value="{{ $type }}">
                <button class="btn-secondary"><x-icon name="lock" class="h-4 w-4"/> Rouvrir</button></form>
        @endif
    </div>
</div>
