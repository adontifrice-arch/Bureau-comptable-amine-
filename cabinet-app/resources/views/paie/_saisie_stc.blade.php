{{--
    Menu Solde de tout compte → Saisie : solde de tout compte d'un salarié qui quitte l'entreprise, déclaré avec un mois (par défaut celui de sa sortie).
    Indemnité compensatrice de congés (solde de jours × taux journalier), préavis, autres gains, indemnité de licenciement (exonérée),
    retenues et acompte. CNSS sur la part soumise ; IRPP par régularisation (case RegP du salarié) ou par différence.
--}}
@php
    $lockT = $stc && $stc->exists && $stc->statut !== 'brouillon';
    $x = $RT[0] ?? null;
    $d = $x['stc'] ?? null;
    $pt = fn ($k) => $stc && $salarieStc ? (($stc->pointage ?? [])[$salarieStc->id][$k] ?? null) : null;
    $nb = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $etat = ! $stc?->exists ? ['Solde non encore enregistré', 'text-rose-600'] : ($lockT ? ['Solde clôturé — '.(['transmise' => 'transmis à la comptabilité', 'val' => 'comptabilisé'][$stc->statut] ?? ''), 'text-emerald-700'] : ['Enregistré (brouillon)', 'text-amber-600']);
    // Valeurs proposées tant que rien n'est saisi (solde de congés de l'année jusqu'à la sortie).
    $s = $salarieStc;
    $baseS = $s ? ($s->regime === 'horaire' ? (float) $s->salaire_base * (float) $PT['heures_mois'] : (float) $s->salaire_base) : 0;
    $tauxJ = $PT['jours_mois'] > 0 ? $baseS / $PT['jours_mois'] : 0;
    $solde = $s ? \App\Services\PaieCalc::soldeConges($s, min($s->date_sortie?->format('Y-m') ?? $stc->mois, $stc->mois)) : null;
    $motifs = \App\Models\Salarie::MOTIFS_SORTIE;
    $restesP = $s ? \App\Services\PaieCalc::restePrets($s, $stc->mois) : ['societe' => 0, 'cnss' => 0];
@endphp

@if(! $stc)
    <div class="card"><x-empty icon="users" title="Aucun employé dans le dossier"/></div>
