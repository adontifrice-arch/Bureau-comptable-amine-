{{--
    Ajout et modification d'une pièce comptable (Comptabilité → Journal & écritures).
    Grille Libellé | Compte | Débit | Crédit | Référence | Solde ; calcul direct dans les montants (450+632, 100/5, 201*32…) ;
    Flèches pour circuler dans la grille, F1 recherche d'un compte, F4 enregistrer et passer à la pièce suivante,
    F6 solder la pièce (le reste à équilibrer sur la ligne courante si elle est vide, sinon sur une nouvelle ligne).
--}}
@php
    $nouvelle = ! $e;
    $editable = $nouvelle ? $peutCreer : ($peutModifier && ! $e->trashed());
    $fr = fn ($v) => (float) $v ? number_format((float) $v, 3, ',', ' ') : '';
    $lignes = old('lignes', $e ? $e->lignes->map(fn ($l) => ['libelle' => $l->libelle ?? $e->libelle, 'compte' => $l->compte, 'debit' => $fr($l->debit), 'credit' => $fr($l->credit), 'reference' => $l->reference ?? ''])->values()->all() : []);
    $cfg = [
        'nouvelle' => $nouvelle, 'editable' => $editable, 'journal' => old('journal', $journal), 'journalImpose' => ! $nouvelle || (request()->filled('journal') && ! request()->filled('enregistree')) || old('journal'),
        'date' => old('date', $e?->date?->format('Y-m-d') ?? ''), 'reference' => old('reference', $e?->reference ?? ''), 'lignes' => array_values($lignes),
        'numeros' => $numeros, 'numero' => $e?->numero, 'comptesJ' => $comptesJ, 'modeles' => (object) $modeles, 'journaux' => $journaux,
    ];
