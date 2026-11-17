{{--
    Paie → Saisie Paie : grille de saisie du mois (sur le modèle de l'écran « Saisie de la paie »).
    En-tête : exercice (mois, année), jours ouvrables, jours fériés, heures par jour et heures ouvrables du mois.
    Une ligne par salarié : net, brut, salaire de base et taux horaire calculés ; J/H ouvrables, J/H travaillés, congés, fériés, acompte,
    heures supplémentaires et primes saisis ; montants des rubriques du dossier. Pied : SMIG, CNSS salariale, arrondi et boutons d'action.
--}}
@php
    $kc = $client->paie_config ?? [];
    $parId = $salaries->keyBy('id');
    $annee = (int) substr($mois, 0, 4);
    $moisNum = (int) substr($mois, 5, 2);
    $nomsMois = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    // Colonnes de rubriques : celles du dossier payées ce mois-ci (hors notes), dans l'ordre des codes.
    $colsRub = $rubriques->reject(fn ($rb) => $rb->note)->filter(fn ($rb) => collect($R)->contains(fn ($x) => collect($x['rubriques'] ?? [])->contains('id', $rb->id)))->values();
    $pt = fn ($id, $k) => (($paie?->pointage ?? [])[$id][$k] ?? null);
    $nb = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $etat = ! $paie ? ['Mois non encore enregistré', 'text-rose-600'] : ($lock ? ['Paie clôturée — '.(['transmise' => 'transmise à la comptabilité', 'val' => 'comptabilisée'][$paie->statut] ?? ''), 'text-emerald-700'] : ['Mois enregistré (brouillon)', 'text-amber-600']);
    $filtres = ['departement' => 'Département', 'profil' => 'Profil', 'regime' => 'Régime salarial'];
    $valeursFiltre = [
        'departement' => $salaries->pluck('departement')->map(fn ($v) => trim((string) $v))->filter()->unique()->sort()->values(),
        'profil' => $salaries->pluck('profil')->map(fn ($v) => trim((string) $v))->filter()->unique()->sort()->values(),
        'regime' => collect(['mensuel', 'horaire']),
    ];
@endphp

<div x-data="{ q: '', filtre: '', valeur: '', valeurs: @js($valeursFiltre),
        visible(el) { const d = el.dataset; return d.recherche.includes(this.q.toLowerCase()) && (!this.filtre || !this.valeur || d[this.filtre] === this.valeur.toLowerCase()); } }">

    {{-- En-tête : exercice et jours du mois --}}
    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <div class="card card-b">
            <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Exercice</div>
            <div class="flex flex-wrap items-center gap-3" x-data="{ m: {{ $moisNum }}, a: {{ $annee }}, aller() { location.href = @js(route('paie.index', ['tab' => 'calc'])) + '&mois=' + this.a + '-' + String(this.m).padStart(2, '0') } }">
                <label class="text-sm font-semibold">Mois*</label>
                <select x-model.number="m" @change="aller()" class="input-sm w-40">@foreach($nomsMois as $i => $n)<option value="{{ $i + 1 }}" @selected($i + 1 === $moisNum)>{{ $n }}</option>@endforeach</select>
                <label class="text-sm font-semibold">Année*</label>
                <input type="number" x-model.number="a" @change="aller()" min="2000" max="2100" class="input-sm w-24">
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <input type="search" x-model="q" placeholder="Rechercher (nom, matricule)…" class="input-sm w-60">
                <label class="ml-2 text-sm font-semibold">Filtrer par</label>
                <select x-model="filtre" @change="valeur = ''" class="input-sm w-40"><option value="">—</option>@foreach($filtres as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                <select x-model="valeur" x-show="filtre" class="input-sm w-44"><option value="">Tous</option>
                    <template x-for="v in (valeurs[filtre] || [])" :key="v"><option :value="v" x-text="v.charAt(0).toUpperCase() + v.slice(1)"></option></template></select>
            </div>
        </div>
        <div class="card card-b" x-data="{ jo: @js($nb($paie?->jours_ouvrables ?? $P['jours_mois'])), hj: @js($nb($paie?->heures_jour ?? $P['heures_jour'])), ho: @js($nb($paie?->heures_ouvrables ?? $P['heures_mois'])) }">
            <div class="mb-2 text-xs font-semibold uppercase text-slate-400">Jours</div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. jrs ouv.* <input form="form-primes" type="number" step="0.5" min="0" max="31" name="jours_ouvrables" x-model="jo" @input="ho = +(jo * hj).toFixed(2)" class="input-sm w-20 text-right" @disabled($lock) required></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. jr férié <input form="form-primes" type="number" step="0.5" min="0" max="31" name="jours_feries" value="{{ $nb($paie?->jours_feries) }}" class="input-sm w-20 text-right" @disabled($lock)></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. hrs/jr <input form="form-primes" type="number" step="0.25" min="0" max="24" name="heures_jour" x-model="hj" @input="ho = +(jo * hj).toFixed(2)" class="input-sm w-20 text-right" @disabled($lock)></label>
                <label class="flex items-center justify-between gap-2 font-semibold">Nb. hrs ouv.* <input form="form-primes" type="number" step="0.01" min="0" name="heures_ouvrables" x-model="ho" class="input-sm w-20 text-right" @disabled($lock) required></label>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="text-rose-500">* Mention obligatoire</span>
                <span class="flex items-center gap-1 font-semibold {{ $etat[1] }}"><x-icon name="alert" class="h-4 w-4"/> {{ $etat[0] }}</span>
            </div>
        </div>
    </div>

    {{-- Grille de saisie --}}
    <form method="POST" action="{{ route('paie.calcul') }}" id="form-primes" class="card overflow-x-auto">@csrf
        <input type="hidden" name="mois" value="{{ $mois }}"><input type="hidden" name="parties[]" value="saisie"><input type="hidden" name="tab" value="calc">
        <table class="tbl text-xs">
            <thead><tr>
                <th>N°</th><th>Matricule</th><th>Employé</th><th class="text-center" title="Régularisation mensuelle de l’IRPP">Reg</th><th class="text-center" title="Régularisation sur les primes / autres / rappel">RegP</th><th class="text-right">Net</th><th class="text-right">S. Brut</th><th class="text-right">S. Base</th>
                <th class="text-right" title="Taux horaire : salaire de base ÷ heures ouvrables">Taux</th>
                <th class="text-right" title="Jours (ou heures en régime horaire) ouvrables de l'employé — vide : ceux du mois">J/H ouv</th>
                <th class="text-right" title="Jours (ou heures) travaillés — vide : mois complet">J/H trav</th>
                <th class="text-right" title="Jours de congé payé, ajoutés aux jours travaillés">Congé</th>
                <th class="text-right" title="Jours fériés payés, ajoutés aux jours travaillés — vide : ceux du mois">Férié</th>
                <th class="text-right" title="Acompte déjà versé, déduit du net">Acom</th>
                <th class="text-right" title="Heures supplémentaires (majoration {{ $P['maj_hs'] }} %)">H. sup.</th>
                <th class="text-right">Primes</th>
                @foreach($colsRub as $rb)<th class="text-right" title="{{ $rb->code }} {{ $rb->nom }}">{{ \Illuminate\Support\Str::limit($rb->nom, 14) }}</th>@endforeach
            </tr></thead>
            <tbody>
            @forelse($R as $i => $x)
                @php $s = $parId[$x['salarie_id']] ?? null; $id = $x['salarie_id']; $horaire = ($x['regime'] ?? 'mensuel') === 'horaire'; @endphp
                <tr x-show="visible($el)" data-recherche="{{ mb_strtolower($x['nom'].' '.($x['matricule'] ?? '')) }}" data-departement="{{ mb_strtolower(trim((string) $s?->departement)) }}"
                    data-profil="{{ mb_strtolower(trim((string) ($x['profil'] ?? $s?->profil))) }}" data-regime="{{ $horaire ? 'horaire' : 'mensuel' }}">
                    <td class="text-slate-400">{{ $i + 1 }}</td>
                    <td class="font-mono">{{ $x['matricule'] ?? '' }}</td>
                    <td class="whitespace-nowrap font-medium"><a href="{{ route('paie.employes.edit', $id) }}" class="hover:text-brand-700">{{ $x['nom'] }}</a></td>
                    @php $regI = $s ? \App\Services\PaieCalc::regulIrpp($s, $P) : false; $regP = $s ? \App\Services\PaieCalc::regulPrimes($s, $P) : false; @endphp
                    <td class="text-center"><input type="hidden" name="regul[{{ $id }}][irpp]" value="0" @disabled($lock)><input type="checkbox" name="regul[{{ $id }}][irpp]" value="1" @checked($regI) @disabled($lock)></td>
                    <td class="text-center"><input type="hidden" name="regul[{{ $id }}][primes]" value="0" @disabled($lock)><input type="checkbox" name="regul[{{ $id }}][primes]" value="1" @checked($regP) @disabled($lock)></td>
                    <td class="num font-semibold text-emerald-700 dark:text-emerald-400">{{ n3($x['net']) }}</td>
                    <td class="num">{{ n3($x['brut']) }}</td>
                    <td class="num">{{ n3($x['base']) }}@if($horaire)<div class="text-[10px] text-slate-400">horaire</div>@endif</td>
                    <td class="num text-slate-500">{{ n3($x['taux_h'] ?? 0) }}</td>
                    @if($lock)
                        <td class="num">{{ $nb($x['jours_mois'] ?? null) }}</td><td class="num">{{ $nb($x['pt_trav'] ?? null) ?: '—' }}</td><td class="num">{{ $nb($x['pt_conge'] ?? null) ?: '—' }}</td>
                        <td class="num">{{ $nb($x['pt_ferie'] ?? null) ?: '—' }}</td><td class="num">{{ n3($x['acompte'] ?? 0) ?: '—' }}</td><td class="num">{{ $nb($x['hs'] ?? null) ?: '—' }}</td><td class="num">{{ n3($x['prime']) ?: '—' }}</td>
                    @else
                        <td><input type="number" step="0.5" min="0" name="pointage[{{ $id }}][jo]" value="{{ $nb($pt($id, 'jo')) }}" placeholder="{{ $nb($horaire ? $P['heures_mois'] : $P['jours_mois']) }}" class="input-sm ml-auto w-16 text-right"></td>
                        <td><input type="number" step="0.5" min="0" name="pointage[{{ $id }}][jours]" value="{{ $nb($pt($id, 'jours')) }}" class="input-sm ml-auto w-16 text-right"></td>
                        <td><input type="number" step="0.5" min="0" name="pointage[{{ $id }}][conge]" value="{{ $nb($pt($id, 'conge')) }}" placeholder="{{ $pt($id, 'conge') === null && ($x['pt_conge'] ?? 0) ? $nb($x['pt_conge']) : '' }}" title="{{ ($x['pt_conge'] ?? 0) && $pt($id, 'conge') === null ? 'Jours du registre des congés (Congé → Saisie des congés)' : '' }}{{ ($x['pt_sans_solde'] ?? 0) ? ' · congé sans solde : '.$nb($x['pt_sans_solde']).' j' : '' }}" class="input-sm ml-auto w-14 text-right {{ ($x['pt_sans_solde'] ?? 0) ? 'border-amber-400' : '' }}"></td>
                        <td><input type="number" step="0.5" min="0" name="pointage[{{ $id }}][ferie]" value="{{ $nb($pt($id, 'ferie')) }}" placeholder="{{ $nb($paie?->jours_feries) }}" class="input-sm ml-auto w-14 text-right"></td>
                        <td><input type="number" step="0.001" min="0" name="pointage[{{ $id }}][acompte]" value="{{ $nb($pt($id, 'acompte')) }}" placeholder="{{ $pt($id, 'acompte') === null && ($x['acompte'] ?? 0) ? $nb($x['acompte']) : '' }}" title="{{ $pt($id, 'acompte') === null && ($x['acompte'] ?? 0) ? 'Acomptes du registre (Acompte → Saisie des acomptes)' : '' }}" class="input-sm ml-auto w-24 text-right"></td>
                        <td><input type="number" step="0.25" min="0" name="pointage[{{ $id }}][hs]" value="{{ ($x['hs'] ?? 0) ? $nb($x['hs']) : '' }}" class="input-sm ml-auto w-16 text-right"></td>
                        <td><input type="number" step="0.001" name="primes[{{ $id }}]" value="{{ $x['prime'] ? $nb($x['prime']) : '' }}" class="input-sm ml-auto w-24 text-right"></td>
                    @endif
                    @foreach($colsRub as $rb)
                        @php $l = collect($x['rubriques'] ?? [])->firstWhere('id', $rb->id); @endphp
                        <td class="num {{ ($l['sens'] ?? '') === 'retenue' ? 'text-rose-600' : '' }}">{{ $l ? (($l['sens'] === 'retenue' ? '−' : '').n3($l['montant'])) : '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ 16 + $colsRub->count() }}"><x-empty icon="users" title="Aucun salarié à payer ce mois-ci">Ajoutez les employés du dossier (Employé → Nouvel employé).</x-empty></td></tr>
            @endforelse
            </tbody>
            @if(count($R))
                <tfoot class="border-t-2 border-slate-200 font-semibold"><tr>
                    <td colspan="5">Total ({{ count($R) }})</td><td class="num">{{ n3($T['net']) }}</td><td class="num">{{ n3($T['brut']) }}</td><td colspan="6"></td>
                    <td class="num">{{ n3($T['acompte'] ?? 0) }}</td><td></td><td class="num">{{ n3(array_sum(array_column($R, 'prime'))) }}</td>
                    @foreach($colsRub as $rb)<td class="num">{{ n3(collect($R)->sum(fn ($x) => collect($x['rubriques'] ?? [])->where('id', $rb->id)->sum('montant'))) }}</td>@endforeach
                </tr></tfoot>
            @endif
        </table>
    </form>

    {{-- Pied : paramètres et actions --}}
    <form id="form-supprimer" method="POST" action="{{ route('paie.supprimer') }}" onsubmit="return confirm('Supprimer toute la saisie de {{ mois_fr($mois) }} ?')">@csrf<input type="hidden" name="mois" value="{{ $mois }}"></form>
    <form id="form-cloture" method="POST" action="{{ route('paie.cloturer') }}" onsubmit="return confirm('Clôturer la paie et la transmettre à la comptabilité ?')">@csrf<input type="hidden" name="mois" value="{{ $mois }}"></form>
    <form id="form-actualiser" method="POST" action="{{ route('paie.mettre-a-jour') }}" onsubmit="return confirm('Recalculer les paies en brouillon avec les taux et le barème en vigueur ? Les paies clôturées ne changent pas.')">@csrf</form>
    <div class="mt-3 flex flex-wrap items-stretch gap-3">
        <div class="card card-b grid grid-cols-[auto_6rem] items-center gap-x-3 gap-y-1.5 text-sm">
            <span class="font-semibold">SMIG mensuel</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ isset($kc['smig_mensuel']) ? n3($kc['smig_mensuel']) : '—' }}</span>
            <span class="font-semibold">SMIG horaire</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ isset($kc['smig_horaire']) ? n3($kc['smig_horaire']) : '—' }}</span>
            <span class="font-semibold">CNSS salariale</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $P['cnssS'] }} %</span>
            <span class="font-semibold">Arrondissement</span><span class="input-sm bg-slate-50 text-right text-slate-500 dark:bg-slate-800">{{ $P['arrondi'] }}</span>
        </div>
        <div class="card card-b flex flex-col justify-center gap-2">
            <button type="button" x-data @click="$dispatch('open-modal', 'paie-calculatrice')" class="btn-secondary btn-sm justify-start" title="Calcul du brut à partir du net"><x-icon name="arrow-left" class="h-4 w-4"/> À l’envers</button>
            <button form="form-primes" class="btn-secondary btn-sm justify-start" @disabled($lock)><x-icon name="copy" class="h-4 w-4"/> Recalculer</button>
        </div>
        <div class="card card-b grid grid-cols-2 gap-2">
            <button form="form-primes" class="btn-primary btn-sm justify-start" @disabled($lock)><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
            <button form="form-cloture" class="btn-secondary btn-sm justify-start" @disabled($lock || ! count($R))><x-icon name="lock" class="h-4 w-4"/> Clôture</button>
            <button form="form-supprimer" class="btn-secondary btn-sm justify-start text-rose-600" @disabled($lock || ! $paie)><x-icon name="x" class="h-4 w-4"/> Supprimer</button>
            <a href="{{ route('paie.edition', ['journal', 'imprimer', 'mois' => $mois]) }}" target="_blank" class="btn-secondary btn-sm justify-start"><x-icon name="printer" class="h-4 w-4"/> Imprimer</a>
        </div>
        <div class="card card-b flex flex-col justify-center gap-2">
            <button form="form-actualiser" class="btn-secondary btn-sm justify-start"><x-icon name="copy" class="h-4 w-4"/> Actualiser tout</button>
            <a href="{{ route('paie.index', ['tab' => 'sal', 'mois' => $mois]) }}" class="btn-secondary btn-sm justify-start"><x-icon name="users" class="h-4 w-4"/> Actif / Inactif</a>
        </div>
        <div class="card card-b flex flex-col justify-center gap-2">
            <button type="button" x-data @click="$dispatch('open-modal', 'paie-import-pointage')" class="btn-secondary btn-sm justify-start" @disabled($lock)><x-icon name="upload" class="h-4 w-4"/> Importation Excel</button>
            <span class="btn-secondary btn-sm cursor-not-allowed justify-start opacity-50" title="Import depuis la pointeuse : à préciser"><x-icon name="upload" class="h-4 w-4"/> Import Pointage</span>
        </div>
        <div class="flex items-center">
            <a href="{{ route('paie.index', ['tab' => 'variable', 'mois' => $mois]) }}" class="btn-secondary"><x-icon name="plus" class="h-4 w-4"/> Variable</a>
        </div>
    </div>
