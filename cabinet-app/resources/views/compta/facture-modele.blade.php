{{--
    Saisie d'une facture d'achat ou de vente selon un modèle (paramètres facture achat / vente) :
    le modèle donne les lignes « objet », on saisit l'assiette et la TVA de chacune, le timbre, l'avance et le FODEC ;
    le TTC est calculé comme dans l'écriture. F4 enregistre et ouvre une nouvelle facture du même modèle, F1 recherche le compte du tiers.
--}}
@php
    $achat = $type === 'achat';
    $nom = $achat ? 'Facture achat' : 'Facture vente';
    $tab = $achat ? 'ach' : 'vte';
    $cfgModeles = $modeles->mapWithKeys(fn ($x) => [$x->id => ['libelle' => $x->libelle, 'journal' => $x->journal, 'timbre' => (float) $x->montant_timbre, 'p' => $x->parametres(),
        'lignes' => array_map(fn ($l) => ['objet' => $l['objet'], 'tva_taux' => $l['tva_taux'], 'taux_majore' => $l['taux_majore'], 'debit' => $l['debit']], $x->lignesModele())]]);
    // Facture modifiée : montants saisis rangés selon la ligne du modèle (TVA saisie = TVA hors majoration)
    $saisies = [];
    foreach ((array) $f?->lignes as $k => $l) $saisies[$l['ligne'] ?? $k] = ['ht' => (float) $l['ht'], 'tva' => round((float) $l['tva'] - (float) ($l['tva_majoree'] ?? 0), 3)];
    $cfg = [
        'modeles' => (object) $cfgModeles->all(), 'numeros' => (object) $numeros, 'modele' => $m?->id, 'edition' => (bool) $f,
        'compte' => old('compte_tiers', $f?->compte_tiers ?? ''), 'tiers' => old('tiers', $f ? ($achat ? $f->fournisseur : $f->tiers) : ''),
        'reference' => old('reference', $f?->reference ?? ''), 'date' => old('date', $f?->date?->format('Y-m-d') ?? ''),
        'saisies' => (object) (old('lignes') ?? $saisies),
        'timbre' => old('timbre', $f?->timbre), 'fodec' => old('fodec', $f?->fodec ?? 0), 'avance' => old('avance', $f?->avance ?? 0),
    ];
