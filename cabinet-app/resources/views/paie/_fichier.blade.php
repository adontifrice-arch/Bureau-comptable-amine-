{{-- Fenêtres du menu Fichier du module Paie. --}}
@php $chef = auth()->user()->estChef(); @endphp

{{-- Ouvrir dossier --}}
<x-modal name="paie-ouvrir" title="Ouvrir un dossier">
    <div x-data="{ q: '' }" class="space-y-3">
        <input type="search" x-model="q" placeholder="Rechercher un dossier…" class="input" autofocus>
        <div class="max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
            @foreach($clients as $c)
                <a href="{{ route('paie.index', ['client' => $c->id, 'tab' => $tab, 'mois' => $mois]) }}" x-show="@js(mb_strtolower($c->nom)).includes(q.toLowerCase())"
                   class="flex items-center justify-between border-b border-slate-100 px-4 py-2 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800 {{ $c->id === $client->id ? 'bg-brand-50 font-semibold dark:bg-slate-800' : '' }}">
                    {{ $c->nom }} @if($c->id === $client->id)<span class="badge-green">ouvert</span>@endif
                </a>
            @endforeach
        </div>
        <div class="flex justify-end"><a href="{{ route('clients.create') }}" class="btn-secondary btn-sm"><x-icon name="plus" class="h-3.5 w-3.5"/> Nouveau dossier</a></div>
    </div>
</x-modal>

{{-- Renseignements employeur --}}
@include('paie._employeur')

{{-- Importation du pointage --}}
<x-modal name="paie-import-pointage" title="Importer le pointage mensuel de la paie">
    <form method="POST" action="{{ route('paie.import.pointage') }}" enctype="multipart/form-data" class="space-y-4">@csrf <input type="hidden" name="type" value="mensuel">
        <p class="text-sm text-slate-600 dark:text-slate-300">Une ligne par employé : matricule, CIN ou nom pour le reconnaître, puis <b>jours travaillés</b>, <b>heures supplémentaires</b> et <b>primes</b>.
            Une colonne vide ne change rien ; des jours laissés vides comptent pour le mois complet ({{ $P['jours_mois'] }} jours).</p>
        <div class="flex flex-wrap items-end gap-3">
            <div><label class="label">Mois de paie</label><input type="month" name="mois" value="{{ $mois }}" class="input" required></div>
            <a href="{{ route('paie.import.modele-paie', ['mois' => $mois]) }}" class="btn-secondary btn-sm"><x-icon name="download" class="h-3.5 w-3.5"/> Modèle de paie de {{ mois_fr($mois) }}</a>
        </div>
        <input type="file" name="fichier" accept=".xlsx,.xls,.ods,.csv" class="input" required>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Importer</button></div>
    </form>
</x-modal>
<x-modal name="paie-import-historique" title="Importer l’historique du pointage de la paie">
    <form method="POST" action="{{ route('paie.import.pointage') }}" enctype="multipart/form-data" class="space-y-4">@csrf <input type="hidden" name="type" value="historique">
        <p class="text-sm text-slate-600 dark:text-slate-300">Même fichier que le pointage mensuel, avec une colonne <b>Mois</b> (2026-03, 03/2026…) : plusieurs mois en une fois, par exemple pour reprendre les paies de l’année
            déjà faites dans un autre logiciel (cumuls pour la régularisation de l’IRPP et la déclaration employeur). Les paies déjà clôturées ne sont pas modifiées.</p>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="cloturer" value="1" checked class="mt-1">
            <span>Clôturer les mois importés et les marquer <b>comptabilisés</b> : ces paies ont déjà été passées en comptabilité, elles ne sont pas transmises au module Comptabilité.</span></label>
        <a href="{{ route('paie.import.modele-paie', ['mois' => $mois]) }}" class="btn-secondary btn-sm"><x-icon name="download" class="h-3.5 w-3.5"/> Modèle de paie</a>
        <input type="file" name="fichier" accept=".xlsx,.xls,.ods,.csv" class="input" required>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Importer l’historique</button></div>
    </form>
</x-modal>

{{-- Importation --}}
<x-modal name="paie-import" title="Importer la liste des employés">
    <form method="POST" action="{{ route('paie.import.employes') }}" enctype="multipart/form-data" class="space-y-4">@csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">Fichier Excel (.xlsx, .xls, .ods) ou CSV, une ligne par employé. Colonnes reconnues : matricule, nom et prénom, CIN, n° CNSS, poste, salaire de base, chef de famille (O/N), enfants, date d’embauche, mode de paiement, banque, RIB.
            Un employé déjà présent (même CIN, sinon même nom) est mis à jour.</p>
        <a href="{{ route('paie.import.modele') }}" class="btn-secondary btn-sm"><x-icon name="download" class="h-3.5 w-3.5"/> Modèle du personnel (Excel)</a>
        <input type="file" name="fichier" accept=".xlsx,.xls,.ods,.csv" class="input" required>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Importer</button></div>
    </form>