</div>

@if(count($R))
    <div class="mt-6 grid gap-4 sm:grid-cols-4">
        <x-stat label="Masse salariale brute" :value="tnd($T['brut'], true, 0)" icon="wallet"/>
        <x-stat label="Net à payer" :value="tnd($T['net'], true, 0)" icon="check" tone="green"/>
        <x-stat label="Retenues IRPP + CSS" :value="tnd($T['ras'], true, 0)" icon="scale" tone="amber"/>
        <x-stat label="Coût employeur" :value="tnd($T['cout'], true, 0)" icon="trend"/>
    </div>
    <div class="mt-6 grid gap-6 md:grid-cols-2">
        <div class="card card-b text-sm"><h3 class="card-t mb-3">Charges patronales du mois</h3>
            <div class="space-y-1.5"><div class="flex justify-between"><span>CNSS patronale ({{ $P['cnssP'] }} %)</span><span class="font-mono">{{ tnd($T['cnssP']) }}</span></div><div class="flex justify-between"><span>TFP ({{ $P['tfp'] }} %)</span><span class="font-mono">{{ tnd($T['tfp']) }}</span></div><div class="flex justify-between"><span>FOPROLOS ({{ $P['fop'] }} %)</span><span class="font-mono">{{ tnd($T['fop']) }}</span></div></div></div>
        <div class="card card-b text-sm"><h3 class="card-t mb-3">Écriture de paie générée à la validation</h3>
            <table class="w-full">@foreach(\App\Services\PaieCalc::lignes($T) as $l)<tr><td class="py-0.5 font-mono text-xs">{{ $l['compte'] }}</td><td class="text-slate-500">{{ \App\Services\Compta::libelle($l['compte']) }}</td><td class="num">{{ n3($l['debit']) }}</td><td class="num">{{ n3($l['credit']) }}</td></tr>@endforeach</table></div>
    </div>
@endif