@endphp
<x-layouts.app :title="$nouvelle ? 'Nouvelle pièce comptable' : 'Pièce '.$e->journal.' n° '.$e->numero">
    <script>
        window.PLAN = @js((object) $plan); window.URL_COMPTES = @js(route('compta.comptes.store'));

        /** Calcul saisi dans un montant : chiffres, virgule ou point, + - * / et parenthèses (même règle que App\Support\Formule). */
        window.formuleMontant = function (saisie) {
            const s = String(saisie ?? '').replace(/[\s  ]/g, '').replace(/,/g, '.');
            if (s === '' || ! /^[0-9.+\-*/()]+$/.test(s)) return null;
            let i = 0;
            const facteur = () => {
                const c = s[i];
                if (c === '-' || c === '+') { i++; const v = facteur(); return v === null ? null : (c === '-' ? -v : v); }
                if (c === '(') { i++; const v = somme(); if (v === null || s[i] !== ')') return null; i++; return v; }
                const m = /^(\d*\.?\d+|\d+\.)/.exec(s.slice(i)); if (! m) return null; i += m[0].length; return parseFloat(m[0]);
            };
            const produit = () => {
                let v = facteur();
                while (v !== null && (s[i] === '*' || s[i] === '/')) { const op = s[i++]; const d = facteur(); if (d === null || (op === '/' && d === 0)) return null; v = op === '*' ? v * d : v / d; }
                return v;
            };
            const somme = () => {
                let v = produit();
                while (v !== null && (s[i] === '+' || s[i] === '-')) { const op = s[i++]; const d = produit(); if (d === null) return null; v = op === '+' ? v + d : v - d; }
                return v;
            };
            const v = somme();
            return v === null || i !== s.length || ! isFinite(v) ? null : Math.round(v * 1000) / 1000;
        };

        window.pieceComptable = (cfg) => ({
            ...cfg,
            j: cfg.journal, date: cfg.date, ref: cfg.reference, lignes: [], cur: 0, champ: 'libelle', erreurs: {},
            souv: Object.assign({ journal: true, date: false, comptes: false }, JSON.parse(localStorage.getItem('piece.souvenir') || '{}')),
            rech: { ouvert: false, q: '' }, modele: '',
            CHAMPS: ['libelle', 'compte', 'debit', 'credit', 'reference'],

            init() {
                const dernier = JSON.parse(localStorage.getItem('piece.dernier') || 'null');
                if (this.nouvelle && dernier && ! cfg.lignes.length) {
                    if (this.souv.journal && ! this.journalImpose && this.journaux[dernier.journal]) this.j = dernier.journal;
                    if (this.souv.date && ! this.date) this.date = dernier.date || '';
                    if (this.souv.comptes && (dernier.comptes || []).length) this.lignes = dernier.comptes.map(c => this.vide({ compte: c }));
                }
                if (! this.lignes.length) this.lignes = cfg.lignes.length ? cfg.lignes.map(l => this.vide(l)) : [this.vide()];
                if (this.nouvelle && ! cfg.lignes.length) this.compteJournal();
                this.$watch('souv', v => localStorage.setItem('piece.souvenir', JSON.stringify(v)), { deep: true });
                this.$nextTick(() => this.focus(0, this.nouvelle && ! this.date ? null : 'libelle'));
            },
            vide(l = {}) { return { libelle: l.libelle ?? '', compte: l.compte ?? '', debit: l.debit ?? '', credit: l.credit ?? '', reference: l.reference ?? '' }; },
            compteJournal() { const c = this.comptesJ[this.j]; if (c && ! this.lignes[0].compte) this.lignes[0].compte = String(c); },
            get numero() { return this.nouvelle ? (this.numeros[this.j] || '') : this.cfgNumero; },
            cfgNumero: cfg.numero,

            // Montants
            val(x) { const v = window.formuleMontant(x); return v === null ? 0 : v; },
            fmt(v) { return v ? v.toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) : ''; },
            evaluer(i, k) {
                const l = this.lignes[i]; const brut = String(l[k] ?? '').trim();
                delete this.erreurs[i + k];
                if (brut === '') return true;
                const v = window.formuleMontant(brut);
                if (v === null || v < 0) { this.erreurs[i + k] = true; return false; }
                l[k] = this.fmt(v);
                if (v) l[k === 'debit' ? 'credit' : 'debit'] = '';
                return true;
            },
            get D() { return this.lignes.reduce((s, l) => s + this.val(l.debit), 0); },
            get C() { return this.lignes.reduce((s, l) => s + this.val(l.credit), 0); },
            get S() { return Math.round((this.D - this.C) * 1000) / 1000; },
            soldeA(i) { let s = 0; for (let k = 0; k <= i; k++) s += this.val(this.lignes[k].debit) - this.val(this.lignes[k].credit); s = Math.round(s * 1000) / 1000; return s ? this.fmt(Math.abs(s)) + (s > 0 ? ' D' : ' C') : ''; },
            get libelleCompte() { const c = this.lignes[this.cur]?.compte; return c ? (window.libelleCompte(c) || 'Compte absent du plan comptable') : ''; },

            // Navigation dans la grille
            focus(i, k) {
                if (k === null) { this.$refs.date?.focus(); return; }
                this.cur = i; this.champ = k;
                this.$nextTick(() => { const el = this.$root.querySelector(`[data-cell="${i}-${k}"]`); el?.focus(); el?.select?.(); });
            },
            suivant(i, k) {
                if ((k === 'debit' || k === 'credit') && ! this.evaluer(i, k)) return;
                let n = this.CHAMPS.indexOf(k) + 1;
                if (k === 'debit' && this.val(this.lignes[i].debit)) n++;   // débit saisi : pas de crédit sur la ligne
                if (n < this.CHAMPS.length) return this.focus(i, this.CHAMPS[n]);
                if (i === this.lignes.length - 1) {
                    if (! this.lignes[i].compte && ! this.val(this.lignes[i].debit) && ! this.val(this.lignes[i].credit)) return;
                    this.lignes.push(this.vide({ libelle: this.lignes[i].libelle, reference: this.lignes[i].reference }));
                }
                this.focus(i + 1, this.lignes[i + 1].libelle ? 'compte' : 'libelle');
            },
            /** Flèches : haut / bas changent de ligne dans la même colonne ; gauche / droite changent de cellule en bout de texte. */
            fleches(ev, i, k) {
                if (ev.altKey || ev.ctrlKey || ev.metaKey || ev.shiftKey) return;
                const el = ev.target, c = this.CHAMPS.indexOf(k), fin = (el.value ?? '').length;
                if (ev.key === 'ArrowUp') { ev.preventDefault(); if (i > 0) this.focus(i - 1, k); }
                else if (ev.key === 'ArrowDown') {
                    ev.preventDefault();
                    if (i < this.lignes.length - 1) return this.focus(i + 1, k);
                    const l = this.lignes[i];   // dernière ligne : une ligne entamée en ouvre une nouvelle
                    if (l.compte || this.val(l.debit) || this.val(l.credit)) { this.lignes.push(this.vide({ libelle: l.libelle, reference: l.reference })); this.focus(i + 1, k); }
                }
                else if (ev.key === 'ArrowLeft' && el.selectionStart === 0 && (el.selectionEnd === 0 || el.selectionEnd === fin)) {
                    ev.preventDefault();
                    if (c > 0) this.focus(i, this.CHAMPS[c - 1]); else if (i > 0) this.focus(i - 1, this.CHAMPS[this.CHAMPS.length - 1]);
                }
                else if (ev.key === 'ArrowRight' && el.selectionEnd === fin && (el.selectionStart === fin || el.selectionStart === 0)) {
                    ev.preventDefault();
                    if (c < this.CHAMPS.length - 1) this.focus(i, this.CHAMPS[c + 1]); else if (i < this.lignes.length - 1) this.focus(i + 1, this.CHAMPS[0]);
                }
            },
            ajouter() { this.lignes.splice(this.cur + 1, 0, this.vide({ libelle: this.lignes[this.cur]?.libelle })); this.focus(this.cur + 1, 'compte'); },
            retirer() { if (this.lignes.length > 1) { this.lignes.splice(this.cur, 1); this.erreurs = {}; } else this.lignes = [this.vide()]; this.focus(Math.min(this.cur, this.lignes.length - 1), 'libelle'); },
            deplacer(d) { const i = this.cur, k = i + d; if (k < 0 || k >= this.lignes.length) return; [this.lignes[i], this.lignes[k]] = [this.lignes[k], this.lignes[i]]; this.erreurs = {}; this.focus(k, this.champ); },
            premiere() { this.focus(0, this.champ); }, derniere() { this.focus(this.lignes.length - 1, this.champ); },
            /**
             * F6 : solde la pièce. Le reste à équilibrer va sur la ligne courante si elle n'a pas encore de montant,
             * sinon sur la ligne vide suivante (créée au besoin, avec le libellé et la référence de la ligne courante).
             */
            equilibrer() {
                const l0 = this.lignes[this.cur];
                const sansMontant = (l) => ! this.val(l.debit) && ! this.val(l.credit);
                let i = this.cur;
                if (! sansMontant(l0)) {
                    if (Math.abs(this.S) < 0.0005) return;   // déjà équilibrée
                    i = this.lignes.findIndex((l, k) => k > this.cur && sansMontant(l));
                    if (i < 0) { this.lignes.push(this.vide({ libelle: l0.libelle, reference: l0.reference })); i = this.lignes.length - 1; }
                }
                const l = this.lignes[i];
                const reste = Math.round((this.S - this.val(l.debit) + this.val(l.credit)) * 1000) / 1000;
                if (Math.abs(reste) < 0.0005) return;
                if (! l.libelle) l.libelle = l0.libelle;
                l.debit = reste < 0 ? this.fmt(-reste) : ''; l.credit = reste > 0 ? this.fmt(reste) : '';
                delete this.erreurs[i + 'debit']; delete this.erreurs[i + 'credit'];
                this.focus(i, l.compte ? (reste < 0 ? 'debit' : 'credit') : 'compte');
            },
            choisirModele() {
                const m = (this.modeles[this.j] || []).find(x => String(x.id) === String(this.modele));
                if (m) { this.lignes = m.lignes.map(l => this.vide({ libelle: l.libelle, compte: l.compte })); this.focus(0, 'debit'); }
                this.modele = '';
            },

            // F1 : recherche d'un compte
            get resultats() {
                const q = this.rech.q.trim().toLowerCase(); const plan = window.PLAN || {};
                return Object.keys(plan).filter(n => ! q || n.startsWith(q) || plan[n].toLowerCase().includes(q)).slice(0, 60).map(n => [n, plan[n]]);
            },
            recherche() { this.rech.q = this.lignes[this.cur]?.compte || ''; this.rech.ouvert = true; this.$nextTick(() => this.$refs.rech?.focus()); },
            choisirCompte(n) { this.lignes[this.cur].compte = n; this.rech.ouvert = false; this.focus(this.cur, 'debit'); },

            // Enregistrement (F4)
            get pret() { return this.lignes.filter(l => l.compte && (this.val(l.debit) || this.val(l.credit))).length >= 2 && Math.abs(this.S) < 0.0005; },
            enregistrer() {
                if (! this.editable) return;
                let ok = true;
                this.lignes.forEach((l, i) => { ok = this.evaluer(i, 'debit') && ok; ok = this.evaluer(i, 'credit') && ok; });
                if (! ok) return alert('Un montant ou un calcul est invalide (en rouge).');
                if (! this.date) { alert('Saisissez la date de la pièce.'); return this.$refs.date.focus(); }
                if (! this.pret) return alert(Math.abs(this.S) >= 0.0005 ? 'La pièce n’est pas équilibrée (solde ' + this.fmt(Math.abs(this.S)) + '). F6 solde la pièce.' : 'Saisissez au moins deux lignes (compte et montant).');
                localStorage.setItem('piece.dernier', JSON.stringify({ journal: this.j, date: this.date, comptes: this.lignes.filter(l => l.compte).map(l => l.compte) }));
                this.$nextTick(() => this.$refs.form.submit());
            },
            touches(ev) {
                if (this.rech.ouvert) { if (ev.key === 'Escape') this.rech.ouvert = false; return; }
                if (ev.key === 'F4') { ev.preventDefault(); this.enregistrer(); }
                else if (ev.key === 'F6') { ev.preventDefault(); if (this.editable) this.equilibrer(); }
                else if (ev.key === 'F1') { ev.preventDefault(); if (this.editable) this.recherche(); }
            },
        });
    </script>
    <style>
        .pc-grille { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .pc-grille th { background: linear-gradient(#f8fafc, #e2e8f0); border: 1px solid #cbd5e1; padding: .35rem .5rem; font-size: .75rem; font-weight: 600; text-transform: uppercase; color: #334155; }
        .pc-grille td { border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px dotted #e2e8f0; height: 2rem; padding: 0; }
        .pc-grille td input { width: 100%; height: 2rem; border: 0; background: transparent; padding: 0 .5rem; font-size: .875rem; outline: none; }
        .pc-grille td input:focus { background: #e0f2fe; box-shadow: inset 0 0 0 1px #0ea5e9; }
        .pc-grille tr.courante td { background: #f0f9ff; }
        .pc-grille td.err input { color: #e11d48; background: #fff1f2; }
        .pc-grille .num { text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .dark .pc-grille th { background: #1e293b; border-color: #334155; color: #cbd5e1; }
        .dark .pc-grille td { border-color: #334155; }
        .dark .pc-grille tr.courante td { background: rgba(14, 165, 233, .08); }
        .pc-outil { display: flex; height: 2.1rem; width: 2.1rem; align-items: center; justify-content: center; border-radius: .375rem; border: 1px solid #cbd5e1; background: #fff; color: #0369a1; }
        .pc-outil:hover:not(:disabled) { background: #e0f2fe; } .pc-outil:disabled { opacity: .4; }
        .dark .pc-outil { background: #0f172a; border-color: #334155; color: #7dd3fc; }
    </style>

    <div x-data="pieceComptable(@js($cfg))" @keydown.window="touches($event)" class="mx-auto max-w-6xl">
        <form x-ref="form" method="POST" action="{{ $nouvelle ? route('compta.piece.store') : route('compta.piece.update', $e) }}" class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">@csrf @unless($nouvelle) @method('PUT') @endunless
            {{-- Bandeau --}}
            <div class="flex items-center gap-3 bg-sky-600 px-4 py-2.5 text-white">
                <a href="{{ route('compta.index', ['tab' => 'jnl']) }}" class="rounded p-1 hover:bg-white/15" title="Retour au journal"><x-icon name="arrow-left" class="h-5 w-5"/></a>
                <h1 class="text-lg font-bold">Pièce comptable</h1>
                @if($e && $e->trashed())<span class="rounded bg-rose-600 px-2 py-0.5 text-xs">Supprimée</span>@endif
                @if($e && $e->source)<span class="rounded bg-amber-500/90 px-2 py-0.5 text-xs" title="Pièce générée depuis : {{ $e->origine() }}">{{ $e->origine() }}</span>@endif
                <div class="ml-auto flex items-center gap-1">
                    @if(! $nouvelle && $peutModifier && ! $e->trashed())
                        <button type="button" class="flex items-center gap-1.5 rounded px-3 py-1.5 text-sm hover:bg-white/15" @click="const m = prompt('Supprimer cette pièce ? Elle sera conservée dans « Pièces supprimées ».\n\nMotif (facultatif) :'); if (m !== null) { $refs.suppr.motif.value = m; $refs.suppr.submit() }"><x-icon name="trash" class="h-4 w-4"/> Supprimer</button>
                    @elseif($nouvelle && $editable)
                        <button type="button" class="flex items-center gap-1.5 rounded px-3 py-1.5 text-sm hover:bg-white/15" @click="if (confirm('Effacer la saisie en cours ?')) { lignes = [vide()]; ref = ''; erreurs = {}; compteJournal(); focus(0, 'libelle') }"><x-icon name="trash" class="h-4 w-4"/> Supprimer</button>
                    @endif
                    @if($editable)<button type="button" class="flex items-center gap-1.5 rounded bg-white/15 px-3 py-1.5 text-sm font-semibold hover:bg-white/25" @click="enregistrer()"><x-icon name="check" class="h-4 w-4"/> Enregistrer (F4)</button>@endif
                </div>
            </div>

            {{-- En-tête de la pièce --}}
            <div class="grid gap-x-6 gap-y-2 border-b border-slate-200 p-4 text-sm md:grid-cols-2 dark:border-slate-700">
                <div class="space-y-2">
                    <div class="flex items-center gap-3"><label for="pc-j" class="w-24 font-medium">Journal</label>
                        <select id="pc-j" name="journal" x-model="j" @change="compteJournal()" class="input !w-28 !py-1 text-center font-semibold" @disabled(! $editable)>
                            @foreach($journaux as $k => $l)<option value="{{ $k }}">{{ $k }}</option>@endforeach
                        </select>
                        <span class="font-medium uppercase text-slate-600 dark:text-slate-300" x-text="'Journal ' + (journaux[j] || '')"></span></div>
                    <div class="flex items-center gap-3"><label for="pc-ref" class="w-24 font-medium">Référence</label><input id="pc-ref" name="reference" x-model="ref" maxlength="60" class="input !w-56 !py-1" @disabled(! $editable)></div>
                    <div class="flex items-center gap-3"><label for="pc-date" class="w-24 font-medium">Date pièce</label><input id="pc-date" x-ref="date" type="date" name="date" x-model="date" class="input !w-44 !py-1" required @keydown.enter.prevent="focus(0, 'libelle')" @disabled(! $editable)></div>
                    <div class="flex items-center gap-3"><span class="w-24 font-medium">N° pièce</span><span class="rounded border border-slate-300 px-3 py-1 font-mono text-base font-semibold text-rose-600 dark:border-slate-600" x-text="numero"></span>
                        <span class="text-slate-500">Libellé compte</span></div>
                </div>
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-end gap-3">
                        @if($editable)<label for="pc-mod" class="whitespace-nowrap font-medium">Utiliser un modèle</label>
                            <select id="pc-mod" x-model="modele" @change="choisirModele()" class="input !w-56 !py-1"><option value="">—</option><template x-for="m in (modeles[j] || [])" :key="m.id"><option :value="m.id" x-text="m.libelle"></option></template></select>@endif
                        <span class="ml-4 whitespace-nowrap text-slate-400">Date écriture :</span><span class="whitespace-nowrap text-slate-500">{{ ($e?->created_at ?? now())->format('d/m/Y') }}</span>
                    </div>
                    <div class="flex flex-1 items-end justify-between gap-3">
                        <span class="truncate text-base font-medium text-rose-600" x-text="libelleCompte"></span>
                        <div class="flex shrink-0 gap-1">
                            @foreach(['premiere' => ['«', 'Première pièce du journal'], 'precedente' => ['‹', 'Pièce précédente'], 'suivante' => ['›', 'Pièce suivante'], 'derniere' => ['»', 'Dernière pièce du journal']] as $k => [$s, $t])
                                @if($nav[$k])<a href="{{ route('compta.piece', ['ecriture' => $nav[$k]]) }}" class="pc-outil text-lg font-bold" title="{{ $t }}">{{ $s }}</a>@else<span class="pc-outil text-lg font-bold opacity-40">{{ $s }}</span>@endif
                            @endforeach
                            @unless($nouvelle)<a href="{{ route('compta.piece', ['journal' => $e->journal]) }}" class="pc-outil" title="Nouvelle pièce"><x-icon name="plus" class="h-4 w-4"/></a>@endunless
                        </div>
                    </div>
                </div>
            </div>

            {{-- Lignes --}}
            <div class="flex gap-2 p-3">
                <div class="min-h-[22rem] flex-1 overflow-x-auto">
                    <table class="pc-grille">
                        <colgroup><col><col style="width: 9rem"><col style="width: 9rem"><col style="width: 9rem"><col style="width: 8rem"><col style="width: 9rem"></colgroup>
                        <thead><tr><th>Libellé</th><th>Compte</th><th>Débit</th><th>Crédit</th><th>Référence</th><th>Solde</th></tr></thead>
                        <tbody>
                            <template x-for="(l, i) in lignes" :key="i">
                                <tr :class="cur === i && 'courante'" @focusin="cur = i">
                                    <td><input :data-cell="`${i}-libelle`" :name="`lignes[${i}][libelle]`" x-model="l.libelle" maxlength="255" @focus="champ = 'libelle'" @keydown.enter.prevent="suivant(i, 'libelle')" @keydown="fleches($event, i, 'libelle')" @disabled(! $editable) aria-label="Libellé"></td>
                                    <td><input :data-cell="`${i}-compte`" :name="`lignes[${i}][compte]`" x-model="l.compte" list="pc-plan" data-compte autocomplete="off" class="font-mono" @focus="champ = 'compte'" @keydown.enter.prevent="suivant(i, 'compte')" @keydown="fleches($event, i, 'compte')" @disabled(! $editable) aria-label="Compte"></td>
                                    <td :class="erreurs[i + 'debit'] && 'err'"><input :data-cell="`${i}-debit`" :name="`lignes[${i}][debit]`" x-model="l.debit" class="num" inputmode="decimal" @focus="champ = 'debit'" @blur="evaluer(i, 'debit')" @keydown.enter.prevent="suivant(i, 'debit')" @keydown="fleches($event, i, 'debit')" title="Montant ou calcul : 450+632, 100/5, 201*32…" @disabled(! $editable) aria-label="Débit"></td>
                                    <td :class="erreurs[i + 'credit'] && 'err'"><input :data-cell="`${i}-credit`" :name="`lignes[${i}][credit]`" x-model="l.credit" class="num" inputmode="decimal" @focus="champ = 'credit'" @blur="evaluer(i, 'credit')" @keydown.enter.prevent="suivant(i, 'credit')" @keydown="fleches($event, i, 'credit')" title="Montant ou calcul : 450+632, 100/5, 201*32…" @disabled(! $editable) aria-label="Crédit"></td>
                                    <td><input :data-cell="`${i}-reference`" :name="`lignes[${i}][reference]`" x-model="l.reference" maxlength="60" @focus="champ = 'reference'" @keydown.enter.prevent="suivant(i, 'reference')" @keydown="fleches($event, i, 'reference')" @disabled(! $editable) aria-label="Référence"></td>
                                    <td class="num px-2 text-sm text-slate-500" x-text="soldeA(i)"></td>
                                </tr>
                            </template>
                            <template x-for="k in Math.max(0, 10 - lignes.length)" :key="'v' + k"><tr><td></td><td></td><td></td><td></td><td></td><td></td></tr></template>
                        </tbody>
                    </table>
                </div>
                @if($editable)
                <div class="flex flex-col gap-1.5">
                    <button type="button" class="pc-outil" @click="recherche()" title="(F1) Rechercher un compte"><x-icon name="search" class="h-4 w-4"/></button>
                    <button type="button" class="pc-outil" @click="ajouter()" title="Insérer une ligne"><x-icon name="plus" class="h-4 w-4"/></button>
                    <button type="button" class="pc-outil !text-rose-600" @click="retirer()" title="Supprimer la ligne"><x-icon name="trash" class="h-4 w-4"/></button>
                    <button type="button" class="pc-outil" @click="equilibrer()" title="(F6) Solder la pièce"><x-icon name="scale" class="h-4 w-4"/></button>
                    <div class="h-3"></div>
                    <button type="button" class="pc-outil font-bold" @click="premiere()" title="Première ligne">⇈</button>
                    <button type="button" class="pc-outil" @click="deplacer(-1)" title="Monter la ligne"><x-icon name="chevron-right" class="h-4 w-4 -rotate-90"/></button>
                    <button type="button" class="pc-outil" @click="deplacer(1)" title="Descendre la ligne"><x-icon name="chevron-right" class="h-4 w-4 rotate-90"/></button>
                    <button type="button" class="pc-outil font-bold" @click="derniere()" title="Dernière ligne">⇊</button>
                </div>
                @endif
            </div>

            @if($errors->any())<div class="mx-3 mb-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">@foreach($errors->all() as $m)<div>{{ $m }}</div>@endforeach</div>@endif

            {{-- Pied --}}
            <div class="flex flex-wrap items-end gap-6 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                @if($editable)
                <fieldset class="rounded-lg border border-slate-300 px-3 pb-2 dark:border-slate-600"><legend class="px-1 text-rose-600">Se souvenir de :</legend>
                    <label class="mr-4 inline-flex items-center gap-1.5"><input type="checkbox" x-model="souv.journal"> Journal</label>
                    <label class="mr-4 inline-flex items-center gap-1.5"><input type="checkbox" x-model="souv.date"> Date pièce</label>
                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" x-model="souv.comptes"> Comptes</label>
                </fieldset>
                <div class="flex gap-4 pb-1 text-sky-600"><button type="button" @click="recherche()" class="hover:underline">(F1) Recherche</button><button type="button" @click="equilibrer()" class="hover:underline">(F6) Solder la pièce</button></div>
                @endif
                <div class="ml-auto flex items-end gap-3">
                    <div><div class="text-xs font-semibold uppercase text-slate-500">Débit</div><div class="w-36 rounded border border-slate-300 px-2 py-1 text-right font-mono dark:border-slate-600" x-text="fmt(D) || '0,000'"></div></div>
                    <div><div class="text-xs font-semibold uppercase text-slate-500">Crédit</div><div class="w-36 rounded border border-slate-300 px-2 py-1 text-right font-mono dark:border-slate-600" x-text="fmt(C) || '0,000'"></div></div>
                    <div><div class="text-sm font-bold uppercase">Solde</div><div class="w-36 rounded border px-2 py-1 text-right font-mono font-semibold" :class="Math.abs(S) < 0.0005 ? 'border-sky-300 bg-sky-100 text-sky-900' : 'border-rose-300 bg-rose-50 text-rose-700'" x-text="fmt(Math.abs(S)) || '0,000'"></div></div>
                </div>
            </div>
        </form>

        @if(! $nouvelle && $peutModifier && ! $e->trashed())
            <form x-ref="suppr" method="POST" action="{{ route('compta.ecritures.annuler', $e) }}" class="hidden">@csrf<input type="hidden" name="motif"><input type="hidden" name="depuis_fiche" value="1"><input type="hidden" name="retour" value="{{ route('compta.index', ['tab' => 'jnl', 'journal' => $e->journal]) }}"></form>
        @endif
        <datalist id="pc-plan" data-plan>@foreach($plan as $n => $lib)<option value="{{ $n }}">{{ $lib }}</option>@endforeach</datalist>
        <p class="mt-2 text-xs text-slate-400">Entrée passe à la cellule suivante, les flèches déplacent dans la grille. F6 solde la pièce, F4 l’enregistre et passe à la pièce suivante. Débit et crédit acceptent un calcul (450+632, 100/5, 201*32, (1200-200)*1,19) : il est calculé à la sortie de la cellule.</p>

        {{-- (F1) Recherche d'un compte --}}
        <div x-show="rech.ouvert" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-4 pt-24" @click.self="rech.ouvert = false">
            <div class="w-full max-w-xl rounded-xl bg-white shadow-xl dark:bg-slate-900">
                <div class="border-b border-slate-200 p-3 dark:border-slate-700"><input x-ref="rech" x-model="rech.q" class="input" placeholder="Numéro ou libellé du compte…" @keydown.enter.prevent="resultats.length && choisirCompte(resultats[0][0])" @keydown.escape="rech.ouvert = false"></div>
                <ul class="max-h-96 overflow-y-auto py-1 text-sm">
                    <template x-for="[n, lib] in resultats" :key="n"><li><button type="button" class="flex w-full gap-3 px-4 py-1.5 text-left hover:bg-sky-50 dark:hover:bg-slate-800" @click="choisirCompte(n)"><span class="w-24 font-mono" x-text="n"></span><span x-text="lib"></span></button></li></template>
                    <li x-show="! resultats.length" class="px-4 py-3 text-slate-400">Aucun compte.</li>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
