<x-layouts.app title="Rédiger un acte">
    <script>
        /** Ordre du jour des PV : points cochés numérotés dans l'ordre de la liste, points du cabinet conservés côté serveur. */
        function redactionActe(init) {
            return {
                ...init, off: {}, nouveau: '', erreur: '', envoi: false,
                numero(item) {
                    if (this.off[item.texte]) return '';
                    return (this.odd[this.t] || []).filter(x => !this.off[x.texte]).findIndex(x => x.texte === item.texte) + 1;
                },
                async appel(methode, corps) {
                    this.envoi = true; this.erreur = '';
                    try {
                        const r = await fetch(@js(route('actes.odd.store')), { method: methode, body: JSON.stringify(corps), headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                        if (!r.ok) { const e = await r.json().catch(() => ({})); this.erreur = e.message || 'Enregistrement impossible.'; return false; }
                        this.odd = await r.json();
                        return true;
                    } catch (e) { this.erreur = 'Connexion impossible : réessayez.'; return false; }
                    finally { this.envoi = false; }
                },
                async ajouter() {
                    const point = this.nouveau.trim().replace(/\s+/g, ' ');
                    if (!point) return;
                    delete this.off[point];
                    if (await this.appel('POST', { type: this.t, point })) this.nouveau = '';
                },
                async retirer(item) {
                    if (confirm('Retirer « ' + item.texte + ' » des prochains PV ?')) await this.appel('DELETE', { type: item.type, point: item.texte });
                },
            };
        }
    </script>
    <x-page-header title="Rédiger statuts / PV" :subtitle="$client->nom" :back="route('clients.show', [$client, 'tab' => 'actes'])">
        <a href="{{ route('modeles-actes.index') }}" class="btn-secondary"><x-icon name="upload" class="h-4 w-4"/> Modèles du cabinet</a>
    </x-page-header>
    <form method="POST" action="{{ route('actes.store', $client) }}" x-data="redactionActe({ t: @js(old('type', 'sarl')), m: @js((string) old('modele_id', '')), odd: @js($odd), modeles: @js($modeles) })" x-init="$watch('t', () => m = '')" class="card">@csrf
        <div class="card-b grid gap-4 md:grid-cols-2">
            <div><label class="label">Type de document</label><select name="type" class="input" x-model="t">@foreach($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
            <x-field label="Date du document" name="date" type="date" :value="now()->format('Y-m-d')"/>
            <div class="md:col-span-2">
                <label class="label" for="f-modele">Modèle</label>
                <select id="f-modele" name="modele_id" class="input" x-model="m">
                    <option value="">Texte intégré à l’application</option>
                    <template x-for="x in modeles.filter(x => x.type === t)" :key="x.id"><option :value="String(x.id)" x-text="x.nom + ' (' + x.format.toUpperCase() + ')'"></option></template>
                </select>
                @error('modele_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-slate-400" x-show="!modeles.some(x => x.type === t)">Aucun modèle importé pour ce type : <a href="{{ route('modeles-actes.index') }}" class="underline">importer un modèle .docx ou .txt</a>.</p>
            </div>
            <template x-if="t === 'sarl' || t === 'suarl'"><div class="grid gap-4 md:col-span-2 md:grid-cols-3">
                <div class="md:col-span-2"><label class="label">Objet social</label><textarea name="objet" rows="3" class="input" placeholder="Une activité par ligne, ex. : La programmation informatique"></textarea><p class="mt-1 text-xs text-slate-400">Statuts SARL : chaque ligne devient un point de l’article 2, suivi des clauses usuelles (participations, opérations connexes).</p></div>
                <div><label class="label">Durée (années)</label><input type="number" name="duree" value="99" class="input"></div>
            </div></template>
            <template x-if="['ago','age','mixte','cess'].includes(t)"><div class="md:col-span-2">
                <div class="flex items-baseline justify-between gap-3">
                    <label class="label">Ordre du jour</label>
                    <span class="text-xs text-slate-400"><span x-text="(odd[t] || []).filter(x => !off[x.texte]).length"></span> point(s) retenu(s), dans l’ordre de la liste</span>
                </div>
                <ol class="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                    <template x-for="item in (odd[t] || [])" :key="item.texte">
                        <li class="flex items-center gap-3 px-4 py-2 text-sm">
                            <span class="w-5 shrink-0 text-right text-xs font-semibold text-slate-400" x-text="numero(item)"></span>
                            <label class="flex flex-1 items-start gap-2">
                                <input type="checkbox" name="ordre[]" :value="item.texte" :checked="!off[item.texte]" @change="off[item.texte] = !$event.target.checked" class="mt-0.5 rounded border-slate-300 text-brand-600">
                                <span x-text="item.texte" :class="off[item.texte] && 'text-slate-400 line-through'"></span>
                            </label>
                            <template x-if="item.type">
                                <span class="flex items-center gap-1">
                                    <span class="badge-blue">Cabinet</span>
                                    <button type="button" class="btn-icon" title="Retirer ce point des prochains PV" @click="retirer(item)"><x-icon name="x" class="h-3.5 w-3.5"/></button>
                                </span>
                            </template>
                        </li>
                    </template>
                </ol>
                <div class="mt-2 flex gap-2">
                    <input class="input" x-model="nouveau" maxlength="500" placeholder="Ajouter un point, conservé pour les prochains PV de ce type" @keydown.enter.prevent="ajouter()">
                    <button type="button" class="btn-secondary whitespace-nowrap" @click="ajouter()" :disabled="!nouveau.trim() || envoi"><x-icon name="plus" class="h-4 w-4"/> Ajouter</button>
                </div>
                <p class="mt-1 text-xs text-rose-600" x-show="erreur" x-text="erreur"></p>
                <details class="mt-3"><summary class="cursor-pointer text-xs text-slate-500">Points ponctuels pour ce PV seulement</summary>
                    <textarea name="ordre_libre" rows="2" class="input mt-2" placeholder="Un point par ligne, ajouté à la fin, non conservé"></textarea>
                </details>
            </div></template>
            <template x-if="['ago','age','mixte'].includes(t)"><div class="grid gap-4 md:col-span-2 md:grid-cols-4">
                <div><label class="label" for="f-heure">Heure de la réunion</label><input id="f-heure" name="heure" class="input" placeholder="ex. 12 ou 9h30"></div>
                <template x-if="t !== 'age'"><div class="contents">
                    <div><label class="label" for="f-ex">Exercice</label><input id="f-ex" type="number" name="exercice" class="input" value="{{ now()->year - 1 }}"></div>
                    <div><label class="label" for="f-bil">Total du bilan (DT)</label><input id="f-bil" type="number" step="0.001" name="total_bilan" class="input" placeholder="repris de la comptabilité"></div>
                    <div><label class="label" for="f-res">Résultat net (DT)</label><input id="f-res" type="number" step="0.001" name="resultat" class="input" placeholder="perte : montant négatif"></div>
                    <div class="md:col-span-4"><label class="label" for="f-aff">Affectation du résultat</label><input id="f-aff" name="affectation" class="input" placeholder="en report à nouveau"></div>
                </div></template>
                <template x-if="t !== 'ago'"><div class="contents">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 md:col-span-4">Assemblée extraordinaire : cession de parts, augmentation du capital, forme juridique</p>
                    <div><label class="label">Cédant</label><select name="cedant" class="input"><option value="">—</option>@foreach($client->associes as $a)<option>{{ $a->nom }}</option>@endforeach @if($client->gerant_nom && ! $client->associes->contains('nom', $client->gerant_nom))<option>{{ $client->gerant_nom }}</option>@endif</select></div>
                    <div><label class="label">Nombre de parts cédées</label><input type="number" name="parts" class="input" placeholder="déduit des numéros si vide"></div>
                    <div><label class="label">Numéros des parts cédées</label><div class="flex gap-2"><input type="number" name="parts_de" class="input" placeholder="de"><input type="number" name="parts_a" class="input" placeholder="à"></div></div>
                    <div><label class="label">Prix de cession (DT)</label><input type="number" step="0.001" name="prix" class="input"></div>
                    <div><label class="label">Cessionnaire</label><input name="cessionnaire" class="input"></div>
                    <div><label class="label">CIN du cessionnaire</label><input name="cessionnaire_cin" class="input"></div>
                    <div class="md:col-span-2"><label class="label">Adresse du cessionnaire</label><input name="cessionnaire_adresse" class="input"></div>
                    <div><label class="label">Augmentation du capital en numéraire (DT)</label><input type="number" step="0.001" min="0" name="augmentation" class="input"></div>
                    <div><label class="label">Nouvelle forme juridique</label><input name="nouvelle_forme" class="input" placeholder="ex. SARL"></div>
                    <p class="text-xs text-slate-400 md:col-span-2">Les parts nouvelles sont réparties au prorata des parts détenues après la cession ; utilisées seulement si le point correspondant est coché.</p>
                </div></template>
            </div></template>
            <template x-if="t === 'cess'"><div class="grid gap-4 md:col-span-2 md:grid-cols-2">
                <div><label class="label">Date de cessation</label><input type="date" name="date_cessation" class="input" value="{{ now()->format('Y-m-d') }}"></div>
                <div><label class="label">Motif</label><input name="motif" class="input"></div>
            </div></template>
            <template x-if="t === 'cession'"><div class="grid gap-4 md:col-span-2 md:grid-cols-3">
                <div><label class="label">Cédant</label><select name="cedant" class="input">@foreach($client->associes as $a)<option>{{ $a->nom }}</option>@endforeach @if($client->gerant_nom)<option>{{ $client->gerant_nom }}</option>@endif</select></div>
                <div><label class="label">Nombre de parts cédées</label><input type="number" name="parts" class="input" placeholder="déduit des numéros si vide"></div>
                <div><label class="label">Numéros des parts cédées</label><div class="flex gap-2"><input type="number" name="parts_de" class="input" placeholder="de"><input type="number" name="parts_a" class="input" placeholder="à"></div></div>
                <div><label class="label">Prix de cession (TND)</label><input type="number" step="0.001" name="prix" class="input"></div>
                <div><label class="label">Cessionnaire</label><input name="cessionnaire" class="input"></div>
                <div><label class="label">CIN du cessionnaire</label><input name="cessionnaire_cin" class="input"></div>
                <div><label class="label">Adresse du cessionnaire</label><input name="cessionnaire_adresse" class="input"></div>
            </div></template>
        </div>
        <div class="flex items-center justify-between border-t border-slate-100 p-4 dark:border-slate-800">
            <p class="text-xs text-slate-500">Les identités, apports et parts sont repris de la fiche client et des associés.</p>
            <button class="btn-primary"><x-icon name="sparkles" class="h-4 w-4"/> Générer le brouillon</button>
        </div>
    </form>
</x-layouts.app>
