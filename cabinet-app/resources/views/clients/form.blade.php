@php $edit = $client->exists; $d = fn($k) => $client->{$k} instanceof \DateTimeInterface ? $client->{$k}->format('Y-m-d') : $client->{$k}; @endphp
<x-layouts.app :title="$edit ? 'Modifier '.$client->nom : 'Nouveau client'">
    <x-page-header :title="$edit ? $client->nom : 'Nouveau client'" :subtitle="$edit ? 'Modifier la fiche du client' : 'Créer un dossier client'" :back="$edit ? route('clients.show', $client) : route('clients.index')"/>

    <form method="POST" action="{{ $edit ? route('clients.update', $client) : route('clients.store') }}" class="space-y-6" @unless($edit) enctype="multipart/form-data" @endunless
          x-data="{ tp: @js(old('type_personne', $client->type_personne ?? ($edit ? ($client->estPhysique() ? 'physique' : 'morale') : (in_array(request('type'), ['physique', 'morale'], true) ? request('type') : '')))),
                    associes:@js(collect(old('associes', $associes))->values()), gas: @js((string) old('gerant_associe', $client->gerant_associe === null ? '' : ($client->gerant_associe ? '1' : '0'))),
                    np: @js((string) old('nb_parts', $client->nb_parts)), gp: @js((string) old('gerant_parts', $client->gerant_parts)), vn: @js((string) old('valeur_nominale', $client->valeur_nominale)), cap: @js((string) old('capital', $client->capital > 0 ? $client->capital : '')),
                    addA() { this.associes.push({nom:'',cin:'',parts:'',apport:'numeraire'}) },
                    totalParts() { return (this.gas === '1' ? (+this.gp || 0) : 0) + this.associes.reduce((s, a) => s + (+a.parts || ((+a.parts_a && +a.parts_de) ? a.parts_a - a.parts_de + 1 : 0)), 0) },
                    nat: @js((string) old('gerant_nationalite', $client->gerant_nationalite)),
                    tun(n) { return /^\s*tunisi/i.test(n || '') }, etr(n) { return (n || '').trim() !== '' && ! this.tun(n) },
                    calcCap() { if (+this.np > 0 && +this.vn > 0) this.cap = Math.round(this.np * this.vn * 1000) / 1000 } }">
        @csrf @if($edit) @method('PUT') @endif

        {{-- Nouveau client : la nature est choisie d'abord, car la fiche n'a pas les mêmes rubriques. --}}
        <div x-show="!tp" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 pt-[12vh] backdrop-blur-sm">
            <div class="card w-full max-w-xl" role="dialog" aria-modal="true" aria-labelledby="tp-titre">
                <div class="card-h"><h3 id="tp-titre" class="card-t">Nouveau client : personne physique ou personne morale ?</h3></div>
                <div class="card-b grid gap-3 sm:grid-cols-2">
                    <button type="button" class="rounded-xl border border-slate-200 p-4 text-left transition hover:border-brand-500 hover:bg-brand-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="tp = 'physique'">
                        <div class="font-semibold text-slate-900 dark:text-white">Personne physique</div>
                        <p class="mt-1 text-xs text-slate-500">Commerçant, artisan, profession libérale, agriculteur… Catégories de revenus et régime d’imposition, sans capital ni gérant ni associés.</p>
                    </button>
                    <button type="button" class="rounded-xl border border-slate-200 p-4 text-left transition hover:border-brand-500 hover:bg-brand-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="tp = 'morale'">
                        <div class="font-semibold text-slate-900 dark:text-white">Personne morale</div>
                        <p class="mt-1 text-xs text-slate-500">SARL, SUARL, SA, association… Forme juridique, capital, gérant et associés.</p>
                    </button>
                    <a href="{{ route('clients.index') }}" class="btn-secondary sm:col-span-2 justify-center">Annuler</a>
                </div>
            </div>
        </div>
        <input type="hidden" name="type_personne" :value="tp">

        <div class="card">
            <div class="card-h"><h2 class="card-t">Identification</h2></div>
            <div class="card-b grid gap-4 md:grid-cols-3">
                <div class="md:col-span-3 flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-2.5 text-sm dark:bg-slate-800/60">
                    <span class="font-medium text-slate-700 dark:text-slate-200" x-text="tp === 'physique' ? 'Personne physique' : 'Personne morale'"></span>
                    <button type="button" class="btn-ghost btn-sm" @click="tp = ''">Changer</button>
                    @error('type_personne')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                </div>
                <div class="md:col-span-2"><label class="label" for="f-nom" x-text="tp === 'physique' ? 'Nom et prénom *' : 'Raison sociale *'">Nom / raison sociale *</label>
                    <input id="f-nom" name="nom" value="{{ old('nom', $client->nom) }}" class="input" required>
                    @error('nom')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div x-show="tp !== 'physique'"><x-field label="Forme juridique" name="forme" :value="$client->forme" placeholder="SARL, SUARL, SA, SNC…" list="formes"/></div>
                <datalist id="formes">@foreach(['SARL','SUARL','SA','SAS','SNC','SCS','Association','Groupement'] as $f)<option value="{{ $f }}">@endforeach</datalist>
                <div x-show="tp === 'physique'"><x-field label="Activité exercée" name="activite" :value="$client->activite" placeholder="Médecin, commerçant, artisan…"/></div>
                <x-field label="Dénomination commerciale" name="denomination_commerciale" :value="$client->denomination_commerciale"/>
                <x-select label="Résidence" name="residence" :value="$client->residence" placeholder="Non précisé" :options="['residente' => 'Résident(e)', 'non_residente' => 'Non résident(e)']"/>
                <x-select label="Régime d’exportation" name="regime_export" :value="$client->regime_export" placeholder="Non précisé" :options="['non' => 'Non exportatrice', 'totale' => 'Totalement exportatrice', 'partielle' => 'Partiellement exportatrice']"/>
                <x-field label="Matricule fiscal" name="matricule_fiscal" :value="$client->matricule_fiscal" placeholder="1234567/A/M/000" hint="7 chiffres + 1 lettre, puis code TVA, catégorie et n° d’établissement"/>
                <x-field label="Matricule CNSS" name="matricule_cnss" :value="$client->matricule_cnss"/>
                <x-field label="Matricule indépendant" name="matricule_independant" :value="$client->matricule_independant"/>
                <x-field label="Adresse du siège" name="adresse" :value="$client->adresse" span="md:col-span-2"/>
                <x-field label="Téléphone" name="telephone" :value="$client->telephone"/>
                <x-field label="E-mail" name="email" type="email" :value="$client->email"/>
            </div>
        </div>

        <div class="card">
            <div class="card-h"><h2 class="card-t" x-text="tp === 'physique' ? 'Mission' : 'Mission & capital'">Mission & capital</h2></div>
            <div class="card-b grid gap-4 md:grid-cols-3">
                <x-field label="Mission confiée" name="mission" :value="$client->mission" placeholder="Tenue + bilan, paie…"/>
                <x-field label="Honoraires mensuels (TND)" name="honoraires_mensuels" type="number" step="0.001" :value="$client->honoraires_mensuels > 0 ? $client->honoraires_mensuels : ''"/>
                <x-field label="Compte comptable client" name="compte_comptable" :value="$client->compte_comptable" placeholder="411001"/>
                <div x-show="tp !== 'physique'"><label class="label">Nombre de parts sociales</label><input name="nb_parts" type="number" class="input" x-model="np" @input="calcCap()"></div>
                <div x-show="tp !== 'physique'"><label class="label">Valeur nominale de la part (TND)</label><input name="valeur_nominale" type="number" step="0.001" class="input" x-model="vn" @input="calcCap()"></div>
                <div x-show="tp !== 'physique'"><label class="label">Capital (TND)</label><input name="capital" type="number" step="0.001" class="input" x-model="cap">
                    <p class="mt-1 text-xs text-slate-400">Capital = nombre de parts × valeur nominale</p>
                    @error('capital')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            </div>
        </div>

        <div class="card" x-show="tp === 'physique'">
            <div class="card-h"><h2 class="card-t">Revenus & imposition</h2></div>
            <div class="card-b grid gap-4 md:grid-cols-3">
                @php $cats = old('categories_revenus', $client->categories_revenus ?? []); @endphp
                <div class="md:col-span-2"><span class="label">Catégories de revenus *</span>
                    <div class="grid gap-2 pt-1 sm:grid-cols-2">
                        @foreach(\App\Models\Client::CATEGORIES_REVENUS as $k => $l)
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="categories_revenus[]" value="{{ $k }}" class="mt-0.5 rounded border-slate-300" @checked(in_array($k, (array) $cats, true))> {{ $l }}</label>
                        @endforeach
                    </div>
                    @error('categories_revenus')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <x-select label="Régime d’imposition" name="regime_imposition" :value="$client->regime_imposition" placeholder="Non précisé" :options="\App\Models\Client::REGIMES_IMPOSITION"/>
            </div>
        </div>

        <div class="card">
            <div class="card-h"><h2 class="card-t">Cadre légal de l’activité de l’entreprise</h2><span class="text-xs text-slate-400">{{ \App\Models\Client::CADRES_LEGAUX_MAX }} lignes au plus</span></div>
            @php $cadres = array_values((array) old('cadres_legaux', $client->cadres_legaux ?? [])); @endphp
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th class="min-w-[18rem]">Cadre légal</th><th>Organisme</th><th>N° de la déclaration d’investissement</th><th>Date de la déclaration d’investissement</th><th>Date d’entrée en activité</th></tr></thead>
                    <tbody>
                    @for($i = 0; $i < \App\Models\Client::CADRES_LEGAUX_MAX; $i++)
                        @php $l = $cadres[$i] ?? []; @endphp
                        <tr>
                            <td><select class="input" name="cadres_legaux[{{ $i }}][cadre]" aria-label="Cadre légal, ligne {{ $i + 1 }}"><option value=""></option>
                                @foreach(\App\Models\Client::CADRES_LEGAUX as $code => $lib)<option value="{{ $code }}" @selected((string) ($l['cadre'] ?? '') === (string) $code)>{{ $code }} - {{ $lib }}</option>@endforeach</select>
                                @error("cadres_legaux.$i.cadre")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</td>
                            <td><select class="input" name="cadres_legaux[{{ $i }}][organisme]" aria-label="Organisme, ligne {{ $i + 1 }}"><option value="">—</option>
                                @foreach(\App\Models\Client::ORGANISMES_INVESTISSEMENT as $k => $o)<option value="{{ $k }}" @selected(($l['organisme'] ?? null) === $k)>{{ $o }}</option>@endforeach</select></td>
                            <td><input class="input" name="cadres_legaux[{{ $i }}][numero]" value="{{ $l['numero'] ?? '' }}" aria-label="N° de la déclaration d’investissement, ligne {{ $i + 1 }}"></td>
                            <td><input type="date" class="input" name="cadres_legaux[{{ $i }}][date_declaration]" value="{{ $l['date_declaration'] ?? '' }}" aria-label="Date de la déclaration d’investissement, ligne {{ $i + 1 }}"></td>
                            <td><input type="date" class="input" name="cadres_legaux[{{ $i }}][date_entree]" value="{{ $l['date_entree'] ?? '' }}" aria-label="Date d’entrée en activité, ligne {{ $i + 1 }}"></td>
                        </tr>
                    @endfor
                    </tbody>
                </table>
            </div>
            @error('cadres_legaux')<p class="px-5 pb-3 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div class="card">
            <div class="card-h"><h2 class="card-t" x-text="tp === 'physique' ? 'Identité de la personne physique' : 'Gérant'">Gérant</h2></div>
            <div class="card-b grid gap-4 md:grid-cols-3">
                <div x-show="tp !== 'physique'"><x-field label="Nom et prénom" name="gerant_nom" :value="$client->gerant_nom"/></div>
                <x-field label="Nationalité" name="gerant_nationalite" :value="$client->gerant_nationalite" x-model="nat" list="nationalites" placeholder="Tunisienne, Française…" hint="Tunisienne : carte d’identité — autre : passeport et carte de séjour"/>
                <datalist id="nationalites">@foreach(['Tunisienne', 'Algérienne', 'Libyenne', 'Marocaine', 'Française', 'Italienne', 'Allemande'] as $n)<option value="{{ $n }}">@endforeach</datalist>
                <x-field label="Adresse" name="gerant_adresse" :value="$client->gerant_adresse"/>
                <div x-show="! etr(nat)"><x-field label="N° CIN" name="gerant_cin" :value="$client->gerant_cin"/></div>
                <div x-show="! etr(nat)"><x-field label="CIN délivrée le" name="gerant_cin_date" type="date" :value="$d('gerant_cin_date')"/></div>
                <div x-show="! etr(nat)"><x-field label="CIN délivrée à" name="gerant_cin_lieu" :value="$client->gerant_cin_lieu"/></div>
                <div x-show="! tun(nat)"><x-field label="N° de passeport" name="gerant_passeport" :value="$client->gerant_passeport"/></div>
                <div x-show="! tun(nat)"><x-field label="Passeport délivré le" name="gerant_passeport_date" type="date" :value="$d('gerant_passeport_date')"/></div>
                <div x-show="! tun(nat)"><x-field label="Passeport valable jusqu’au" name="gerant_passeport_validite" type="date" :value="$d('gerant_passeport_validite')"/></div>
                <div x-show="! tun(nat)"><x-field label="Carte de séjour (le cas échéant)" name="gerant_carte_sejour" :value="$client->gerant_carte_sejour"/></div>
                <x-field label="Date de naissance" name="gerant_naissance_date" type="date" :value="$d('gerant_naissance_date')"/>
                <x-field label="Lieu de naissance" name="gerant_naissance_lieu" :value="$client->gerant_naissance_lieu"/>
                <div x-show="tp !== 'physique'"><x-field label="Date de nomination" name="gerant_nomination_date" type="date" :value="$d('gerant_nomination_date')"/></div>
                <div x-show="tp !== 'physique'"><x-field label="Durée de nomination" name="gerant_nomination_duree" :value="$client->gerant_nomination_duree" placeholder="illimitée, 3 ans…"/></div>
                <div x-show="tp !== 'physique'"><label class="label">Le gérant est-il associé ?</label>
                    <select name="gerant_associe" class="input" x-model="gas"><option value="">—</option><option value="1">Oui, associé</option><option value="0">Non associé</option></select></div>
                <template x-if="gas === '1' && tp !== 'physique'">
                    <div class="grid gap-4 md:col-span-3 md:grid-cols-4">
                        <x-field label="Parts détenues" name="gerant_parts" type="number" :value="$client->gerant_parts" x-model="gp"/>
                        <x-field label="Parts numérotées de" name="gerant_parts_de" type="number" :value="$client->gerant_parts_de"/>
                        <x-field label="à" name="gerant_parts_a" type="number" :value="$client->gerant_parts_a"/>
                        <x-select label="Apport" name="gerant_apport" :value="$client->gerant_apport" :options="['numeraire' => 'En numéraire', 'nature' => 'En nature']"/>
                    </div>
                </template>
            </div>
        </div>

        <div class="card" x-show="tp !== 'physique'">
            <div class="card-h"><h2 class="card-t">Associés</h2><button type="button" class="btn-secondary btn-sm" @click="addA()"><x-icon name="plus" class="h-3.5 w-3.5"/> Ajouter un associé</button></div>
            <div class="card-b space-y-3">
                <template x-for="(a, i) in associes" :key="i">
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4 md:grid-cols-4 dark:border-slate-700">
                        <div class="md:col-span-2"><label class="label">Nom et prénom</label><input class="input" :name="`associes[${i}][nom]`" x-model="a.nom"></div>
                        <div><label class="label">Nationalité</label><input class="input" list="nationalites" placeholder="Tunisienne, Française…" :name="`associes[${i}][nationalite]`" x-model="a.nationalite"></div>
                        <div x-show="! etr(a.nationalite)"><label class="label">CIN</label><input class="input" :name="`associes[${i}][cin]`" x-model="a.cin"></div>
                        <div x-show="! etr(a.nationalite)"><label class="label">CIN délivrée le</label><input type="date" class="input" :name="`associes[${i}][cin_date]`" x-model="a.cin_date"></div>
                        <div x-show="! etr(a.nationalite)"><label class="label">CIN délivrée à</label><input class="input" :name="`associes[${i}][cin_lieu]`" x-model="a.cin_lieu"></div>
                        <div x-show="! tun(a.nationalite)"><label class="label">Passeport</label><input class="input" :name="`associes[${i}][passeport]`" x-model="a.passeport"></div>
                        <div x-show="! tun(a.nationalite)"><label class="label">Passeport délivré le</label><input type="date" class="input" :name="`associes[${i}][passeport_date]`" x-model="a.passeport_date"></div>
                        <div x-show="! tun(a.nationalite)"><label class="label">Passeport valable jusqu’au</label><input type="date" class="input" :name="`associes[${i}][passeport_validite]`" x-model="a.passeport_validite"></div>
                        <div x-show="! tun(a.nationalite)"><label class="label">Carte de séjour (le cas échéant)</label><input class="input" :name="`associes[${i}][carte_sejour]`" x-model="a.carte_sejour"></div>
                        <div><label class="label">Date de naissance</label><input type="date" class="input" :name="`associes[${i}][naissance_date]`" x-model="a.naissance_date"></div>
                        <div><label class="label">Lieu de naissance</label><input class="input" :name="`associes[${i}][naissance_lieu]`" x-model="a.naissance_lieu"></div>
                        <div class="md:col-span-2"><label class="label">Adresse</label><input class="input" :name="`associes[${i}][adresse]`" x-model="a.adresse"></div>
                        <div><label class="label">Parts (de … à …)</label><div class="flex gap-2"><input type="number" class="input" placeholder="de" :name="`associes[${i}][parts_de]`" x-model="a.parts_de"><input type="number" class="input" placeholder="à" :name="`associes[${i}][parts_a]`" x-model="a.parts_a"></div></div>
                        <div><label class="label">Nombre de parts</label><input type="number" class="input" :name="`associes[${i}][parts]`" x-model="a.parts"></div>
                        <div><label class="label">Apport</label><select class="input" :name="`associes[${i}][apport]`" x-model="a.apport"><option value="numeraire">En numéraire</option><option value="nature">En nature</option></select></div>
                        <div class="flex items-end justify-end"><button type="button" class="btn-ghost btn-sm !text-rose-600" @click="associes.splice(i, 1)"><x-icon name="trash" class="h-3.5 w-3.5"/> Retirer</button></div>
                    </div>
                </template>
                <p x-show="!associes.length" class="text-sm text-slate-500">Aucun associé renseigné.</p>
                <p x-show="+np > 0" class="text-xs" :class="totalParts() > +np ? 'text-rose-600' : 'text-slate-500'">Parts attribuées : <span x-text="totalParts()"></span> / <span x-text="np"></span></p>
            </div>
        </div>

        @unless($edit)
            <div class="card">
                <div class="card-h"><h2 class="card-t">Reprise de la comptabilité</h2><span class="text-xs text-slate-400">facultatif</span></div>
                <div class="card-b grid gap-4 md:grid-cols-3">
                    <div class="md:col-span-2"><label class="label" for="c-bn">Balance de l’exercice précédent (PDF, Excel ou Word)</label>
                        <input id="c-bn" type="file" name="balance_n1" accept=".pdf,.xlsx,.xls,.ods,.csv,.txt,.docx,.doc,.rtf" class="input">
                        @error('balance_n1')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                    <div><label class="label" for="c-ex">Comptabilité tenue à partir de</label>
                        <input id="c-ex" type="number" name="exercice" value="{{ old('exercice', now()->year) }}" min="2000" max="2100" class="input">
                        <p class="mt-1 text-xs text-slate-400">La balance importée est celle de l’année précédente.</p></div>
                    <p class="text-xs text-slate-500 md:col-span-3">Après l’enregistrement du client, les comptes lus vous sont présentés pour contrôle, puis passés en à-nouveaux.</p>
                </div>
            </div>
        @endunless

        <div class="flex justify-end gap-2">
            <a href="{{ $edit ? route('clients.show', $client) : route('clients.index') }}" class="btn-secondary">Annuler</a>
            <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
        </div>
    </form>
</x-layouts.app>