</x-modal>
@if($chef)
<x-modal name="paie-restaurer" title="Restaurer une sauvegarde">
    <form method="POST" action="{{ route('paie.restaurer') }}" enctype="multipart/form-data" class="space-y-4" onsubmit="return confirm('Restaurer cette sauvegarde dans le dossier {{ addslashes($client->nom) }} ?')">@csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">Fichier .json obtenu par <b>Fichier → Sauvegarde</b>. Rien n’est écrasé : les employés sont créés ou mis à jour, les paies d’un mois déjà présent sont ignorées,
            et les paies clôturées restaurées reviennent « transmises » pour être validées en comptabilité.</p>
        <input type="file" name="fichier" accept=".json,application/json" class="input" required>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Restaurer dans {{ $client->nom }}</button></div>
    </form>
</x-modal>
@endif

{{-- Exportation --}}
<x-modal name="paie-export" title="Exportation">
    <div class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
        @foreach(\App\Services\Exports\EtatsPaie::EXPORTS + \App\Services\Exports\EtatsPaie::EDITIONS as $k => $l)
            <div class="flex items-center justify-between gap-3 py-2">
                <span>{{ $l }}</span>
                <span class="flex gap-1">@foreach(['xlsx' => 'Excel', 'pdf' => 'PDF', 'docx' => 'Word'] as $f => $fl)<a href="{{ route('paie.edition', [$k, $f, 'mois' => $mois]) }}" @if($f === 'pdf') target="_blank" @endif class="btn-ghost btn-sm">{{ $fl }}</a>@endforeach</span>
            </div>
        @endforeach
        <div class="flex items-center justify-between gap-3 py-2"><span>Sauvegarde complète du dossier de paie</span><a href="{{ route('paie.sauvegarde') }}" class="btn-ghost btn-sm">JSON</a></div>
    </div>
    <p class="mt-3 text-xs text-slate-400">Période : {{ mois_fr($mois) }} (trimestre pour la CNSS, année pour la déclaration employeur et le récapitulatif annuel).</p>
</x-modal>

{{-- Sécurité --}}
@if($chef)
<x-modal name="paie-rouvrir" title="Rouvrir une paie clôturée">
    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">Une paie transmise peut être rouverte pour correction. Une paie déjà comptabilisée doit d’abord être annulée en comptabilité.</p>
    <div class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
        @forelse($historique as $p)
            <div class="flex items-center justify-between px-4 py-2 text-sm">
                <span class="capitalize">{{ mois_fr($p->mois) }}</span>
                @if($p->statut === 'val')<span class="badge-green">Comptabilisée</span>
                @else<form method="POST" action="{{ route('paie.rouvrir') }}" onsubmit="return confirm('Rouvrir la paie de {{ mois_fr($p->mois) }} ?')">@csrf<input type="hidden" name="mois" value="{{ $p->mois }}"><button class="btn-secondary btn-sm">Rouvrir</button></form>@endif
            </div>
        @empty <div class="px-4 py-6 text-center text-sm text-slate-400">Aucune paie clôturée.</div> @endforelse
    </div>
</x-modal>
@endif
<x-modal name="paie-journal" title="Journal des opérations de paie">
    <div class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto text-sm dark:divide-slate-800">
        @forelse($journal as $j)
            <div class="flex justify-between gap-4 py-2"><span>{{ $j->action }}</span><span class="whitespace-nowrap text-xs text-slate-400">{{ $j->user?->name ?? '—' }} · {{ $j->created_at->format('d/m/Y H:i') }}</span></div>
        @empty <div class="py-6 text-center text-slate-400">Aucune opération enregistrée.</div> @endforelse
    </div>
</x-modal>

