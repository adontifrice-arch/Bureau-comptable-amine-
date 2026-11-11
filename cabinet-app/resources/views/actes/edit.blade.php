<x-layouts.app :title="$acte->titre">
    {{-- Page A4 : 210 × 297 mm, marges identiques au PDF (actes/pdf) : 20 mm, 22 mm en bas → zone de texte 170 × 255 mm. --}}
    <style>
        .a4-page { width: 210mm; max-width: 100%; min-height: 297mm; box-sizing: border-box; padding: 20mm 20mm 22mm; margin: 0 auto;
            background-color: #fff; color: #111; font-family: 'DejaVu Serif', Georgia, 'Times New Roman', serif; font-size: 11pt; line-height: 1.5; text-align: justify;
            background-image: repeating-linear-gradient(to bottom, transparent 0, transparent calc(255mm - 1px), #94a3b8 calc(255mm - 1px), #94a3b8 255mm);
            background-origin: content-box; background-clip: content-box; outline: none; }
        .a4-page:focus { box-shadow: 0 0 0 2px rgb(14 116 144 / .35), 0 25px 50px -12px rgb(0 0 0 / .25); }
        .a4-page p { margin: 0 0 6pt; }
        .a4-page h1, .a4-page h2, .a4-page h3, .a4-page h4 { font-weight: bold; margin: 12pt 0 6pt; line-height: 1.3; }
        .a4-page h1 { font-size: 16pt; } .a4-page h2 { font-size: 14pt; } .a4-page h3 { font-size: 12pt; } .a4-page h4 { font-size: 11pt; }
        .a4-page ul, .a4-page ol { margin: 0 0 6pt; padding-left: 18pt; } .a4-page ul { list-style: disc; } .a4-page ol { list-style: decimal; }
        .a4-page table { width: 100%; border-collapse: collapse; } .a4-page td, .a4-page th { vertical-align: top; padding: 3pt; }
        .outil.actif { background: rgb(14 116 144 / .12); color: rgb(14 116 144); }
        @media print {
            @page { size: A4 portrait; margin: 20mm 20mm 22mm; }
            .a4-page { width: auto; min-height: 0; padding: 0; box-shadow: none !important; background-image: none; }
        }
    </style>

    <form method="POST" action="{{ route('actes.update', $acte) }}" x-data="editeurActe()" @submit="enregistrer()" @keydown.ctrl.s.window.prevent="$el.requestSubmit()">@csrf @method('PUT')
        <div class="no-print">
            <x-page-header :title="$acte->titre" :subtitle="$acte->client->nom.' · brouillon à relire avant signature'" :back="route('clients.show', [$acte->client, 'tab' => 'actes'])">
                @if($acte->modele?->format === 'docx')
                    <a href="{{ route('actes.docx', $acte) }}" class="btn-secondary" title="Rempli depuis le modèle « {{ $acte->modele->nom }} » avec les données actuelles de la fiche ; les modifications faites ici n’y figurent pas."><x-icon name="download" class="h-4 w-4"/> Word (modèle)</a>
                @endif
                <button type="button" class="btn-secondary" @click="copier()"><x-icon name="copy" class="h-4 w-4"/> <span x-text="copie ? 'Copié' : 'Copier'"></span></button>
                <button type="button" onclick="window.print()" class="btn-secondary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</button>
                <a href="{{ route('actes.pdf', $acte) }}" target="_blank" class="btn-secondary" @click="if (modifie && !confirm('Le PDF reprend la dernière version enregistrée. Continuer sans enregistrer ?')) $event.preventDefault()"><x-icon name="download" class="h-4 w-4"/> PDF</a>
                <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
            </x-page-header>
            @error('contenu')<p class="mb-3 text-sm text-rose-600">{{ $message }}</p>@enderror

            <div class="sticky top-0 z-10 mb-4 flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-white/95 p-2 shadow-sm backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                <input name="titre" value="{{ old('titre', $acte->titre) }}" class="input mr-2 w-72" aria-label="Titre du document">
                <button type="button" class="btn-ghost btn-sm" title="Annuler (Ctrl+Z)" @mousedown.prevent="cmd('undo')">↶</button>
                <button type="button" class="btn-ghost btn-sm" title="Rétablir (Ctrl+Y)" @mousedown.prevent="cmd('redo')">↷</button>
                <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                <select class="input !w-auto !py-1 text-sm" title="Style du paragraphe" @change="cmd('formatBlock', $event.target.value); $event.target.value = ''">
                    <option value="">Style…</option><option value="p">Paragraphe</option><option value="h2">Titre 1</option><option value="h3">Titre 2</option><option value="h4">Titre 3</option>
                </select>
                <select class="input !w-auto !py-1 text-sm" title="Taille du texte" @change="cmd('fontSize', $event.target.value); $event.target.value = ''">
                    <option value="">Taille…</option><option value="2">Petite (10 pt)</option><option value="3">Normale (12 pt)</option><option value="4">Grande (14 pt)</option><option value="5">Très grande (18 pt)</option>
                </select>
                <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                <button type="button" class="outil btn-ghost btn-sm font-bold" :class="etat.bold && 'actif'" title="Gras (Ctrl+B)" @mousedown.prevent="cmd('bold')">G</button>
                <button type="button" class="outil btn-ghost btn-sm italic" :class="etat.italic && 'actif'" title="Italique (Ctrl+I)" @mousedown.prevent="cmd('italic')">I</button>
                <button type="button" class="outil btn-ghost btn-sm underline" :class="etat.underline && 'actif'" title="Souligné (Ctrl+U)" @mousedown.prevent="cmd('underline')">S</button>
                <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                @foreach([['justifyLeft', 'Aligner à gauche', '⫷'], ['justifyCenter', 'Centrer', '☰'], ['justifyRight', 'Aligner à droite', '⫸'], ['justifyFull', 'Justifier', '≡']] as [$c, $t, $s])
                    <button type="button" class="outil btn-ghost btn-sm" :class="etat.{{ $c }} && 'actif'" title="{{ $t }}" aria-label="{{ $t }}" @mousedown.prevent="cmd('{{ $c }}')">{{ $s }}</button>
                @endforeach
                <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                <button type="button" class="outil btn-ghost btn-sm" :class="etat.insertUnorderedList && 'actif'" title="Liste à puces" @mousedown.prevent="cmd('insertUnorderedList')">• Liste</button>
                <button type="button" class="outil btn-ghost btn-sm" :class="etat.insertOrderedList && 'actif'" title="Liste numérotée" @mousedown.prevent="cmd('insertOrderedList')">1. Liste</button>
                <span class="ml-auto pr-1 text-xs text-slate-500" title="Estimation d’après l’aperçu ; le PDF peut différer légèrement">
                    ≈ <b x-text="pages"></b> page<span x-show="pages > 1">s</span> A4
                    <span x-show="modifie" class="ml-2 text-amber-600">· non enregistré</span>
                </span>
            </div>
        </div>

        <input type="hidden" name="contenu" x-ref="c">
        <div class="overflow-x-auto rounded-2xl bg-slate-100 py-8 print:bg-transparent print:p-0 dark:bg-slate-800/60">
            <div x-ref="ed" contenteditable="true" spellcheck="true" lang="fr" class="a4-page print-area shadow-xl" role="textbox" aria-multiline="true" aria-label="Contenu du document"
                 @input="modifie = true; mesurer()" @keyup="etatOutils()" @mouseup="etatOutils()">{!! \App\Services\Actes\HtmlActe::nettoyer($acte->contenu) !!}</div>
        </div>
        <div x-ref="mm" style="position:absolute;visibility:hidden;pointer-events:none;width:1px;height:255mm" aria-hidden="true"></div>
    </form>

    <script>
        function editeurActe() {
            return {
                modifie: false, pages: 1, copie: false, etat: {},
                init() {
                    document.execCommand('defaultParagraphSeparator', false, 'p');
                    this.$nextTick(() => this.mesurer());
                    window.addEventListener('resize', () => this.mesurer());
                    window.addEventListener('beforeunload', e => { if (this.modifie) { e.preventDefault(); e.returnValue = ''; } });
                },
                cmd(nom, valeur = null) {
                    if (!valeur && ['formatBlock', 'fontSize'].includes(nom)) return;
                    this.$refs.ed.focus();
                    document.execCommand(nom, false, nom === 'formatBlock' ? '<' + valeur + '>' : valeur);
                    this.modifie = true;
                    this.mesurer();
                    this.etatOutils();
                },
                etatOutils() {
                    const e = {};
                    ['bold', 'italic', 'underline', 'justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull', 'insertUnorderedList', 'insertOrderedList']
                        .forEach(c => { try { e[c] = document.queryCommandState(c); } catch (_) { e[c] = false; } });
                    this.etat = e;
                },
                /** Nombre de pages A4 : hauteur du texte / hauteur utile d'une page (255 mm). */
                mesurer() {
                    const ed = this.$refs.ed, s = getComputedStyle(ed);
                    const texte = ed.scrollHeight - parseFloat(s.paddingTop) - parseFloat(s.paddingBottom);
                    const page = this.$refs.mm.offsetHeight || 964;
                    this.pages = Math.max(1, Math.ceil((texte - 2) / page));
                },
                enregistrer() { this.$refs.c.value = this.$refs.ed.innerHTML; this.modifie = false; },
                async copier() {
                    const html = this.$refs.ed.innerHTML, texte = this.$refs.ed.innerText;
                    try {
                        await navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([texte], { type: 'text/plain' }) })]);
                    } catch (_) {
                        const sel = getSelection(), r = document.createRange();
                        r.selectNodeContents(this.$refs.ed); sel.removeAllRanges(); sel.addRange(r);
                        document.execCommand('copy'); sel.removeAllRanges();
                    }
                    this.copie = true; setTimeout(() => this.copie = false, 2000);
                },
            };
        }
    </script>
</x-layouts.app>
