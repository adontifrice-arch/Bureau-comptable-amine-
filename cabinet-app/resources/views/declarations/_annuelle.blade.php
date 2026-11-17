{{-- Déclaration annuelle : impôt sur les sociétés (personne morale) ou impôt sur le revenu (personne physique), modèles DGI. --}}
@php
    $A = \App\Services\DeclarationAnnuelle::class;
    $mod = $A::modele($client); $f = $A::champs($d->champs ?? [], $client, (int) $periode);
    $is = $mod === 'is';
    $DEF = $is ? $A::IS : $A::IR; $TAB = $is ? $A::TABLEAUX_IS : $A::TABLEAUX_IR; $IMP = $is ? $A::IMPOTS_IS : $A::IMPOTS_IR;
    $C = $is ? $A::calculIs($f) : $A::calculIr($f); $coches = $is ? $A::impotsIs($f) : $A::impotsIr($f);
    $calcules = ['ded_acp' => $C['acomptes'] ?? 0];
@endphp
<form method="POST" action="{{ route('declarations.save') }}">@include('declarations._hidden')<input type="hidden" name="imp_saisie" value="1">
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card card-b space-y-3 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2"><div class="font-semibold">{{ $is ? 'Déclaration de l’impôt sur les sociétés' : 'Déclaration de l’impôt sur le revenu des personnes physiques' }}</div>
                <span class="badge-blue">{{ $is ? 'Personne morale' : 'Personne physique' }}</span></div>
            <div class="grid gap-3 md:grid-cols-3">
                <div><label class="label">Code de la déclaration</label><select name="e[acte]" class="input" @disabled($lock)>@foreach($A::ACTES as $k => $l)<option value="{{ $k }}" @selected((string) ($f['acte'] ?? '0') === (string) $k)>{{ $k }} – {{ $l }}</option>@endforeach</select></div>
                @if($is)<div><label class="label">Nature</label><select name="e[nature]" class="input" @disabled($lock)><option value="d" @selected(($f['nature'] ?? 'd') === 'd')>Déclaration définitive</option><option value="p" @selected(($f['nature'] ?? 'd') === 'p')>Déclaration provisoire</option></select></div>@endif
                <div><label class="label">Ouverture de l’exercice</label><input type="date" name="e[ouv]" value="{{ $f['ouv'] ?? '' }}" class="input" @disabled($lock)></div>
                <div><label class="label">Clôture de l’exercice</label><input type="date" name="e[clo]" value="{{ $f['clo'] ?? '' }}" class="input" @disabled($lock)></div>
                <div><label class="label">Activité principale</label><input name="e[activite]" value="{{ $f['activite'] ?? '' }}" class="input" @disabled($lock)></div>
                @if($is)<div><label class="label">Forme juridique</label><input name="e[forme]" value="{{ $f['forme'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Nationalité</label><input name="e[nationalite]" value="{{ $f['nationalite'] ?? '' }}" class="input" @disabled($lock)></div>@endif
            </div>
            @unless($is)
                <div class="grid gap-3 border-t border-slate-100 pt-3 md:grid-cols-3 dark:border-slate-800">
                    <div><label class="label">Nom</label><input name="e[nom]" value="{{ $f['nom'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Prénom</label><input name="e[prenom]" value="{{ $f['prenom'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Téléphone</label><input name="e[telephone]" value="{{ $f['telephone'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Pièce d’identité</label><select name="e[id_code]" class="input" @disabled($lock)>@foreach(['2' => 'Carte d’identité nationale', '3' => 'Carte de séjour', '4' => 'Identifiant non-résident', '5' => 'Passeport'] as $k => $l)<option value="{{ $k }}" @selected(($f['id_code'] ?? '2') == $k)>{{ $l }}</option>@endforeach</select></div>
                    <div><label class="label">Numéro</label><input name="e[id_num]" value="{{ $f['id_num'] ?? '' }}" class="input font-mono" @disabled($lock)></div>
                    <div><label class="label">Date de délivrance</label><input type="date" name="e[id_date]" value="{{ $f['id_date'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Date de naissance (sans matricule fiscal)</label><input type="date" name="e[naissance]" value="{{ $f['naissance'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Nationalité</label><input name="e[nationalite]" value="{{ $f['nationalite'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Pays de naissance</label><input name="e[pays_naissance]" value="{{ $f['pays_naissance'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Situation familiale</label><select name="e[situation]" class="input" @disabled($lock)><option value="">—</option>@foreach($A::SITUATIONS as $k => [$l])<option value="{{ $k }}" @selected(($f['situation'] ?? '') == $k)>{{ $l }}</option>@endforeach</select></div>
                    <div><label class="label">Enfants handicapés à charge</label><input type="number" min="0" name="e[enf_handicap]" value="{{ $f['enf_handicap'] ?? '' }}" class="input" @disabled($lock)></div>
                    <div><label class="label">Quatre premiers enfants (0 : plus à charge, 1 : ≤ 20 ans, 2 : étudiant ≤ 25 ans)</label>
                        <div class="flex gap-1">@foreach(['enf1', 'enf2', 'enf3', 'enf4'] as $k)<select name="e[{{ $k }}]" class="input !px-1" @disabled($lock)><option value="">—</option>@foreach(['0', '1', '2'] as $v)<option @selected(($f[$k] ?? '') === $v)>{{ $v }}</option>@endforeach</select>@endforeach</div></div>
                </div>
            @endunless
            <div><div class="label">Impôts et taxes payés sur la base de cette déclaration</div>
                <div class="grid gap-1.5 sm:grid-cols-2">@foreach($IMP as $k => [$fr, $ar])<label class="flex items-start gap-2"><input type="checkbox" name="imp[]" value="{{ $k }}" @checked(in_array($k, $coches, true)) class="mt-0.5 rounded border-slate-300 text-brand-600" @disabled($lock)> {{ $fr }}</label>@endforeach</div></div>
            <label class="flex items-center gap-2"><input type="hidden" name="e[privilege]" value="0"><input type="checkbox" name="e[privilege]" value="1" @checked(! empty($f['privilege'])) class="rounded border-slate-300" @disabled($lock)> Poursuite de l’exploitation d’une entreprise transmise, dans le cadre des avantages fiscaux</label>
        </div>

        @foreach($DEF as $s => [$titre, $titreAr, $L])
            <div class="card overflow-hidden"><div class="card-h"><h2 class="card-t">{{ $titre }}</h2></div>
                <div class="card-b space-y-2">
                    @foreach($L as $k => $def)
                        @if($k === 'taux')
                            <div class="flex items-center gap-2"><label class="flex-1 text-sm">{{ $def[0] }}</label><select name="f[taux]" class="input !w-40 !py-1" @disabled($lock)>@foreach($A::TAUX_IS as $t)<option value="{{ $t }}" @selected((float) ($f['taux'] ?? 15) == $t)>{{ $t }} %</option>@endforeach</select><span class="w-16"></span></div>
                        @else
                            @include('declarations._annuelle_champ', ['k' => $k, 'def' => $def, 'val' => $calcules[$k] ?? ($f[$k] ?? null), 'nom' => "f[$k]"])
                        @endif
                    @endforeach
                    @includeIf('declarations._annuelle_'.$mod.'_'.$s, ['C' => $C, 'f' => $f])
                </div>
                @foreach($TAB as $t => [$tt, $tar, $cols, $nb]) @if(($A::PLACE[$mod][$t] ?? null) === $s)
                    <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-800"><div class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tt }}</div>
                        <table class="tbl text-xs"><thead><tr>@foreach($cols as [$cl])<th>{{ $cl }}</th>@endforeach</tr></thead><tbody>
                        @for($i = 0; $i < max($nb, count($f[$t] ?? [])); $i++)
                            <tr>@foreach($cols as $c => [, , $cn])<td><input name="t[{{ $t }}][{{ $i }}][{{ $c }}]" value="{{ $f[$t][$i][$c] ?? '' }}" @if($cn === 'd') type="date" @endif class="input !py-1 {{ in_array($cn, ['m', 'r'], true) ? 'text-right font-mono' : '' }}" @disabled($lock)></td>@endforeach</tr>
                        @endfor
                        </tbody></table></div>
                @endif @endforeach
            </div>
        @endforeach
    </div>
    <div class="space-y-4 self-start lg:sticky lg:top-4">
        @if($is)
            <x-stat label="Impôt sur les sociétés dû" :value="tnd($C['is_du'])" icon="scale" tone="amber"/>
            <x-stat label="{{ $C['IV'] >= 0 ? 'Solde d’impôt à payer' : 'Excédent d’impôt' }}" :value="tnd(abs($C['IV']))" icon="wallet" :tone="$C['IV'] >= 0 ? 'red' : 'green'"/>
            <div class="card card-b space-y-1 text-xs">
                <div class="flex justify-between"><span>Impôt au taux de {{ $C['taux'] }} %</span><span class="font-mono">{{ tnd($C['impot_calc'], false) }}</span></div>
                <div class="flex justify-between"><span>Minimum d’impôt</span><span class="font-mono">{{ tnd($C['min'], false) }}</span></div>
                <div class="flex justify-between"><span>Déductions (retenues, acomptes, excédents…)</span><span class="font-mono">{{ tnd($C['II'], false) }}</span></div>
                <div class="flex justify-between"><span>Contribution sociale de solidarité ({{ $C['css_taux'] }} %)</span><span class="font-mono">{{ tnd($C['css_net'], false) }}</span></div>
                @if($C['cc_du'])<div class="flex justify-between"><span>Contribution conjoncturelle (4 %)</span><span class="font-mono">{{ tnd($C['cc_net'], false) }}</span></div>@endif
            </div>
        @else
            <x-stat label="Impôt sur le revenu dû" :value="tnd($C['impot'])" icon="scale" tone="amber"/>
            <x-stat label="{{ $C['solde'] >= 0 ? 'Solde d’impôt à payer' : 'Excédent d’impôt' }}" :value="tnd(abs($C['solde']))" icon="wallet" :tone="$C['solde'] >= 0 ? 'red' : 'green'"/>
            <div class="card card-b space-y-1 text-xs">
                @foreach(['bic' => 'Bénéfices industriels et commerciaux', 'bnc' => 'Bénéfices non commerciaux', 'agr' => 'Bénéfices agricoles', 'fon' => 'Revenus fonciers', 'sal' => 'Salaires et pensions (nets)', 'rcm' => 'Revenus de capitaux mobiliers', 'aut' => 'Autres revenus'] as $k => $l)
                    @if($C[$k])<div class="flex justify-between"><span>{{ $l }}</span><span class="font-mono">{{ tnd($C[$k], false) }}</span></div>@endif
                @endforeach
                <div class="flex justify-between border-t pt-1 font-semibold"><span>Revenu global net</span><span class="font-mono">{{ tnd($C['rng'], false) }}</span></div>
                @if($C['pertes_imput'])<div class="flex justify-between"><span>Pertes antérieures imputées</span><span class="font-mono">− {{ tnd($C['pertes_imput'], false) }}</span></div>@endif
                <div class="flex justify-between"><span>Déductions (activité, réinvestissement, communes)</span><span class="font-mono">− {{ tnd($C['ded_act'] + $C['reinv'] + $C['ded_communes'], false) }}</span></div>
                <div class="text-slate-500">dont chef de famille {{ tnd($C['ded_chef'], false) }}, enfants {{ tnd($C['ded_enf'], false) }}, parents {{ tnd($C['ded_parents'], false) }}</div>
                <div class="flex justify-between border-t pt-1 font-semibold"><span>Revenu net imposable</span><span class="font-mono">{{ tnd($C['rni_arrondi'], false) }}</span></div>
                <div class="flex justify-between"><span>Impôt selon le barème</span><span class="font-mono">{{ tnd($C['impot_bareme'], false) }}</span></div>
                <div class="flex justify-between"><span>Minimum d’impôt</span><span class="font-mono">{{ tnd($C['min'], false) }}</span></div>
                <div class="flex justify-between"><span>Contribution sociale de solidarité (0,5 %)</span><span class="font-mono">{{ tnd($C['css_net'], false) }}</span></div>
            </div>
        @endif
        <div class="card overflow-hidden"><div class="card-h"><h2 class="card-t">Synthèse</h2></div>
            <table class="tbl text-xs"><thead><tr><th>Impôt ou taxe</th><th class="text-right">Dû</th><th class="text-right">Pénalités</th><th class="text-right">Total</th></tr></thead><tbody>
                @foreach($C['synthese'] as $k => $x)<tr class="{{ $x['tot'] ? '' : 'text-slate-400' }}"><td>{{ $A::LIB_SYNTHESE[$k] }}</td><td class="num">{{ tnd($x['III'], false) }}</td><td class="num">{{ tnd($x['pen'], false) }}</td><td class="num">{{ tnd($x['tot'], false) }}</td></tr>@endforeach
            </tbody><tfoot><tr><td>Total à payer</td><td></td><td></td><td class="num">{{ tnd($C['tot'], false) }}</td></tr></tfoot></table></div>
        @unless($lock)<button class="btn-primary w-full justify-center">Enregistrer</button>@endunless
        <a href="{{ route('declarations.arabe', $q) }}" class="btn-secondary w-full justify-center"><x-icon name="printer" class="h-4 w-4"/> Formulaire officiel (arabe)</a>
        <div class="card card-b text-xs text-slate-500">Le modèle dépend de la fiche client : {{ $is ? 'personne morale → impôt sur les sociétés' : 'personne physique → impôt sur le revenu' }}.
            « Pré-remplir » reprend les états financiers, la liasse fiscale (résultat fiscal), le tableau de passage, les acomptes et retenues ; les montants restent modifiables.</div>
    </div>
</div>
</form>