{{-- Notifications --}}
@if($notifAuto ?? false)<div x-data x-init="setTimeout(() => $dispatch('open-modal', 'paie-notifications'), 300)"></div>@endif
<x-modal name="paie-notifications" title="Notifications" size="max-w-6xl">
    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
        @foreach($notifications as $cle => $n)
            <div>
                <h3 class="mb-1.5 text-sm font-semibold {{ count($n['lignes']) ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500' }}">{{ $n['titre'] }} <span class="font-mono">{{ '{'.count($n['lignes']).'}' }}</span></h3>
                <div class="max-h-40 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800"><tr><th class="w-8 px-2 py-1.5 text-left">N°</th>@foreach($n['colonnes'] as $c)<th class="px-2 py-1.5 text-left">{{ $c }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse($n['lignes'] as $i => $l)
                                <tr class="border-t border-slate-100 dark:border-slate-800"><td class="px-2 py-1.5">{{ $i + 1 }}</td>@foreach($l as $k => $v)<td class="px-2 py-1.5 {{ $k === 0 ? 'font-medium uppercase' : '' }}">{{ $v ?: '—' }}</td>@endforeach</tr>
                            @empty
                                <tr><td colspan="{{ count($n['colonnes']) + 1 }}" class="h-16 px-2 text-center text-slate-300 dark:text-slate-600">—</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>

    <h3 class="mb-1.5 mt-6 text-sm font-semibold {{ count($alertes) ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500' }}">Dossier à compléter <span class="font-mono">{{ '{'.count($alertes).'}' }}</span></h3>
    <div class="space-y-2 text-sm">
        @forelse($alertes as $a)
            <div class="flex items-start gap-3 rounded-lg px-3 py-2 {{ ['err' => 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300', 'warn' => 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300', 'info' => 'bg-slate-50 text-slate-700 dark:bg-slate-800 dark:text-slate-200'][$a['niveau']] }}">
                <x-icon :name="$a['niveau'] === 'info' ? 'clock' : 'alert'" class="mt-0.5 h-4 w-4 shrink-0"/>
                <span class="flex-1">{{ $a['texte'] }}</span>
                @if($a['lien'])<a href="{{ $a['lien'] }}" class="whitespace-nowrap text-xs font-medium underline">Ouvrir</a>
                @elseif($a['niveau'] !== 'info')<button type="button" @click="$dispatch('close-modal'); $nextTick(() => $dispatch('open-modal', 'paie-employeur'))" class="whitespace-nowrap text-xs font-medium underline">Compléter</button>@endif
            </div>
        @empty <div class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">Rien à signaler.</div> @endforelse
    </div>
    <p class="mt-4 text-xs text-slate-400">Contrats, date de naissance, profil, catégorie et échelon se renseignent dans la fiche de l’employé (Employé → Liste des employés).</p>
</x-modal>

{{-- Calculatrice : calculs libres, simulateur brut ↔ net et simulateur net → brut --}}
<x-modal name="paie-calculatrice" title="Calculatrice">
    <div @paie-net-brut.window="t = 'nb'; $nextTick(() => $refs.net?.focus())" x-data="{
            t: 'calc', ecran: '0', neuf: true,
            touche(k) {
                if (k === 'C') { this.ecran = '0'; this.neuf = true; return; }
                if (k === '←') { this.ecran = this.ecran.length > 1 ? this.ecran.slice(0, -1) : '0'; return; }
                if (k === '=') { try { const v = Function('return (' + this.ecran.replace(/×/g, '*').replace(/÷/g, '/').replace(/,/g, '.') + ')')(); this.ecran = String(Math.round(v * 1000) / 1000).replace('.', ','); } catch (e) { this.ecran = 'Erreur'; } this.neuf = true; return; }
                const op = '+-×÷%'.includes(k);
                if (this.neuf && !op) this.ecran = '';
                this.neuf = false;
                this.ecran = (this.ecran === 'Erreur' ? '' : this.ecran) + (k === '%' ? '/100' : k);
            },
            s: { montant: '', sens: 'brut', chef: false, enfants: 0 }, r: null, err: '',
            async simuler() {
                this.err = ''; if (!this.s.montant) { this.r = null; return; }
                const q = new URLSearchParams({ montant: this.s.montant, sens: this.s.sens, chef: this.s.chef ? 1 : 0, enfants: this.s.enfants || 0 });
                const rep = await fetch('{{ route('paie.simulation') }}?' + q, { headers: { Accept: 'application/json' } });
                if (rep.ok) this.r = await rep.json(); else { this.r = null; this.err = 'Montant invalide.'; }
            },
            n: { montant: '', chef: false, enfants: 0, parents: 0, profil: '' }, rn: null, errn: '',
            async netBrut() {
                this.errn = ''; if (!this.n.montant) { this.rn = null; return; }
                const q = new URLSearchParams({ montant: this.n.montant, sens: 'net', chef: this.n.chef ? 1 : 0, enfants: this.n.enfants || 0, parents: this.n.parents || 0, profil: this.n.profil });
                const rep = await fetch('{{ route('paie.simulation') }}?' + q, { headers: { Accept: 'application/json' } });
                if (rep.ok) this.rn = await rep.json(); else { this.rn = null; this.errn = 'Montant invalide.'; }
            },
            f(v) { return Number(v).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
        }" @keydown.window="if (open && t === 'calc' && document.activeElement.tagName !== 'INPUT') { const m = { '*': '×', '/': '÷', 'Enter': '=', 'Backspace': '←', 'Escape': 'C', '.': ',' }; const k = m[$event.key] ?? $event.key; if ('0123456789,+-×÷%=←C()'.includes(k) && k.length === 1) { $event.preventDefault(); touche(k); } }">
        <div class="mb-4 flex gap-1 border-b border-slate-200 dark:border-slate-800">
            <button type="button" @click="t = 'calc'" class="tab" :class="t === 'calc' && 'tab-active'">Calculatrice</button>
            <button type="button" @click="t = 'sim'" class="tab" :class="t === 'sim' && 'tab-active'">Simulateur brut ↔ net</button>
            <button type="button" @click="t = 'nb'; $nextTick(() => $refs.net?.focus())" class="tab" :class="t === 'nb' && 'tab-active'">Simulateur net → brut</button>
        </div>
        <div x-show="t === 'calc'" class="mx-auto max-w-xs">
            <div class="mb-3 truncate rounded-lg bg-slate-900 px-4 py-3 text-right font-mono text-2xl text-white" x-text="ecran"></div>
            <div class="grid grid-cols-4 gap-2">
                <template x-for="k in ['C','(',')','÷','7','8','9','×','4','5','6','-','1','2','3','+','%','0',',','=']">
                    <button type="button" @click="touche(k)" x-text="k" class="rounded-lg py-3 text-lg font-medium" :class="k === '=' ? 'bg-brand-600 text-white hover:bg-brand-700' : ('C()÷×-+%'.includes(k) ? 'bg-slate-200 hover:bg-slate-300 dark:bg-slate-700' : 'bg-slate-100 hover:bg-slate-200 dark:bg-slate-800')"></button>
                </template>
            </div>
        </div>
        <div x-show="t === 'sim'" x-cloak class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="sm:col-span-2"><label class="label">Montant mensuel</label><input type="number" step="0.001" min="0" x-model="s.montant" @input.debounce.400ms="simuler()" class="input"></div>
                <div><label class="label">Le montant est le</label><select x-model="s.sens" @change="simuler()" class="input"><option value="brut">Brut</option><option value="net">Net</option></select></div>
                <div><label class="label">Enfants</label><input type="number" min="0" max="20" x-model="s.enfants" @input.debounce.400ms="simuler()" class="input"></div>
                <label class="flex items-center gap-2 text-sm sm:col-span-4"><input type="checkbox" x-model="s.chef" @change="simuler()"> Chef de famille</label>
            </div>
            <p x-show="err" x-text="err" class="text-sm text-red-600"></p>
            <template x-if="r">
                <table class="w-full text-sm">
                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5 font-semibold">Salaire brut</td><td class="text-right font-mono font-semibold" x-text="f(r.brut)"></td></tr>
                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5">Retenue CNSS</td><td class="text-right font-mono" x-text="'− ' + f(r.cnssS)"></td></tr>
                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5">IRPP</td><td class="text-right font-mono" x-text="'− ' + f(r.ir)"></td></tr>
                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5">CSS</td><td class="text-right font-mono" x-text="'− ' + f(r.css)"></td></tr>
                    <tr class="border-b border-slate-200 bg-emerald-50 dark:border-slate-700 dark:bg-emerald-950/30"><td class="py-1.5 font-semibold">Net à payer</td><td class="text-right font-mono font-semibold" x-text="f(r.net)"></td></tr>
                    <tr><td class="py-1.5 text-slate-500">Coût employeur (CNSS patronale, TFP, FOPROLOS)</td><td class="text-right font-mono text-slate-500" x-text="f(r.cout)"></td></tr>
                </table>
            </template>
            <p class="text-xs text-slate-400">Calcul avec les taux et le barème des paramètres de paie, sans prime.</p>
        </div>
        {{-- Simulateur net → brut : brut à verser pour un net à payer voulu, selon la situation familiale et le profil. --}}
        <div x-show="t === 'nb'" x-cloak class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="sm:col-span-2"><label class="label">Net à payer souhaité</label><input x-ref="net" type="number" step="0.001" min="0" x-model="n.montant" @input.debounce.400ms="netBrut()" class="input text-right font-mono"></div>
                <div class="sm:col-span-2"><label class="label">Profil</label><select x-model="n.profil" @change="netBrut()" class="input"><option value="">Ordinaire (paramètres du dossier)</option>@foreach(\App\Models\PaieProfil::duDossier($client)->pluck('nom') as $pn)<option value="{{ $pn }}">{{ $pn }}</option>@endforeach</select></div>
                <div><label class="label">Enfants à charge</label><input type="number" min="0" max="20" x-model="n.enfants" @input.debounce.400ms="netBrut()" class="input"></div>
                <div><label class="label">Parents à charge</label><input type="number" min="0" max="4" x-model="n.parents" @input.debounce.400ms="netBrut()" class="input"></div>
                <label class="flex items-center gap-2 self-end pb-2 text-sm sm:col-span-2"><input type="checkbox" x-model="n.chef" @change="netBrut()"> Chef de famille</label>
            </div>
            <p x-show="errn" x-text="errn" class="text-sm text-red-600"></p>
            <template x-if="rn">
                <div>
                    <div class="mb-3 flex items-center justify-between rounded-lg bg-brand-50 px-4 py-3 dark:bg-brand-950/30">
                        <span class="font-semibold">Salaire brut à verser</span><span class="font-mono text-xl font-bold text-brand-700 dark:text-brand-300" x-text="f(rn.brut)"></span>
                    </div>
                    <table class="w-full text-sm">
                        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5">Salaire brut</td><td class="text-right font-mono" x-text="f(rn.brut)"></td></tr>
                        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5">Retenue CNSS <span class="text-xs text-slate-400" x-text="'(' + String(rn.taux_cnssS).replace('.', ',') + ' %)'"></span></td><td class="text-right font-mono" x-text="'− ' + f(rn.cnssS)"></td></tr>
                        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5 text-slate-500">Salaire imposable</td><td class="text-right font-mono text-slate-500" x-text="f(rn.brut - rn.cnssS)"></td></tr>
                        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5">IRPP</td><td class="text-right font-mono" x-text="'− ' + f(rn.ir)"></td></tr>
                        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5">CSS</td><td class="text-right font-mono" x-text="'− ' + f(rn.css)"></td></tr>
                        <tr class="border-b border-slate-200 bg-emerald-50 dark:border-slate-700 dark:bg-emerald-950/30"><td class="py-1.5 font-semibold">Net à payer obtenu</td><td class="text-right font-mono font-semibold" x-text="f(rn.net)"></td></tr>
                        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5 text-slate-500">CNSS patronale</td><td class="text-right font-mono text-slate-500" x-text="f(rn.cnssP)"></td></tr>
                        <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5 text-slate-500">TFP + FOPROLOS</td><td class="text-right font-mono text-slate-500" x-text="f(rn.tfp + rn.fop)"></td></tr>
                        <tr><td class="py-1.5 font-semibold">Coût employeur</td><td class="text-right font-mono font-semibold" x-text="f(rn.cout)"></td></tr>
                    </table>
                </div>
            </template>
            <p class="text-xs text-slate-400">Le brut est recherché pour que le net à payer atteigne le montant voulu (taux, barème IRPP et CSS des paramètres de paie, sans prime ni acompte). Le profil fixe la CNSS salariale (CIVP, gérant : aucune), l’IRPP et la CSS (CIVP : aucun) et les charges patronales.</p>
        </div>
    </div>
</x-modal>

{{-- Chemin du dossier --}}
<x-modal name="paie-chemin" title="Chemin du dossier">
    <dl class="grid grid-cols-3 gap-x-4 gap-y-2 text-sm">
        <dt class="text-slate-500">Cabinet</dt><dd class="col-span-2">{{ \App\Models\Setting::cabinet()['nom'] }}</dd>
        <dt class="text-slate-500">Dossier</dt><dd class="col-span-2 font-medium">{{ $client->nom }} <span class="text-xs text-slate-400">(n° {{ $client->id }})</span></dd>
        <dt class="text-slate-500">Adresse web</dt><dd class="col-span-2 break-all font-mono text-xs">{{ route('paie.index', ['client' => $client->id]) }}</dd>
        <dt class="text-slate-500">Contenu</dt><dd class="col-span-2">{{ $salaries->count() }} employé(s) · {{ $historique->count() }} paie(s) clôturée(s)</dd>
        @if($chef)
            <dt class="text-slate-500">Base de données</dt><dd class="col-span-2 break-all font-mono text-xs">{{ config('database.default') }}{{ config('database.default') === 'sqlite' ? ' · '.config('database.connections.sqlite.database') : ' · '.config('database.connections.'.config('database.default').'.database') }}</dd>
            <dt class="text-slate-500">Documents</dt><dd class="col-span-2 break-all font-mono text-xs">{{ storage_path('app/public') }}</dd>
        @endif
    </dl>
</x-modal>