@else
<form method="POST" action="{{ route('paie.calcul') }}" id="form-stc">@csrf
    @if($stc->exists)<input type="hidden" name="paie_id" value="{{ $stc->id }}">@else<input type="hidden" name="type" value="stc"><input type="hidden" name="salarie_id" value="{{ $s?->id }}">@endif
    <input type="hidden" name="parties[]" value="saisie">
    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <div class="card card-b">
            <div class="mb-2 flex items-center justify-between"><span class="text-xs font-semibold uppercase text-slate-400">Salarié</span>
                <a href="{{ route('paie.index', ['tab' => 'stc', 'nouveau' => 1, 'mois' => $mois]) }}" class="text-xs text-brand-600 hover:underline">+ Nouveau solde de tout compte</a></div>
            @if($stc->exists)
                <div class="text-base font-semibold">{{ $s?->nomComplet() }}</div>
                <div class="text-sm text-slate-500">Déclaré avec {{ mois_fr($stc->mois) }}</div>
            @else
                <div class="flex flex-wrap items-center gap-3" x-data="{ s: {{ (int) $s?->id }}, d: @js($stc->mois),
                        aller(garder) { location.href = @js(route('paie.index', ['tab' => 'stc', 'nouveau' => 1])) + '&salarie=' + this.s + (garder ? '&decl=' + this.d : '') } }">
                    <label class="text-sm font-semibold">Salarié*</label>
                    <select x-model.number="s" @change="aller(false)" class="input-sm w-64">
                        @foreach($candidatsStc as $c)<option value="{{ $c->id }}">{{ $c->nomComplet() }}{{ $c->date_sortie ? ' — sorti le '.$c->date_sortie->format('d/m/Y') : '' }}</option>@endforeach
                    </select>
                    <label class="text-sm font-semibold">À déclarer avec*</label>
                    <input type="month" name="mois" x-model="d" @change="aller(true)" class="input-sm w-40" required>
                </div>
            @endif
            @if($s)
                <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <span class="text-slate-500">Matricule / CIN</span><span>{{ $s->matricule ?: '—' }} · {{ $s->cin ?: '—' }}</span>
                    <span class="text-slate-500">Embauche</span><span>{{ $s->date_embauche?->format('d/m/Y') ?? '—' }}</span>
                    <span class="text-slate-500">Sortie</span><span class="{{ $s->date_sortie ? '' : 'text-amber-600' }}">{{ $s->date_sortie?->format('d/m/Y') ?? 'date de sortie non renseignée sur la fiche' }}{{ $s->motif_sortie ? ' · '.($motifs[$s->motif_sortie] ?? $s->motif_sortie) : '' }}</span>
                    <span class="text-slate-500">Salaire de base</span><span class="font-mono">{{ n3($baseS) }}</span>
                    <span class="text-slate-500">Congés</span><span>report des années précédentes {{ $nb($solde['report'] ?? 0) }} j + acquis {{ substr($stc->mois, 0, 4) }} {{ $nb($solde['acquis'] ?? 0) }} j − pris {{ $nb($solde['pris'] ?? 0) }} j = <b>reste {{ $nb($solde['reste'] ?? 0) }} j</b></span>
                    <span class="text-slate-500">IRPP</span><span>{{ \App\Services\PaieCalc::regulPrimes($s, $PT) ? 'régularisation sur le cumul de l’année (RegP)' : 'par différence sur l’année' }}</span>
                </div>
            @endif
        </div>
        <div class="card card-b">
            <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Éléments du solde</div>
            <div class="grid grid-cols-[1fr_8rem] items-center gap-x-3 gap-y-2 text-sm">
                @foreach([
                    ['jours_trav', 'Jours travaillés le mois de sortie (salaire)', $nb($s ? \App\Services\PaieCalc::joursOuvrablesSortie($s, $PT) : 0).' / '.$nb($PT['jours_mois']), '0.5'],
                    ['conge_jours', 'Jours de congé à indemniser (report + année)', $nb(max(0, $solde['reste'] ?? 0)), '0.5'],
                    ['conge_montant', 'Indemnité compensatrice de congés', $nb(r3((float) ($pt('conge_jours') ?? max(0, $solde['reste'] ?? 0)) * $tauxJ)).' (j × '.n3($tauxJ).')', '0.001'],
                    ['preavis', 'Indemnité de préavis', '', '0.001'],
                    ['autres', 'Autres gains (soumis)', '', '0.001'],
                    ['licenciement', 'Indemnité de licenciement (exonérée)', '', '0.001'],
                    ['pret_societe', 'Reste dû du prêt société (retenu)', $restesP['societe'] ? $nb($restesP['societe']) : '0', '0.001'],
                    ['pret_cnss', 'Reste dû du prêt CNSS (retenu)', $restesP['cnss'] ? $nb($restesP['cnss']) : '0', '0.001'],
                    ['retenues', 'Autres retenues : avance… (sur le net)', '', '0.001'],
                    ['acompte', 'Acompte déjà versé', '', '0.001'],
                ] as [$k, $l, $ph, $step])
                    <label for="stc-{{ $k }}">{{ $l }}</label>
                    @if($lockT)<span class="text-right font-mono">{{ $nb($d[['conge_jours' => 'jours', 'conge_montant' => 'conge'][$k] ?? $k] ?? ($k === 'acompte' ? ($x['acompte'] ?? null) : null)) ?: '—' }}</span>
                    @else<input id="stc-{{ $k }}" type="number" step="{{ $step }}" min="0" name="pointage[{{ $s?->id }}][{{ $k }}]" value="{{ $nb($pt($k)) }}" placeholder="{{ $ph }}" class="input-sm text-right">@endif
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="text-slate-400">Vide : valeur proposée (en gris).</span>
                <span class="flex items-center gap-1 font-semibold {{ $etat[1] }}"><x-icon name="alert" class="h-4 w-4"/> {{ $etat[0] }}</span>
            </div>
        </div>
    </div>
