{{--
    Menu Rappel → Saisie Rappel : rappel de salaire (augmentation rétroactive) d'une période, déclaré avec un mois.
    Par salarié : ancien salaire (historique de la fiche), nouveau salaire (salaire actuel ou ancien + augmentation de tête), nombre de mois
    de la période, montant = (nouveau − ancien) × nombre de mois. CNSS sur le rappel, IRPP et CSS par différence sur l'année.
--}}
@php
    $kc = $client->paie_config ?? [];
    $lockR = $rappel->exists && $rappel->statut !== 'brouillon';
    $o = $rappel->options ?? [];
    $pt = fn ($id, $k) => (($rappel->pointage ?? [])[$id][$k] ?? null);
    $nb = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $parId = collect($RR)->keyBy('salarie_id');
    $decl = $rappel->mois;
    $fin = \Carbon\Carbon::parse($decl.'-01')->endOfMonth();
    $lignes = $salaries->filter(fn ($e) => (! $e->date_embauche || $e->date_embauche->lte($fin)) && \App\Services\PaieCalc::payeLeMois($e, $decl))->values();
    $etat = ! $rappel->exists ? ['Rappel non encore enregistré', 'text-rose-600'] : ($lockR ? ['Rappel clôturé — '.(['transmise' => 'transmis à la comptabilité', 'val' => 'comptabilisé'][$rappel->statut] ?? ''), 'text-emerald-700'] : ['Enregistré (brouillon)', 'text-amber-600']);
@endphp

