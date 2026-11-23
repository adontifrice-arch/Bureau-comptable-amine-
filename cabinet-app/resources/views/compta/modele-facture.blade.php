{{--
    Paramètres facture achat / vente : modèle de saisie d'une facture (journal, comptes TTC, FODEC, avance et timbre avec leur sens,
    lignes « objet · compte assiette · taux TVA · compte TVA · assiette majorée · taux TVA majoré · compte TVA majoré · débiteur »).
    F4 enregistre, F1 recherche un compte pour le champ en cours.
--}}
@php
    $achat = $type === 'achat';
    $titre = 'paramètres facture '.($achat ? 'achat' : 'vente');
    $cfg = [
        'lignes' => old('lignes', $m->lignesModele()),
        'debitDefaut' => $achat,
        'comptes' => ['compte_ttc' => old('compte_ttc', $m->compte_ttc), 'compte_fodec' => old('compte_fodec', $m->compte_fodec), 'compte_avance' => old('compte_avance', $m->compte_avance), 'compte_timbre' => old('compte_timbre', $m->compte_timbre)],
    ];
@endphp
<x-layouts.app :title="ucfirst($titre)">
    <script>
        window.PLAN = @js((object) $plan); window.URL_COMPTES = @js(route('compta.comptes.store'));
        window.parametresFacture = (cfg) => window.avecRecherche({
            lignes: cfg.lignes.map(l => ({ ...l, debit: !! l.debit })),
            c: cfg.comptes, cur: 0, champ: 'objet',
            CHAMPS: ['objet', 'compte', 'tva_taux', 'compte_tva', 'compte_majore', 'taux_majore', 'compte_tva_majore'],
            vide() { return { objet: '', compte: '', tva_taux: 0, compte_tva: '', compte_majore: '', taux_majore: 0, compte_tva_majore: '', debit: cfg.debitDefaut }; },
            init() { if (! this.lignes.length) this.lignes.push(this.vide()); },
            focus(i, k) { this.cur = i; this.champ = k; this.$nextTick(() => { const el = this.$root.querySelector(`[data-cell="${i}-${k}"]`); el?.focus(); el?.select?.(); }); },
            ajouter() { this.lignes.push(this.vide()); this.focus(this.lignes.length - 1, 'objet'); },
            modifier() { this.focus(this.cur, 'objet'); },
            supprimer() { if (! this.lignes.length) return; if (! confirm('Supprimer la ligne « ' + (this.lignes[this.cur].objet || '') + ' » ?')) return; this.lignes.splice(this.cur, 1); if (! this.lignes.length) this.lignes.push(this.vide()); this.cur = Math.min(this.cur, this.lignes.length - 1); },
            /** Entrée et flèches : cellule suivante, ligne précédente / suivante. */
            touche(ev, i, k) {
                const n = this.CHAMPS.indexOf(k);
                if (ev.key === 'Enter') { ev.preventDefault(); if (n < this.CHAMPS.length - 1) return this.focus(i, this.CHAMPS[n + 1]); if (i === this.lignes.length - 1) this.lignes.push(this.vide()); this.focus(i + 1, 'objet'); }
                else if (ev.key === 'ArrowUp' && i > 0) { ev.preventDefault(); this.focus(i - 1, k); }
                else if (ev.key === 'ArrowDown' && i < this.lignes.length - 1) { ev.preventDefault(); this.focus(i + 1, k); }
            },
            /** Compte choisi dans la recherche : champ d'en-tête (« compte_ttc »…) ou cellule de ligne (« 3-compte_tva »). */
            compteChoisi(cible, n) {
                if (cible in this.c) { this.c[cible] = n; return; }
                const [i, k] = cible.split('-'); this.lignes[+i][k] = n; this.focus(+i, k);
            },
            touches(ev) {
                if (this.rech.ouvert) return;
                if (ev.key === 'F4') { ev.preventDefault(); this.$refs.form.requestSubmit(); }
                else if (ev.key === 'F1') {
                    ev.preventDefault();
                    const el = document.activeElement, cell = el?.dataset?.cell, hd = el?.dataset?.entete;
                    if (hd) this.chercher(hd, this.c[hd]);
                    else if (cell && cell.split('-')[1].startsWith('compte')) this.chercher(cell, el.value);
                    else this.chercher(this.cur + '-compte', this.lignes[this.cur]?.compte);
                }
            },
            lib(n) { return n ? (window.libelleCompte(n) || 'Compte absent du plan comptable') : ''; },
        });
    </script>
    <style>
        .fm-grille { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .fm-grille th { background: linear-gradient(#f8fafc, #d9e1ea); border: 1px solid #cbd5e1; padding: .3rem .4rem; font-size: .75rem; font-weight: 600; color: #334155; line-height: 1.1; }
        .fm-grille td { border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px dotted #e2e8f0; height: 1.9rem; padding: 0; }
        .fm-grille td input:not([type=checkbox]) { width: 100%; height: 1.9rem; border: 0; background: transparent; padding: 0 .4rem; font-size: .85rem; outline: none; }
        .fm-grille td input:focus { background: #e0f2fe; box-shadow: inset 0 0 0 1px #0ea5e9; }
        .fm-grille tr.courante td { background: #dbeafe; }
        .fm-grille tr.courante td:first-child input { font-weight: 700; }
        .fm-grille .num { text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .dark .fm-grille th { background: #1e293b; border-color: #334155; color: #cbd5e1; }
        .dark .fm-grille td { border-color: #334155; } .dark .fm-grille tr.courante td { background: rgba(14, 165, 233, .12); }
        .fm-cpt { display: flex; align-items: stretch; } .fm-cpt input { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .fm-loupe { display: flex; width: 2rem; align-items: center; justify-content: center; border: 1px solid #cbd5e1; border-left: 0; border-radius: 0 .375rem .375rem 0; background: #f8fafc; color: #334155; }
        .fm-loupe:hover { background: #e0f2fe; } .dark .fm-loupe { background: #0f172a; border-color: #334155; color: #cbd5e1; }
        .fm-sens { display: inline-flex; align-items: center; gap: .4rem; border: 1px solid #cbd5e1; border-radius: .375rem; padding: .3rem .6rem; font-size: .8rem; white-space: nowrap; }
        .dark .fm-sens { border-color: #334155; }
        .fm-outil { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; color: #334155; } .fm-outil:hover { color: #0369a1; }
        .dark .fm-outil { color: #cbd5e1; }
    </style>

    <div x-data="parametresFacture(@js($cfg))" @keydown.window="touches($event)" class="mx-auto max-w-7xl">
        <form x-ref="form" method="POST" action="{{ $m->exists ? route('compta.modeles-factures.update', $m) : route('compta.modeles-factures.store', $type) }}" class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">@csrf @if($m->exists) @method('PUT') @endif
            {{-- Bandeau --}}
            <div class="flex flex-wrap items-center gap-3 bg-sky-600 px-4 py-2.5 text-white">
                <a href="{{ route('compta.index', ['tab' => 'param']) }}" class="rounded p-1 hover:bg-white/15" title="Retour au paramétrage"><x-icon name="arrow-left" class="h-5 w-5"/></a>
                <h1 class="text-lg font-bold">{{ $titre }}</h1>
                <span class="text-sm text-sky-100">{{ $client->nom }}</span>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    @if($modeles->count())
                        <select class="rounded border-0 bg-white/15 py-1 pl-2 pr-8 text-sm text-white [&>option]:text-slate-900" onchange="location.href = this.value" aria-label="Modèle affiché">
                            @foreach($modeles as $x)<option value="{{ route('compta.modeles-factures', ['type' => $type, 'modele' => $x->id]) }}" @selected($m->id === $x->id)>{{ $x->numero }} — {{ $x->libelle }}</option>@endforeach
                            @unless($m->exists)<option selected>Nouveau modèle</option>@endunless
                        </select>
                    @endif
                    <a href="{{ route('compta.modeles-factures', ['type' => $type, 'nouveau' => 1]) }}" class="flex items-center gap-1.5 rounded px-3 py-1.5 text-sm hover:bg-white/15"><x-icon name="plus" class="h-4 w-4"/> Nouveau</a>
                    @if($m->exists)<button type="button" class="flex items-center gap-1.5 rounded px-3 py-1.5 text-sm hover:bg-white/15" @click="if (confirm('Supprimer le modèle {{ $m->numero }} ? Les factures déjà saisies gardent leur paramétrage.')) $refs.suppr.submit()"><x-icon name="trash" class="h-4 w-4"/> Supprimer</button>@endif
                    <button class="flex items-center gap-1.5 rounded bg-white/15 px-3 py-1.5 text-sm font-semibold hover:bg-white/25"><x-icon name="check" class="h-4 w-4"/> Enregistrer (F4)</button>
                </div>
            </div>

            {{-- En-tête du modèle --}}
            <div class="grid gap-x-8 gap-y-2.5 p-4 text-sm lg:grid-cols-[minmax(0,34rem)_minmax(0,1fr)]">
                <div class="space-y-2.5">
                    <div class="flex items-center gap-3"><span class="w-32 font-medium">N° Model</span><span class="input !w-36 !py-1 font-mono">{{ $m->numero }}</span></div>
                    <div class="flex items-center gap-3"><label for="mf-lib" class="w-32 font-medium">Libéllé du model</label><input id="mf-lib" name="libelle" value="{{ old('libelle', $m->libelle) }}" maxlength="100" class="input !py-1 uppercase" required autofocus placeholder="ex. {{ $achat ? 'ACHAT' : 'VENTE' }} {{ mb_strtoupper(\Illuminate\Support\Str::limit($client->nom, 20, '')) }}"></div>
                    <div class="flex items-center gap-3"><label for="mf-j" class="w-32 font-medium">Journal</label>
                        <select id="mf-j" name="journal" class="input !w-28 !py-1 font-semibold">@foreach($journaux as $k => $l)<option value="{{ $k }}" @selected(old('journal', $m->journal) === (string) $k)>{{ $k }}</option>@endforeach
                            @unless(isset($journaux[$m->journal]))<option value="{{ $m->journal }}" selected>{{ $m->journal }}</option>@endunless</select>
                        <span class="text-slate-500">{{ $journaux[old('journal', $m->journal)] ?? '' }}</span></div>
                </div>
                <div></div>
                @php
                    $champ = fn (string $k, string $label, string $sens, string $sensLabel) => compact('k', 'label', 'sens', 'sensLabel');
                    $champs = [[$champ('compte_ttc', 'Compte TTC', 'ttc_debit', 'TTC Débiteur'), null],
                        [$champ('compte_fodec', 'Compte FODEC', 'fodec_debit', 'Fodec débiteur'), $champ('compte_avance', 'Compte AVANCE', 'avance_debit', 'Avance débiteur')],
                        [$champ('compte_timbre', 'Compte TIMBRE', 'timbre_debit', 'Timbre débiteur'), 'timbre']];
                @endphp
                @foreach($champs as [$g, $d])
                    <div class="flex items-start gap-3">
                        <label for="mf-{{ $g['k'] }}" class="w-32 pt-1.5 font-medium">{{ $g['label'] }}</label>
                        <div><div class="fm-cpt"><input id="mf-{{ $g['k'] }}" name="{{ $g['k'] }}" x-model="c.{{ $g['k'] }}" data-entete="{{ $g['k'] }}" class="input !w-36 !py-1 font-mono" data-compte autocomplete="off" inputmode="numeric"><button type="button" class="fm-loupe" @click="chercher('{{ $g['k'] }}', c.{{ $g['k'] }})" title="(F1) Rechercher un compte"><x-icon name="search" class="h-4 w-4"/></button></div>
                            <p class="mt-0.5 w-44 truncate text-xs text-slate-400" x-text="lib(c.{{ $g['k'] }})"></p></div>
                        <label class="fm-sens"><input type="checkbox" name="{{ $g['sens'] }}" value="1" @checked(old($g['sens'], $m->{$g['sens']})) class="rounded border-slate-300 text-sky-600"> {{ $g['sensLabel'] }}</label>
                    </div>
                    <div class="flex items-start gap-3">
                        @if(is_array($d))
                            <label for="mf-{{ $d['k'] }}" class="w-32 pt-1.5 font-medium">{{ $d['label'] }}</label>
                            <div><div class="fm-cpt"><input id="mf-{{ $d['k'] }}" name="{{ $d['k'] }}" x-model="c.{{ $d['k'] }}" data-entete="{{ $d['k'] }}" class="input !w-36 !py-1 font-mono" data-compte autocomplete="off" inputmode="numeric"><button type="button" class="fm-loupe" @click="chercher('{{ $d['k'] }}', c.{{ $d['k'] }})" title="(F1) Rechercher un compte"><x-icon name="search" class="h-4 w-4"/></button></div>
                                <p class="mt-0.5 w-44 truncate text-xs text-slate-400" x-text="lib(c.{{ $d['k'] }})"></p></div>
                            <label class="fm-sens"><input type="checkbox" name="{{ $d['sens'] }}" value="1" @checked(old($d['sens'], $m->{$d['sens']})) class="rounded border-slate-300 text-sky-600"> {{ $d['sensLabel'] }}</label>
                        @elseif($d === 'timbre')
                            <label for="mf-mt" class="w-32 pt-1.5 font-medium">Mnt timbre</label>
                            <input id="mf-mt" type="number" step="0.001" min="0" name="montant_timbre" value="{{ number_format((float) old('montant_timbre', $m->montant_timbre), 3, '.', '') }}" class="input !w-36 !py-1 text-right font-mono">
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Lignes --}}
            <div class="flex items-center gap-5 border-t border-slate-200 px-4 py-2 dark:border-slate-700">
                <button type="button" class="fm-outil" @click="ajouter()"><x-icon name="plus" class="h-4 w-4"/> Ajouter</button>
                <button type="button" class="fm-outil" @click="modifier()"><x-icon name="edit" class="h-4 w-4"/> Modifier</button>
                <button type="button" class="fm-outil !text-rose-600" @click="supprimer()"><x-icon name="trash" class="h-4 w-4"/> Supprimer</button>
                <span class="ml-auto text-xs text-slate-400">Entrée : cellule suivante · ↑ ↓ : ligne · F1 : rechercher un compte · F4 : enregistrer</span>
            </div>
            <div class="overflow-x-auto px-3 pb-3">
                <table class="fm-grille min-w-[64rem]">
                    <colgroup><col><col style="width: 8rem"><col style="width: 6.5rem"><col style="width: 8rem"><col style="width: 8rem"><col style="width: 7rem"><col style="width: 8rem"><col style="width: 5rem"></colgroup>
                    <thead><tr><th>Objet</th><th>Compte assiette</th><th>Taux tva</th><th>Compte MNT TVA</th><th>Assiette majorée</th><th>Taux TVA majoré</th><th>Compte TVA majoré</th><th>Débiteur</th></tr></thead>
                    <tbody>
                        <template x-for="(l, i) in lignes" :key="i">
                            <tr :class="cur === i && 'courante'" @focusin="cur = i" @click="cur = i">
                                <td><input :data-cell="`${i}-objet`" :name="`lignes[${i}][objet]`" x-model="l.objet" maxlength="100" class="uppercase" @keydown="touche($event, i, 'objet')" aria-label="Objet"></td>
                                <td><input :data-cell="`${i}-compte`" :name="`lignes[${i}][compte]`" x-model="l.compte" class="font-mono" inputmode="numeric" data-compte autocomplete="off" :title="lib(l.compte)" @keydown="touche($event, i, 'compte')" aria-label="Compte assiette"></td>
                                <td><input :data-cell="`${i}-tva_taux`" :name="`lignes[${i}][tva_taux]`" x-model="l.tva_taux" type="number" step="0.001" min="0" max="100" class="num" @keydown="touche($event, i, 'tva_taux')" aria-label="Taux TVA"></td>
                                <td><input :data-cell="`${i}-compte_tva`" :name="`lignes[${i}][compte_tva]`" x-model="l.compte_tva" class="font-mono" inputmode="numeric" data-compte autocomplete="off" :title="lib(l.compte_tva)" @keydown="touche($event, i, 'compte_tva')" aria-label="Compte TVA"></td>
                                <td><input :data-cell="`${i}-compte_majore`" :name="`lignes[${i}][compte_majore]`" x-model="l.compte_majore" class="font-mono" inputmode="numeric" data-compte autocomplete="off" :title="lib(l.compte_majore)" @keydown="touche($event, i, 'compte_majore')" aria-label="Compte assiette majorée"></td>
                                <td><input :data-cell="`${i}-taux_majore`" :name="`lignes[${i}][taux_majore]`" x-model="l.taux_majore" type="number" step="0.001" min="0" max="100" class="num" @keydown="touche($event, i, 'taux_majore')" aria-label="Taux TVA majoré"></td>
                                <td><input :data-cell="`${i}-compte_tva_majore`" :name="`lignes[${i}][compte_tva_majore]`" x-model="l.compte_tva_majore" class="font-mono" inputmode="numeric" data-compte autocomplete="off" :title="lib(l.compte_tva_majore)" @keydown="touche($event, i, 'compte_tva_majore')" aria-label="Compte TVA majoré"></td>
                                <td class="text-center"><input type="hidden" :name="`lignes[${i}][debit]`" :value="l.debit ? 1 : 0"><input type="checkbox" x-model="l.debit" class="rounded border-slate-300 text-sky-600" aria-label="Débiteur"></td>
                            </tr>
                        </template>
                        <template x-for="k in Math.max(0, 12 - lignes.length)" :key="'v' + k"><tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr></template>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-slate-400" x-text="cur in lignes ? [lignes[cur].compte, lignes[cur].compte_tva].filter(Boolean).map(n => n + ' ' + lib(n)).join(' · ') : ''"></p>
            </div>

            @if($errors->any())<div class="mx-3 mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

            <div class="border-t border-slate-200 px-4 py-3 text-xs leading-relaxed text-slate-500 dark:border-slate-700">
                <p><b>Sens :</b> chaque montant est passé au débit si sa case « débiteur » est cochée, sinon au crédit. Le TTC équilibre l’écriture : il vaut la somme des montants de sens opposé, moins ceux de même sens (une avance de même sens que le TTC vient en déduction).</p>
                <p><b>Compte TTC :</b> laissé vide, c’est le compte {{ $achat ? 'fournisseur' : 'client' }} choisi à la saisie de la facture. <b>Taux tva :</b> s’il n’est pas nul, la TVA est calculée à la saisie (et reste modifiable) ; sans compte MNT TVA, la TVA s’ajoute au compte de l’assiette.
                    <b>Taux TVA majoré :</b> TVA supplémentaire calculée sur l’assiette et passée au compte TVA majoré ; l’assiette va alors au compte « assiette majorée » s’il est renseigné.</p>
            </div>
        </form>
        @if($m->exists)<form x-ref="suppr" method="POST" action="{{ route('compta.modeles-factures.destroy', $m) }}" class="hidden">@csrf @method('DELETE')</form>@endif
        @include('compta._recherche-compte')
    </div>
</x-layouts.app>