</form>

@if($x)
    <div class="card card-b mb-3 max-w-2xl text-sm">
        <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Calcul</div>
        <table class="w-full">
            @foreach(\App\Services\PaieCalc::lignesBulletin($x, $PT) as $row)
                <tr class="border-b border-slate-100 {{ ($row[2] ?? 0) === 1 ? 'font-bold' : '' }}"><td class="py-1">{{ $row[0] }}</td><td class="py-1 text-right font-mono">{{ tnd($row[1], false) }}</td></tr>
            @endforeach
        </table>
        <div class="mt-2 text-xs text-slate-500">Charges patronales : CNSS {{ tnd($x['cnssP']) }} · TFP {{ tnd($x['tfp']) }} · FOPROLOS {{ tnd($x['fop']) }}</div>
    </div>
@else
    <p class="mb-3 text-sm text-slate-500">Aucun élément à payer : saisissez au moins un montant ou des jours de congé.</p>
@endif

@if($stc->exists)
    <form id="form-stc-supprimer" method="POST" action="{{ route('paie.supprimer') }}" onsubmit="return confirm('Supprimer ce solde de tout compte ?')">@csrf<input type="hidden" name="paie_id" value="{{ $stc->id }}"></form>
    <form id="form-stc-cloture" method="POST" action="{{ route('paie.cloturer') }}" onsubmit="return confirm('Clôturer ce solde de tout compte et le transmettre à la comptabilité ?')">@csrf<input type="hidden" name="paie_id" value="{{ $stc->id }}"></form>
@endif
<div class="flex flex-wrap items-stretch gap-3">
    <div class="card card-b grid grid-cols-2 gap-2">
        <button form="form-stc" class="btn-primary btn-sm justify-start" @disabled($lockT || ! $s)><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
        <button form="form-stc" class="btn-secondary btn-sm justify-start" @disabled($lockT || ! $s)><x-icon name="copy" class="h-4 w-4"/> Recalculer</button>
        <button form="form-stc-cloture" class="btn-secondary btn-sm justify-start" @disabled($lockT || ! $stc->exists || ! $x)><x-icon name="lock" class="h-4 w-4"/> Clôture</button>
        <button form="form-stc-supprimer" class="btn-secondary btn-sm justify-start text-rose-600" @disabled($lockT || ! $stc->exists)><x-icon name="x" class="h-4 w-4"/> Supprimer</button>
        @if($stc->exists && $x)
            <a href="{{ route('paie.bulletins', ['paie' => $stc->id, 'format' => 'a4']) }}" target="_blank" class="btn-secondary btn-sm justify-start"><x-icon name="printer" class="h-4 w-4"/> Bulletin</a>
            <a href="{{ route('paie.stc.quittance', $stc) }}" target="_blank" class="btn-secondary btn-sm justify-start"><x-icon name="file-text" class="h-4 w-4"/> Quittance</a>
        @endif
    </div>
    @if($lockT && $stc->statut === 'transmise' && auth()->user()->estChef())
        <form method="POST" action="{{ route('paie.rouvrir') }}" class="flex items-center" onsubmit="return confirm('Rouvrir ce solde de tout compte ?')">@csrf
            <input type="hidden" name="paie_id" value="{{ $stc->id }}"><input type="hidden" name="mois" value="{{ $stc->mois }}">
            <button class="btn-secondary"><x-icon name="lock" class="h-4 w-4"/> Rouvrir</button></form>
    @endif
    <p class="self-center text-xs text-slate-400">Le salaire du mois de sortie est payé ici : le salarié sort de la paie ordinaire du mois de déclaration. Renseignez la date et le motif de sortie, et le report de congés des années précédentes, sur la fiche employé.</p>
</div>
@endif