<div x-data="{ q: '' }">
    <form method="POST" action="{{ route('paie.calcul') }}" id="form-rappel">@csrf
        @if($rappel->exists)<input type="hidden" name="paie_id" value="{{ $rappel->id }}">@else<input type="hidden" name="type" value="rappel">@endif
        <input type="hidden" name="parties[]" value="saisie">
        <div class="mb-3 grid gap-3 lg:grid-cols-2">
            <div class="card card-b">
                <div class="mb-2 flex items-center justify-between"><span class="text-xs font-semibold uppercase text-slate-400">Rappel</span>
                    <a href="{{ route('paie.index', ['tab' => 'rappel', 'nouveau' => 1, 'mois' => $mois]) }}" class="text-xs text-brand-600 hover:underline">+ Nouveau rappel</a></div>
                <div class="grid gap-2 text-sm sm:grid-cols-2">
                    <label class="sm:col-span-2"><span class="font-semibold">Libellé*</span>
                        <input name="libelle" value="{{ old('libelle', $rappel->libelle) }}" placeholder="Rappel augmentation {{ substr($decl, 0, 4) }}" class="input-sm mt-1 w-full" @disabled($lockR) required></label>
                    <label><span class="font-semibold">Période rappelée : du*</span><input type="month" name="du" value="{{ old('du', $o['du'] ?? '') }}" class="input-sm mt-1 w-full" @disabled($lockR) required></label>
                    <label><span class="font-semibold">au*</span><input type="month" name="au" value="{{ old('au', $o['au'] ?? '') }}" class="input-sm mt-1 w-full" @disabled($lockR) required></label>
                    <label><span class="font-semibold">À déclarer avec*</span><input type="month" name="mois" value="{{ $decl }}" class="input-sm mt-1 w-full" @disabled($lockR || $rappel->exists) required></label>
                    <label><span class="font-semibold">Augmentation</span>
                        <span class="mt-1 flex gap-1"><input type="number" step="0.001" min="0" name="augmentation" value="{{ $nb($o['augmentation'] ?? null) }}" class="input-sm w-full text-right" @disabled($lockR)>
                            <select name="unite" class="input-sm w-20" @disabled($lockR)><option value="%" @selected(($o['unite'] ?? '%') === '%')>%</option><option value="TND" @selected(($o['unite'] ?? '') === 'TND')>TND</option></select></span></label>
                </div>
                <p class="mt-2 text-xs text-slate-400">L’ancien salaire vient de l’historique de la fiche employé (dernier changement du salaire de base). Sans historique, l’augmentation saisie ici s’applique au salaire actuel.</p>
                <div class="mt-3"><input type="search" x-model="q" placeholder="Rechercher (nom, matricule)…" class="input-sm w-60"></div>
            </div>
            <div class="card card-b">
                <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Récapitulatif</div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <span class="text-slate-500">Période</span><span>{{ ($m = $rappel->moisRappeles()) ? mois_fr($m[0]).' → '.mois_fr(end($m)).' ('.count($m).' mois)' : '—' }}</span>
                    <span class="text-slate-500">Salariés</span><span>{{ count($RR) }}</span>
                    <span class="text-slate-500">Brut rappelé</span><span class="font-mono">{{ tnd($TR['brut']) }}</span>
                    <span class="text-slate-500">Net à payer</span><span class="font-mono font-semibold">{{ tnd($TR['net']) }}</span>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span class="text-rose-500">* Mention obligatoire</span>
                    <span class="flex items-center gap-1 font-semibold {{ $etat[1] }}"><x-icon name="alert" class="h-4 w-4"/> {{ $etat[0] }}</span>
                </div>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="tbl text-xs">
                <thead><tr>
                    <th>N°</th><th>Matricule</th><th>Employé</th><th class="text-right">Net</th><th class="text-right">S. Brut</th>
                    <th class="text-right" title="Salaire de base avant l'augmentation">Ancien salaire</th><th class="text-right" title="Salaire de base après l'augmentation">Nouveau salaire</th>
                    <th class="text-right" title="Mois rappelés">Nb mois</th><th class="text-right" title="Vide : (nouveau − ancien) × nb mois">Montant</th>
                    <th class="text-right" title="Acompte déjà versé, déduit du net">Acom</th><th class="text-right">CNSS</th><th class="text-right">IRPP</th><th class="text-right">CSS</th>
                </tr></thead>
                <tbody>
                @forelse($lignes as $i => $e)
                    @php
                        $x = $parId[$e->id] ?? null; $id = $e->id;
                        $base = $e->regime === 'horaire' ? (float) $e->salaire_base * (float) $PR['heures_mois'] : (float) $e->salaire_base;
                        $d = $x['rappel'] ?? \App\Services\PaieCalc::rappel($e, $rappel, (array) (($rappel->pointage ?? [])[$id] ?? []), $base);
                    @endphp
                    <tr x-show="@js(mb_strtolower($e->nomComplet().' '.$e->matricule)).includes(q.toLowerCase())" class="{{ $x ? '' : 'text-slate-400' }}">
                        <td>{{ $i + 1 }}</td><td class="font-mono">{{ $e->matricule }}</td><td class="whitespace-nowrap font-medium">{{ $e->nomComplet() }}</td>
                        <td class="num font-semibold text-emerald-700 dark:text-emerald-400">{{ $x ? n3($x['net']) : '' }}</td><td class="num">{{ $x ? n3($x['brut']) : '' }}</td>
                        @if($lockR)
                            <td class="num">{{ n3($d['ancien']) }}</td><td class="num">{{ n3($d['nouveau']) }}</td><td class="num">{{ $nb($d['nb']) }}</td><td class="num">{{ $x ? n3($x['salaire']) : '—' }}</td><td class="num">{{ $x && $x['acompte'] ? n3($x['acompte']) : '—' }}</td>
                        @else
                            <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][ancien]" value="{{ $nb($pt($id, 'ancien')) }}" placeholder="{{ $nb($d['ancien']) }}" class="input-sm ml-auto w-24 text-right"></td>
                            <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][nouveau]" value="{{ $nb($pt($id, 'nouveau')) }}" placeholder="{{ $nb($d['nouveau']) }}" class="input-sm ml-auto w-24 text-right"></td>
                            <td><input type="number" step="0.5" min="0" name="pointage[{{ $id }}][nb]" value="{{ $nb($pt($id, 'nb')) }}" placeholder="{{ $nb($d['nb']) }}" class="input-sm ml-auto w-14 text-right"></td>
                            <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][montant]" value="{{ $nb($pt($id, 'montant')) }}" placeholder="{{ $nb($d['montant']) }}" class="input-sm ml-auto w-24 text-right"></td>
                            <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][acompte]" value="{{ $nb($pt($id, 'acompte')) }}" class="input-sm ml-auto w-20 text-right"></td>
                        @endif
                        <td class="num">{{ $x ? n3($x['cnssS']) : '' }}</td><td class="num">{{ $x ? n3($x['ir']) : '' }}</td><td class="num">{{ $x ? n3($x['css']) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13"><x-empty icon="users" title="Aucun salarié présent en {{ mois_fr($decl) }}"/></td></tr>
                @endforelse
                </tbody>
                @if(count($RR))
                    <tfoot class="border-t-2 border-slate-200 font-semibold"><tr>
                        <td colspan="3">Total ({{ count($RR) }})</td><td class="num">{{ n3($TR['net']) }}</td><td class="num">{{ n3($TR['brut']) }}</td><td colspan="5"></td>
                        <td class="num">{{ n3($TR['cnssS']) }}</td><td class="num">{{ n3($TR['ir']) }}</td><td class="num">{{ n3($TR['css']) }}</td>
                    </tr></tfoot>
                @endif
            </table>
        </div>
    </form>

    @if($rappel->exists)
        <form id="form-rappel-supprimer" method="POST" action="{{ route('paie.supprimer') }}" onsubmit="return confirm('Supprimer ce rappel ?')">@csrf<input type="hidden" name="paie_id" value="{{ $rappel->id }}"></form>
        <form id="form-rappel-cloture" method="POST" action="{{ route('paie.cloturer') }}" onsubmit="return confirm('Clôturer ce rappel et le transmettre à la comptabilité ?')">@csrf<input type="hidden" name="paie_id" value="{{ $rappel->id }}"></form>
    @endif
    <div class="mt-3 flex flex-wrap items-stretch gap-3">
        <div class="card card-b grid grid-cols-[auto_6rem] items-center gap-x-3 gap-y-1.5 text-sm">
            <span class="font-semibold">SMIG mensuel</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ isset($kc['smig_mensuel']) ? n3($kc['smig_mensuel']) : '—' }}</span>
            <span class="font-semibold">CNSS salariale</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $PR['cnssS'] }} %</span>
            <span class="font-semibold">Arrondissement</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $PR['arrondi'] }}</span>
        </div>
        <div class="card card-b grid grid-cols-2 gap-2">
            <button form="form-rappel" class="btn-primary btn-sm justify-start" @disabled($lockR)><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
            <button form="form-rappel" class="btn-secondary btn-sm justify-start" @disabled($lockR)><x-icon name="copy" class="h-4 w-4"/> Recalculer</button>
            <button form="form-rappel-cloture" class="btn-secondary btn-sm justify-start" @disabled($lockR || ! $rappel->exists || ! count($RR))><x-icon name="lock" class="h-4 w-4"/> Clôture</button>
            <button form="form-rappel-supprimer" class="btn-secondary btn-sm justify-start text-rose-600" @disabled($lockR || ! $rappel->exists)><x-icon name="x" class="h-4 w-4"/> Supprimer</button>
            @if($rappel->exists)<a href="{{ route('paie.bulletins', ['paie' => $rappel->id, 'format' => 'a4']) }}" target="_blank" class="btn-secondary btn-sm col-span-2 justify-start"><x-icon name="printer" class="h-4 w-4"/> Imprimer les bulletins</a>@endif
        </div>
        @if($lockR && $rappel->statut === 'transmise' && auth()->user()->estChef())
            <form method="POST" action="{{ route('paie.rouvrir') }}" class="flex items-center" onsubmit="return confirm('Rouvrir ce rappel ?')">@csrf
                <input type="hidden" name="paie_id" value="{{ $rappel->id }}"><input type="hidden" name="mois" value="{{ $rappel->mois }}">
                <button class="btn-secondary"><x-icon name="lock" class="h-4 w-4"/> Rouvrir</button></form>
        @endif
        @unless($rappel->exists)<p class="self-center text-xs text-slate-400">Renseignez le libellé et la période, puis Enregistrer : le rappel est calculé et peut ensuite être clôturé et édité (menu Rappel).</p>@endunless
    </div>
</div>
