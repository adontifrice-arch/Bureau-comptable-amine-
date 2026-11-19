@php $u = fn($t) => route('liasse.index', ['tab' => $t, 'annee' => $annee]); @endphp
<x-layouts.app title="Liasse fiscale">
    <x-page-header title="Liasse fiscale & états financiers" :subtitle="$client->nom.' · exercice clos le 31/12/'.$annee">
        <x-client-picker :clients="$clients" :current="$client" :route="route('liasse.index')" :params="['tab' => $tab, 'annee' => $annee]"/>
        <form><input type="hidden" name="tab" value="{{ $tab }}"><input type="number" name="annee" value="{{ $annee }}" class="input w-24" onchange="this.form.submit()"></form>
        <a href="{{ route('liasse.print', ['annee' => $annee]) }}" class="btn-secondary"><x-icon name="printer" class="h-4 w-4"/> Bilan et résultat (impression rapide)</a>
        <a href="{{ $u('ef') }}" class="btn-primary"><x-icon name="file-text" class="h-4 w-4"/> États financiers complets</a>
    </x-page-header>
    @if(!$F['n'])<div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Aucune écriture comptabilisée pour cet exercice : les états sont alimentés par les écritures validées dans l’onglet Comptabilité.</div>@endif
    <x-tabs :active="$tab" :items="['bil' => ['Bilan', $u('bil')], 'res' => ['État de résultat', $u('res')], 'fis' => ['Passage au résultat fiscal', $u('fis')], 'ef' => ['États financiers', $u('ef')], 'lf' => ['Liasse fiscale (XML)', $u('lf')]]"/>
    @if($tab === 'lf')
        @include('liasse._liasse_fiscale')
    @elseif($tab === 'ef')
        @include('liasse._etats-financiers')
    @elseif($tab === 'fis')
        <div class="grid gap-6 lg:grid-cols-3">
            @php
                $TP = \App\Services\TableauPassage::class; $num = $TP::numeroAutres($annexe);
                $mt = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
                $autresRei = array_pad(array_values($passage['rei_autres']), $TP::AUTRES_LIGNES, ['lib' => '', 'm' => '']);
                $autresDed = array_pad(array_values($passage['ded_autres']), $TP::AUTRES_LIGNES, ['lib' => '', 'm' => '']);
                $impLib = $client->estPhysique() ? 'IRPP et CSS comptabilisés (comptes 69)' : 'Impôt sur les sociétés comptabilisé (comptes 69)';
            @endphp
            <form method="POST" action="{{ route('liasse.passage') }}" class="card lg:col-span-2"
                  x-data="{ k: 0, res: {{ (float) $fisc['res'] }}, imp: {{ (float) $fisc['impots'] }},
                            somme(sel) { this.k; return [...this.$root.querySelectorAll(sel)].reduce((s, i) => s + (parseFloat(i.value) || 0), 0) },
                            fmt(v) { return v.toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) },
                            cf: @js(['annexe' => $annexe, 'prov' => array_keys($TP::provisions($annexe)), 'def' => collect($TP::DEFICITS)->map(fn ($d) => $d[2]),
                                     'exc' => array_keys($TP::exceptionnels($annexe)), 'vi' => array_merge(...array_map('array_keys', array_values($TP::NON_ELIGIBLES))),
                                     'exploit' => ['e1', 'e5', 'e6', 'e2']]),
                            {{-- Aperçu pendant la saisie : mêmes règles que le F6005 de la liasse (LiasseFiscale::resultatFiscal), recalculé à l’enregistrement --}}
                            c() {
                                this.k;
                                const v = (k) => parseFloat(this.$root.querySelector(`[name='suite[${k}]']`)?.value) || 0;
                                const sum = (ks) => ks.reduce((s, k) => s + v(k), 0);
                                const r = { prov: 0, apres_prov: 0, mv: 0, avant_deficits: 0, apres_deficits: 0, V: 0, VI: 0, imposable: 0, deficit: 0, deficitaire: false };
                                r.avant_prov = this.res + this.imp + this.somme('[data-rei]') - this.somme('[data-ded]');
                                r.prov_limite = Math.max(0, r.avant_prov) / 2;
                                const amort = v('a1') - v('a2') - v('a3') - v('a4');
                                if (r.avant_prov < 0) { r.deficitaire = true; r.deficit = r.avant_prov + amort; return r; }                       // cas 1
                                r.prov = Math.min(sum(this.cf.prov), r.prov_limite);
                                r.apres_prov = r.avant_prov - r.prov;
                                r.mv = this.cf.annexe === 2 ? Math.min(v('mv'), Math.max(0, r.apres_prov) * 0.05) : 0;
                                r.avant_deficits = Math.max(r.apres_prov - r.mv, 0);
                                const x = r.avant_deficits + amort;
                                if (x < 0) { r.deficitaire = true; r.deficit = amort; return r; }                                                  // cas 2 à 4
                                r.apres_deficits = x;
                                r.V = Math.max(x - sum(this.cf.exc), 0);
                                r.VI = r.V - sum(this.cf.vi);
                                const apres = r.V - sum(this.cf.exploit);
                                r.imposable = apres < 0 ? 0 : apres - v('e3') + v('e4');                                                          // cas 5
                                return r;
                            } }" @input="k++">@csrf <input type="hidden" name="annee" value="{{ $annee }}">
                <div class="card-h"><h2 class="card-t">Tableau de détermination du résultat fiscal</h2>
                    <span class="text-xs text-slate-400">Note commune n° 26/2016 — annexe {{ $annexe }} ({{ $annexe === 1 ? 'personnes physiques, sociétés de personnes et assimilées' : 'sociétés passibles de l’impôt sur les sociétés' }})</span></div>
                <table class="tbl">
                    <tbody>
                        <tr class="bg-slate-50 font-semibold dark:bg-slate-800/40"><td colspan="2">Résultat comptable après impôt ({{ $fisc['res'] >= 0 ? 'bénéfice' : 'déficit' }})</td><td class="num w-44">{{ tnd($fisc['res']) }}</td></tr>

                        <tr class="bg-slate-100/70 font-semibold dark:bg-slate-800"><td colspan="3">I- Réintégrations</td></tr>
                        <tr><td class="w-14 text-slate-500">—</td><td>{{ $impLib }}</td><td class="num">{{ tnd($fisc['impots']) }}</td></tr>
                        @foreach($TP::reintegrations($annexe) as $groupe => $lignes)
                            <tr><td colspan="3" class="pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $loop->iteration }}. {{ $groupe }}</td></tr>
                            @foreach($lignes as $code => [$n, $lib])
                                <tr><td class="text-slate-500">{{ $n }}</td><td><label for="rei-{{ $code }}">{{ $lib }}</label></td>
                                    <td><input id="rei-{{ $code }}" data-rei type="number" step="0.001" name="rei_l[{{ $code }}]" value="{{ $mt(old("rei_l.$code", $passage['rei_l'][$code] ?? '')) }}" class="input text-right font-mono"></td></tr>
                            @endforeach
                        @endforeach
                        <tr><td colspan="3" class="pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $num['rei'] }}. Autres réintégrations (à préciser)</td></tr>
                        @foreach($autresRei as $i => $l)
                            <tr><td class="text-slate-500">{{ $num['rei'] }}</td><td><input name="rei_autres[{{ $i }}][lib]" value="{{ $l['lib'] }}" class="input" placeholder="Nature de la réintégration" aria-label="Autre réintégration {{ $i + 1 }}"></td>
                                <td><input data-rei type="number" step="0.001" name="rei_autres[{{ $i }}][m]" value="{{ $mt($l['m']) }}" class="input text-right font-mono" aria-label="Montant de l’autre réintégration {{ $i + 1 }}"></td></tr>
                        @endforeach
                        <tr class="font-semibold"><td colspan="2">Total réintégrations</td><td class="num" x-text="fmt(imp + somme('[data-rei]'))">{{ tnd($fisc['impots'] + $fisc['rei']) }}</td></tr>

                        <tr class="bg-slate-100/70 font-semibold dark:bg-slate-800"><td colspan="3">II- Déductions</td></tr>
                        @foreach($TP::deductions($annexe) as $code => [$n, $lib])
                            <tr><td class="text-slate-500">{{ $n }}</td><td><label for="ded-{{ $code }}">{{ $lib }}</label></td>
                                <td><input id="ded-{{ $code }}" data-ded type="number" step="0.001" name="ded_l[{{ $code }}]" value="{{ $mt(old("ded_l.$code", $passage['ded_l'][$code] ?? '')) }}" class="input text-right font-mono"></td></tr>
                        @endforeach
                        @foreach($autresDed as $i => $l)
                            <tr><td class="text-slate-500">{{ $num['ded'] }}</td><td><input name="ded_autres[{{ $i }}][lib]" value="{{ $l['lib'] }}" class="input" placeholder="Autre déduction (à préciser)" aria-label="Autre déduction {{ $i + 1 }}"></td>
                                <td><input data-ded type="number" step="0.001" name="ded_autres[{{ $i }}][m]" value="{{ $mt($l['m']) }}" class="input text-right font-mono" aria-label="Montant de l’autre déduction {{ $i + 1 }}"></td></tr>
                        @endforeach
                        <tr class="font-semibold"><td colspan="2">Total déductions</td><td class="num" x-text="fmt(somme('[data-ded]'))">{{ tnd($fisc['ded']) }}</td></tr>

                        @php
                            $Kc = $fisc['calc']; $s = $passage['suite'];
                            $inp = fn ($code, $lbl) => '<input type="number" step="0.001" min="0" name="suite['.$code.']" value="'.e($mt(old("suite.$code", $s[$code] ?? ''))).'" class="input text-right font-mono" aria-label="'.e($lbl).'">';
                            $ligneTotal = fn ($lib, $expr, $val, $fort = false) => '<tr class="'.($fort ? 'bg-slate-50 font-semibold dark:bg-slate-800/40' : 'font-semibold').'"><td colspan="2">'.e($lib).'</td><td class="num" x-text="fmt('.$expr.')">'.tnd($val).'</td></tr>';
                            $titre = fn ($t) => '<tr class="bg-slate-100/70 font-semibold dark:bg-slate-800"><td colspan="3">'.e($t).'</td></tr>';
                        @endphp
                        {!! $ligneTotal('Résultat fiscal avant déduction des provisions', 'c().avant_prov', $Kc['avant_prov'], true) !!}

                        <tr><td colspan="3" class="pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Déduction des provisions dans la limite de 50 % du résultat fiscal</td></tr>
                        @foreach($TP::provisions($annexe) as $code => $lib)
                            <tr><td class="text-slate-500">—</td><td>{{ $lib }}</td><td>{!! $inp($code, $lib) !!}</td></tr>
                        @endforeach
                        <tr><td></td><td class="text-xs text-slate-500">Provisions déduites (plafond : <span x-text="fmt(c().prov_limite)">{{ tnd($Kc['prov_limite']) }}</span>)</td><td class="num" x-text="fmt(c().prov)">{{ tnd($Kc['prov']) }}</td></tr>
                        {!! $ligneTotal('Résultat fiscal après déduction des provisions', 'c().apres_prov', $Kc['apres_prov']) !!}
                        @if($annexe === 2)
                            <tr><td class="text-slate-500">—</td><td>{{ $TP::MOINS_VALUE_OPTION }} <span class="text-xs text-slate-500">(retenu : <span x-text="fmt(c().mv)">{{ tnd($Kc['mv']) }}</span>)</span></td><td>{!! $inp('mv', 'Moins-value de levée d’option') !!}</td></tr>
                        @endif
                        {!! $ligneTotal('Résultat fiscal après déduction des provisions et avant déduction des déficits et des amortissements', 'c().avant_deficits', $Kc['avant_deficits']) !!}

                        {!! $titre('III- Déduction des amortissements différés et déficits reportés') !!}
                        @foreach($TP::DEFICITS as $code => [$n, $lib, $sg])
                            <tr><td class="text-slate-500">{{ $n }}</td><td>{{ $lib }} <span class="text-xs text-slate-400">({{ $sg > 0 ? '+' : '−' }})</span></td><td>{!! $inp($code, $lib) !!}</td></tr>
                        @endforeach
                        {!! $ligneTotal('Résultat fiscal après déduction des déficits et amortissements', 'c().apres_deficits', $Kc['apres_deficits']) !!}

                        {!! $titre('IV- Déduction des bénéfices ou revenus exceptionnels non imposables') !!}
                        @foreach($TP::exceptionnels($annexe) as $code => [$n, $lib])
                            <tr><td class="text-slate-500">{{ $n }}</td><td>{{ $lib }}</td><td>{!! $inp($code, $lib) !!}</td></tr>
                        @endforeach
                        {!! $ligneTotal('V- Résultat fiscal avant déduction des bénéfices provenant de l’exploitation', 'c().V', $Kc['V'], true) !!}

                        {!! $titre('VI- Bénéfices servant de base pour la détermination de la quote-part des bénéfices provenant de l’exploitation déductible') !!}
                        <tr><td colspan="3" class="text-xs text-slate-500">Déduction des bénéfices et gains exceptionnels non éligibles aux avantages fiscaux (VI = V − ces bénéfices et gains)</td></tr>
                        @foreach($TP::NON_ELIGIBLES as $groupe => $lignes)
                            @foreach($lignes as $code => [$n, $lib])
                                <tr><td class="text-slate-500">{{ $n }}</td><td>{{ $groupe }} : {{ mb_strtolower(mb_substr($lib, 0, 1)).mb_substr($lib, 1) }}</td><td>{!! $inp($code, $lib) !!}</td></tr>
                            @endforeach
                        @endforeach
                        {!! $ligneTotal('Bénéfices servant de base à la quote-part (VI)', 'c().VI', $Kc['VI']) !!}

                        {!! $titre($annexe === 1 ? 'VII- Bénéfice après déduction' : 'VII- Bénéfice fiscal après déduction des bénéfices au titre de l’exploitation') !!}
                        @foreach($TP::finales($annexe) as $code => [$lib, $sg])
                            <tr><td class="text-slate-500">{{ $sg > 0 ? '+' : '−' }}</td><td>{{ $lib }}</td><td>{!! $inp($code, $lib) !!}</td></tr>
                        @endforeach
                        {!! $ligneTotal('Résultat imposable', 'c().imposable', $Kc['imposable'], true) !!}
                        <tr x-show="c().deficitaire" @if(! $Kc['deficitaire']) x-cloak @endif class="bg-rose-50 font-semibold text-rose-700 dark:bg-rose-950/40">
                            <td colspan="2">Résultat fiscal déficitaire : déficit reportable</td><td class="num" x-text="fmt(c().deficit)">{{ tnd($Kc['deficit_reportable']) }}</td></tr>
                    </tbody>
                </table>
                @if($errors->any())<p class="px-5 pt-3 text-xs text-rose-600">{{ $errors->first() }}</p>@endif
                <div class="card-h border-t"><h2 class="card-t">Impôt</h2></div>
                <div class="card-b grid gap-4 md:grid-cols-3">
                    @unless($client->estPhysique())<x-field label="Taux de l’IS (%)" name="taux" type="number" step="0.01" :value="$fisc['taux']"/>@endunless
                    <x-field label="Minimum d’impôt" name="min" type="number" step="0.001" :value="$fisc['min']"/>
                    <x-field label="Acomptes provisionnels versés" name="acp" type="number" step="0.001" :value="$fisc['acp']"/>
                    <x-field label="Retenues à la source subies" name="rs" type="number" step="0.001" :value="$fisc['rs']"/>
                </div>
                <div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-primary">Enregistrer le calcul</button></div>
            </form>
            <div class="card card-b space-y-2 self-start text-sm">
                <h3 class="card-t mb-2">Résultat</h3>
                @if($ir = $fisc['ir'])
                    @php $ligne = fn ($lib, $m, $signe = '−') => '<div class="flex justify-between gap-3"><span>'.e($lib).'</span><span class="font-mono whitespace-nowrap">'.($m != 0 ? $signe.' ' : '').tnd($m).'</span></div>'; @endphp
                    <div class="flex justify-between gap-3"><span>Résultat fiscal de l’activité (avant déduction des bénéfices d’exploitation)</span><span class="font-mono whitespace-nowrap">{{ tnd($ir['activite']) }}</span></div>
                    @if($ir['autres'] != 0){!! $ligne('Autres revenus de la déclaration IR', $ir['autres'], '+') !!}@endif
                    {!! $ligne('Pertes des années antérieures non encore déduites', $ir['pertes']) !!}
                    {!! $ligne('Déductions au titre des revenus et bénéfices d’activité', $ir['ded_act']) !!}
                    {!! $ligne('Déductions des revenus réinvestis', $ir['reinv']) !!}
                    {!! $ligne('Déductions communes', $ir['ded_communes']) !!}
                    <div class="pl-3 text-xs text-slate-500">dont chef de famille {{ tnd($ir['ded_chef'], false) }}, enfants {{ tnd($ir['ded_enf'], false) }}, parents {{ tnd($ir['ded_parents'], false) }}</div>
                    <div class="flex justify-between gap-3 border-t border-slate-200 pt-2"><span>Résultat imposable</span><span class="font-mono">{{ tnd($ir['rni']) }}</span></div>
                    <div class="flex justify-between gap-3"><span>Résultat imposable arrondi au dinar supérieur</span><b class="font-mono">{{ tnd($ir['rni_arrondi']) }}</b></div>
                    <p class="text-xs text-slate-400">Pertes, déductions et autres revenus : ceux de la déclaration annuelle IR de l’exercice ; situation familiale de la fiche client.</p>
                @else
                    <div class="flex justify-between"><span>Résultat fiscal (avant provisions)</span><span class="font-mono">{{ tnd($fisc['rf']) }}</span></div>
                    <div class="flex justify-between"><span>Résultat imposable</span><b class="font-mono">{{ tnd($fisc['imposable']) }}</b></div>
                @endif
                <div class="flex justify-between"><span>Impôt théorique {{ $client->estPhysique() ? '(barème IRPP)' : '' }}</span><span class="font-mono">{{ tnd($fisc['th']) }}</span></div>
                <div class="flex justify-between"><span>Impôt dû</span><b class="font-mono">{{ tnd($fisc['du']) }}</b></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base"><span>{{ $fisc['sol'] >= 0 ? 'Solde à payer' : 'Crédit d’impôt' }}</span><b class="font-mono {{ $fisc['sol'] >= 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ tnd(abs($fisc['sol'])) }}</b></div>
                <p class="pt-2 text-xs text-slate-400">À vérifier selon la situation de la société (taux, exonérations, minimum d’impôt).</p>
            </div>
        </div>
    @else
        @include('liasse._etats', ['show' => [$tab]])
    @endif
</x-layouts.app>