@endphp
<x-layouts.app :title="$nom">
    <script>
        window.PLAN = @js((object) $plan); window.URL_COMPTES = @js(route('compta.comptes.store'));
        window.factureModele = (cfg) => window.avecRecherche({
            modele: cfg.modele ? String(cfg.modele) : '', compte: cfg.compte || '', tiers: cfg.tiers || '', nomAuto: ! cfg.tiers,
            reference: cfg.reference, date: cfg.date, lignes: [], cur: 0,
            timbre: '', fodec: '', avance: '',
            init() {
                this.charger(cfg.saisies);
                const f = (v) => v === null || v === undefined || v === '' ? '' : window.ecrireMontant(v);
                if (cfg.timbre !== null && cfg.timbre !== undefined) this.timbre = f(cfg.timbre);
                this.fodec = f(cfg.fodec); this.avance = f(cfg.avance);
                this.$nextTick(() => this.$refs.compte?.focus());
            },
            get m() { return cfg.modeles[this.modele] || null; },
            get numero() { return cfg.numeros[this.modele] || ''; },
            /** Lignes du modèle choisi ; montants repris (modification) ou vides. */
            charger(saisies = {}) {
                const m = this.m;
                this.lignes = m ? m.lignes.map((l, i) => ({ ...l, ht: saisies[i] ? window.ecrireMontant(saisies[i].ht) : '', tva: saisies[i] ? window.ecrireMontant(saisies[i].tva) : '' })) : [];
                if (m && ! cfg.edition) this.timbre = window.ecrireMontant(m.timbre);
                this.cur = 0;
            },
            changerModele() { this.charger(); this.$nextTick(() => this.focusCell(0, 'ht')); },

            v: (x) => window.lireMontant(x),
            fmt: (x) => window.ecrireMontant(x),
            majoree(l) { return l.taux_majore > 0 ? Math.round(this.v(l.ht) * l.taux_majore * 10) / 1000 : 0; },
            calcTva(l) { if (+l.tva_taux > 0) l.tva = this.v(l.ht) ? this.fmt(this.v(l.ht) * l.tva_taux / 100) : ''; },
            sortie(l, k) { if (String(l[k]).trim() !== '') l[k] = this.fmt(this.v(l[k])); if (k === 'ht') this.calcTva(l); },
            sortieTotal(k) { if (String(this[k]).trim() !== '') this[k] = this.fmt(this.v(this[k])); },
            get totalHt() { return this.lignes.reduce((s, l) => s + this.v(l.ht), 0); },
            get totalTva() { return this.lignes.reduce((s, l) => s + this.v(l.tva) + this.majoree(l), 0); },
            /** TTC : solde des montants, dans le sens du paramétrage (même calcul que l'écriture). */
            get ttc() {
                const m = this.m; if (! m) return 0;
                let d = 0, c = 0; const ajout = (x, debit) => { if (debit) d += x; else c += x; };
                this.lignes.forEach(l => ajout(this.v(l.ht) + this.v(l.tva) + this.majoree(l), l.debit));
                ajout(this.v(this.timbre), m.p.timbre.debit); ajout(this.v(this.fodec), m.p.fodec.debit); ajout(this.v(this.avance), m.p.avance.debit);
                return Math.round((m.p.ttc.debit ? c - d : d - c) * 1000) / 1000;
            },

            focusCell(i, k) { this.cur = i; this.$nextTick(() => { const el = this.$root.querySelector(`[data-cell="${i}-${k}"]`); el?.focus(); el?.select?.(); }); },
            touche(ev, i, k) {
                if (ev.key === 'Enter') { ev.preventDefault(); if (k === 'ht') return this.focusCell(i, 'tva'); if (i < this.lignes.length - 1) return this.focusCell(i + 1, 'ht'); this.$refs.timbre?.focus(); this.$refs.timbre?.select(); }
                else if (ev.key === 'ArrowUp' && i > 0) { ev.preventDefault(); this.focusCell(i - 1, k); }
                else if (ev.key === 'ArrowDown' && i < this.lignes.length - 1) { ev.preventDefault(); this.focusCell(i + 1, k); }
            },
            suivant(ref) { this.$refs[ref]?.focus(); this.$refs[ref]?.select?.(); },

            get libelleCompte() { return this.compte ? (window.libelleCompte(this.compte) || '') : ''; },
            compteSaisi() { if (this.nomAuto || ! this.tiers) { this.tiers = this.libelleCompte; this.nomAuto = true; } },
            compteChoisi(_, n) { this.compte = n; this.compteSaisi(); this.$nextTick(() => this.suivant('reference')); },

            enregistrer() {
                if (! this.m) return alert('Choisissez le modèle de facture.');
                if (! this.date) { alert('Saisissez la date de la facture.'); return this.$refs.date.focus(); }
                if (! this.lignes.some(l => this.v(l.ht) || this.v(l.tva))) { alert('Saisissez au moins une assiette.'); return this.focusCell(0, 'ht'); }
                if (this.ttc <= 0) return alert('Le total TTC doit être positif : vérifiez les montants et le sens des comptes du modèle.');
                this.$nextTick(() => this.$refs.form.submit());
            },
            touches(ev) {
                if (this.rech.ouvert) return;
                if (ev.key === 'F4') { ev.preventDefault(); this.enregistrer(); }
                else if (ev.key === 'F1') { ev.preventDefault(); this.chercher('compte', this.compte); }
            },
        });
    </script>
    <style>
        .fm-grille { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .fm-grille th { background: linear-gradient(#f8fafc, #d9e1ea); border: 1px solid #cbd5e1; padding: .35rem .5rem; font-size: .8rem; font-weight: 600; color: #334155; }
        .fm-grille td { border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px dotted #e2e8f0; height: 1.9rem; padding: 0 .5rem; font-size: .85rem; }
        .fm-grille td input { width: 100%; height: 1.9rem; border: 0; background: transparent; padding: 0; font-size: .85rem; outline: none; text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .fm-grille td:has(input:focus) { background: #e0f2fe; box-shadow: inset 0 0 0 1px #0ea5e9; }
        .fm-grille tr.courante td { background: #dbeafe; } .fm-grille tr.courante td:first-child { font-weight: 700; }
        .dark .fm-grille th { background: #1e293b; border-color: #334155; color: #cbd5e1; }
        .dark .fm-grille td { border-color: #334155; } .dark .fm-grille tr.courante td { background: rgba(14, 165, 233, .12); }
        .fm-loupe { display: flex; width: 2rem; align-items: center; justify-content: center; border: 1px solid #cbd5e1; border-left: 0; border-radius: 0 .375rem .375rem 0; background: #f8fafc; color: #334155; }
        .fm-loupe:hover { background: #e0f2fe; } .dark .fm-loupe { background: #0f172a; border-color: #334155; color: #cbd5e1; }
        .fm-total { width: 9rem; text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>

    <div x-data="factureModele(@js($cfg))" @keydown.window="touches($event)" class="mx-auto max-w-5xl">
        <form x-ref="form" method="POST" action="{{ $f ? route('compta.factures-modele.update', [$type, $f->id]) : route('compta.factures-modele.store', $type) }}" class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900" @submit.prevent="enregistrer()">@csrf @if($f) @method('PUT') @endif
            {{-- Bandeau --}}
            <div class="flex flex-wrap items-center gap-3 bg-sky-600 px-4 py-2.5 text-white">
                <a href="{{ route('compta.index', ['tab' => $tab]) }}" class="rounded p-1 hover:bg-white/15" title="Retour à la liste"><x-icon name="arrow-left" class="h-5 w-5"/></a>
                <h1 class="text-lg font-bold">{{ $nom }}</h1>
                <label for="fm-mod" class="ml-6 text-sm font-semibold">{{ $achat ? 'Modèle facture' : 'Modèle de saisie' }}</label>
                <select id="fm-mod" x-model="modele" @change="changerModele()" class="rounded border-0 py-1 pl-2 pr-8 text-sm text-slate-900" @disabled($f)>
                    <option value="">—</option>
                    @foreach($modeles as $x)<option value="{{ $x->id }}">{{ $x->libelle }}</option>@endforeach
                </select>
                <input type="hidden" name="modele_id" :value="modele">
                <div class="ml-auto flex items-center gap-1">
                    <a href="{{ route('compta.modeles-factures', ['type' => $type] + ($m ? ['modele' => $m->id] : [])) }}" class="flex items-center gap-1.5 rounded px-3 py-1.5 text-sm hover:bg-white/15" title="Paramètres facture {{ $achat ? 'achat' : 'vente' }}"><x-icon name="settings" class="h-4 w-4"/> Paramètres</a>
                    <button type="button" class="flex items-center gap-1.5 rounded bg-white/15 px-3 py-1.5 text-sm font-semibold hover:bg-white/25" @click="enregistrer()"><x-icon name="check" class="h-4 w-4"/> Enregistrer (F4)</button>
                </div>
            </div>

            @if(! $modeles->count())
                <div class="p-8 text-center text-sm text-slate-500">
                    <p class="mb-3">Aucun modèle de facture {{ $achat ? 'd’achat' : 'de vente' }} pour {{ $client->nom }}.</p>
                    <a href="{{ route('compta.modeles-factures', ['type' => $type, 'nouveau' => 1]) }}" class="btn-primary">Créer le paramétrage facture {{ $achat ? 'achat' : 'vente' }}</a>
                </div>
            @else
            {{-- En-tête --}}
            <div class="grid gap-x-6 gap-y-2.5 p-4 text-sm md:grid-cols-[minmax(0,1fr)_auto]">
                <div class="space-y-2.5">
                    <div class="flex items-center gap-3"><span class="w-40 font-medium">Numero Pièce</span><span class="input !w-36 !bg-sky-100 !py-1 font-mono text-base text-slate-800" x-text="numero" title="Numéro attribué dans le journal à la validation"></span>
                        <span class="text-xs text-slate-400" x-text="m ? 'Journal ' + m.journal : ''"></span></div>
                    <div class="flex items-start gap-3"><label for="fm-cpt" class="w-40 pt-1.5 font-medium">Compte {{ $achat ? 'fournisseur' : 'client' }}</label>
                        <div class="flex"><input id="fm-cpt" x-ref="compte" name="compte_tiers" x-model="compte" @change="compteSaisi()" @keydown.enter.prevent="compteSaisi(); suivant('tiers')" class="input !w-32 !rounded-r-none !py-1 font-mono" inputmode="numeric" autocomplete="off" placeholder="{{ $achat ? '401…' : '411…' }}"><button type="button" class="fm-loupe" @click="chercher('compte', compte)" title="(F1) Rechercher un compte"><x-icon name="search" class="h-4 w-4"/></button></div>
                        <input x-ref="tiers" name="tiers" x-model="tiers" @input="nomAuto = false" @keydown.enter.prevent="suivant('reference')" maxlength="255" class="input min-w-0 flex-1 !py-1" placeholder="Nom du {{ $achat ? 'fournisseur' : 'client' }}" aria-label="Nom du {{ $achat ? 'fournisseur' : 'client' }}"></div>
                    <div class="flex items-center gap-3"><label for="fm-ref" class="w-40 font-medium">N° facture originale</label><input id="fm-ref" x-ref="reference" name="reference" x-model="reference" maxlength="100" @keydown.enter.prevent="suivant('date')" class="input !w-48 !py-1"></div>
                    <div class="flex items-center gap-3"><label for="fm-date" class="w-40 font-medium">Date facture</label><input id="fm-date" x-ref="date" type="date" name="date" x-model="date" @keydown.enter.prevent="focusCell(0, 'ht')" class="input !w-48 !py-1" required></div>
                </div>
                <div class="flex items-start gap-2 text-slate-400"><span>Date écriture</span><span class="rounded border border-slate-200 px-3 py-1 dark:border-slate-700">{{ ($f?->created_at ?? now())->format('d/m/Y') }}</span></div>
            </div>

            {{-- Lignes du modèle et totaux --}}
            <div class="flex flex-col gap-3 px-3 pb-3 md:flex-row">
                <div class="min-w-0 flex-1 overflow-x-auto">
                    <table class="fm-grille">
                        <colgroup><col><col style="width: 9rem"><col style="width: 9rem"></colgroup>
                        <thead><tr><th>Objet</th><th>Assiette</th><th>MNT TVA</th></tr></thead>
                        <tbody>
                            <template x-for="(l, i) in lignes" :key="i">
                                <tr :class="cur === i && 'courante'" @focusin="cur = i">
                                    <td class="truncate" x-text="l.objet" :title="l.tva_taux > 0 ? 'TVA ' + l.tva_taux + ' %' + (l.taux_majore > 0 ? ' + majoration ' + l.taux_majore + ' %' : '') : ''"></td>
                                    <td><input :data-cell="`${i}-ht`" x-model="l.ht" @blur="sortie(l, 'ht')" @input="calcTva(l)" @keydown="touche($event, i, 'ht')" inputmode="decimal" placeholder="0,000" aria-label="Assiette">
                                        <input type="hidden" :name="`lignes[${i}][ht]`" :value="v(l.ht)"></td>
                                    <td><input :data-cell="`${i}-tva`" x-model="l.tva" @blur="sortie(l, 'tva')" @keydown="touche($event, i, 'tva')" inputmode="decimal" placeholder="0,000" aria-label="Montant TVA">
                                        <input type="hidden" :name="`lignes[${i}][tva]`" :value="v(l.tva)"></td>
                                </tr>
                            </template>
                            <template x-for="k in Math.max(0, 12 - lignes.length)" :key="'v' + k"><tr><td></td><td></td><td></td></tr></template>
                        </tbody>
                    </table>
                </div>
                <div class="w-full space-y-2.5 rounded-lg border border-slate-300 p-4 text-sm md:w-72 dark:border-slate-700">
                    <div class="flex items-center justify-between pt-2"><span class="text-base font-bold">Total HT</span><span class="input fm-total !py-1" x-text="fmt(totalHt)"></span></div>
                    <div class="flex items-center justify-between"><label for="fm-tim">Timbre</label><input id="fm-tim" x-ref="timbre" x-model="timbre" @blur="sortieTotal('timbre')" @keydown.enter.prevent="suivant('avance')" class="input fm-total !py-1" inputmode="decimal"><input type="hidden" name="timbre" :value="v(timbre)"></div>
                    <div class="flex items-center justify-between"><span>Total TVA</span><span class="input fm-total !py-1" x-text="fmt(totalTva)"></span></div>
                    <div class="flex items-center justify-between"><label for="fm-av">Avance/Imp</label><input id="fm-av" x-ref="avance" x-model="avance" @blur="sortieTotal('avance')" @keydown.enter.prevent="suivant('fodec')" class="input fm-total !py-1" inputmode="decimal" placeholder="0,000"><input type="hidden" name="avance" :value="v(avance)"></div>
                    <div class="flex items-center justify-between"><label for="fm-fo">Fodec</label><input id="fm-fo" x-ref="fodec" x-model="fodec" @blur="sortieTotal('fodec')" @keydown.enter.prevent="enregistrer()" class="input fm-total !py-1" inputmode="decimal" placeholder="0,000"><input type="hidden" name="fodec" :value="v(fodec)"></div>
                    <div class="flex items-center justify-between pt-1"><span class="text-base font-bold text-blue-700 dark:text-sky-400">Total TTC</span><span class="input fm-total !py-1 font-semibold" :class="ttc < 0 && '!text-rose-600'" x-text="fmt(ttc)"></span></div>
                </div>
            </div>
            @endif

            @if($errors->any())<div class="mx-3 mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
        </form>
        <p class="mt-2 text-xs text-slate-400">Entrée passe à la cellule suivante, ↑ ↓ changent de ligne. La TVA est calculée sur l’assiette quand le modèle donne un taux, et reste modifiable. F1 recherche le compte du tiers, F4 enregistre la facture (en attente de validation) et ouvre une nouvelle facture du même modèle.</p>
        @include('compta._recherche-compte')
    </div>
</x-layouts.app>
