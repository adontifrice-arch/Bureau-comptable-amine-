{{-- Paramétrage de la saisie du dossier : valeurs par défaut, banque et règles d'affectation, modèles de factures --}}
@php
    $pa = $saisie['achat']; $pv = $saisie['vente']; $pb = $saisie['banque'];
    $charges = collect($plan)->filter(fn ($l, $n) => preg_match('/^[26]/', $n) && ! preg_match('/^2[89]/', $n));
    $produits = collect($plan)->filter(fn ($l, $n) => str_starts_with($n, '7') && strlen($n) >= 3);
    $lib = fn ($n) => $n ? $n.' '.\App\Services\Compta::libelle((string) $n) : '—';
@endphp
<datalist id="cpts-param" data-plan>@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>

<div class="grid gap-6 xl:grid-cols-2">
    <form method="POST" action="{{ route('compta.parametres') }}" class="card" x-data="{ regles: @js(array_values($pb['regles'])), achats: @js($pa['comptes']), ventes: @js($pv['comptes']) }">@csrf
        <datalist id="taux-tva">@foreach(\App\Services\Saisie::TAUX_TVA as $t)<option value="{{ $t }}">{{ $t }} %</option>@endforeach</datalist>
        <div class="card-h"><h2 class="card-t">Paramétrage de la saisie — {{ $client->nom }}</h2>@unless($chef)<span class="badge-gray">Lecture seule</span>@endunless</div>
        <fieldset @disabled(! $chef) class="space-y-6 p-5">
            <div>
                <h3 class="mb-2 text-sm font-semibold">Factures d’achat</h3>
                <div class="grid gap-3 md:grid-cols-3">
                    <div><label class="label" for="pa-t">Compte fournisseur</label><input id="pa-t" name="achat[compte_tiers]" value="{{ $pa['compte_tiers'] }}" list="cpts-param" class="input font-mono" required data-compte autocomplete="off"><p data-compte-libelle class="mt-1 truncate text-xs text-slate-400">{{ \App\Services\Compta::libelle((string) $pa['compte_tiers']) }}</p></div>
                    <div><label class="label" for="pa-tx">Taux de TVA général (%)</label><input id="pa-tx" type="number" step="0.01" min="0" max="100" name="achat[tva_taux]" value="{{ $pa['tva_taux'] }}" list="taux-tva" class="input" required><p class="mt-1 text-xs text-slate-400">Comptes non listés ci-dessous</p></div>
                    <div><label class="label" for="pa-tim">Droit de timbre</label><input id="pa-tim" type="number" step="0.001" min="0" name="achat[timbre]" value="{{ $pa['timbre'] }}" class="input"></div>
                    <div><label class="label" for="pa-rs">Retenue à la source (%)</label><input id="pa-rs" type="number" step="0.01" min="0" max="100" name="achat[retenue_taux]" value="{{ $pa['retenue_taux'] }}" class="input"></div>
                    <label class="flex items-center gap-2 text-sm md:col-span-3"><input type="hidden" name="achat[tva_deductible]" value="0"><input type="checkbox" name="achat[tva_deductible]" value="1" @checked($pa['tva_deductible']) class="rounded border-slate-300 text-brand-600"> TVA récupérable par défaut (4366)</label>
                </div>
                <datalist id="cpts-charges" data-plan>@foreach($charges as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>
                <div class="mt-3">
                    <div class="label">Comptes de charge proposés et leur taux de TVA <span class="font-normal text-slate-400">— le premier est proposé par défaut</span></div>
                    <template x-for="(x, i) in achats" :key="i">
                        <div class="mb-2 grid grid-cols-12 items-start gap-2">
                            <div class="col-span-7"><input class="input font-mono" placeholder="Compte (ex. 6061)" list="cpts-charges" :name="`achat[comptes][${i}][compte]`" x-model="x.compte" data-compte autocomplete="off" aria-label="Compte de charge"><p data-compte-libelle class="mt-0.5 truncate text-xs text-slate-400" x-init="$el.textContent = (window.PLAN || {})[x.compte] || ''"></p></div>
                            <div class="col-span-4"><input type="number" step="0.01" min="0" max="100" class="input" list="taux-tva" placeholder="TVA %" :name="`achat[comptes][${i}][tva_taux]`" x-model="x.tva_taux" aria-label="Taux de TVA"></div>
                            <button type="button" class="btn-icon col-span-1" @click="achats.splice(i, 1)" title="Retirer"><x-icon name="trash" class="h-4 w-4"/></button>
                        </div>
                    </template>
                    <button type="button" class="btn-secondary btn-sm" @click="achats.push({compte: '', tva_taux: 19})">+ Compte de charge</button>
                </div>
            </div>
            <div>
                <h3 class="mb-2 text-sm font-semibold">Factures de vente</h3>
                <div class="grid gap-3 md:grid-cols-3">
                    <div><label class="label" for="pv-t">Compte client</label><input id="pv-t" name="vente[compte_tiers]" value="{{ $pv['compte_tiers'] }}" list="cpts-param" class="input font-mono" required data-compte autocomplete="off"><p data-compte-libelle class="mt-1 truncate text-xs text-slate-400">{{ \App\Services\Compta::libelle((string) $pv['compte_tiers']) }}</p></div>
                    <div><label class="label" for="pv-tx">Taux de TVA général (%)</label><input id="pv-tx" type="number" step="0.01" min="0" max="100" name="vente[tva_taux]" value="{{ $pv['tva_taux'] }}" list="taux-tva" class="input" required><p class="mt-1 text-xs text-slate-400">Comptes non listés ci-dessous</p></div>
                    <div><label class="label" for="pv-tim">Droit de timbre</label><input id="pv-tim" type="number" step="0.001" min="0" name="vente[timbre]" value="{{ $pv['timbre'] }}" class="input"></div>
                    <div><label class="label" for="pv-ct">Compte du timbre</label><input id="pv-ct" name="vente[compte_timbre]" value="{{ $pv['compte_timbre'] }}" list="cpts-param" class="input font-mono" required data-compte autocomplete="off"><p data-compte-libelle class="mt-1 truncate text-xs text-slate-400">{{ \App\Services\Compta::libelle((string) $pv['compte_timbre']) }}</p></div>
                </div>
                <datalist id="cpts-produits" data-plan>@foreach($produits as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>
                <div class="mt-3">
                    <div class="label">Comptes de vente proposés et leur taux de TVA <span class="font-normal text-slate-400">— le premier est proposé par défaut</span></div>
                    <template x-for="(x, i) in ventes" :key="i">
                        <div class="mb-2 grid grid-cols-12 items-start gap-2">
                            <div class="col-span-7"><input class="input font-mono" placeholder="Compte (ex. 705)" list="cpts-produits" :name="`vente[comptes][${i}][compte]`" x-model="x.compte" data-compte autocomplete="off" aria-label="Compte de vente"><p data-compte-libelle class="mt-0.5 truncate text-xs text-slate-400" x-init="$el.textContent = (window.PLAN || {})[x.compte] || ''"></p></div>
                            <div class="col-span-4"><input type="number" step="0.01" min="0" max="100" class="input" list="taux-tva" placeholder="TVA %" :name="`vente[comptes][${i}][tva_taux]`" x-model="x.tva_taux" aria-label="Taux de TVA"></div>
                            <button type="button" class="btn-icon col-span-1" @click="ventes.splice(i, 1)" title="Retirer"><x-icon name="trash" class="h-4 w-4"/></button>
                        </div>
                    </template>
                    <button type="button" class="btn-secondary btn-sm" @click="ventes.push({compte: '', tva_taux: 19})">+ Compte de vente</button>
                </div>
            </div>
            <div>
                <h3 class="mb-2 text-sm font-semibold">Banque (import des relevés)</h3>
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="label" for="pb-c">Compte de banque</label><input id="pb-c" name="banque[compte]" value="{{ $pb['compte'] }}" list="cpts-param" class="input font-mono" required data-compte autocomplete="off"><p data-compte-libelle class="mt-1 text-xs text-slate-400">{{ \App\Services\Compta::libelle((string) $pb['compte']) }}</p></div>
                    <div><label class="label" for="pb-a">Compte d’attente</label><input id="pb-a" name="banque[attente]" value="{{ $pb['attente'] }}" list="cpts-param" class="input font-mono" required data-compte autocomplete="off"><p class="mt-1 text-xs text-slate-400"><span data-compte-libelle>{{ \App\Services\Compta::libelle((string) $pb['attente']) }}</span> — contrepartie des opérations non reconnues</p></div>
                </div>
                <div class="mt-3">
                    <div class="label">Règles d’affectation : si le libellé contient…</div>
                    <template x-for="(x, i) in regles" :key="i">
                        <div class="mb-2 grid grid-cols-12 gap-2">
                            <input class="input col-span-6" placeholder="Mot-clé (ex. STEG)" :name="`banque[regles][${i}][mot_cle]`" x-model="x.mot_cle" aria-label="Mot-clé">
                            <input class="input col-span-5 font-mono" placeholder="Compte" list="cpts-param" :name="`banque[regles][${i}][compte]`" x-model="x.compte" aria-label="Compte" data-compte autocomplete="off">
                            <button type="button" class="btn-icon col-span-1" @click="regles.splice(i, 1)" title="Retirer"><x-icon name="trash" class="h-4 w-4"/></button>
                        </div>
                    </template>
                    <button type="button" class="btn-secondary btn-sm" @click="regles.push({mot_cle: '', compte: ''})">+ Règle</button>
                </div>
            </div>
            @if($chef)<div class="flex justify-end"><button class="btn-primary">Enregistrer le paramétrage</button></div>@endif
        </fieldset>
    </form>

    <div class="card self-start" x-data="{ m: { comptes: [] } }" @mod-edit.window="m = Object.assign({ comptes: [] }, $event.detail); if (! m.comptes.length) m.comptes.push({ compte: '', tva_taux: 19 })">
        <div class="card-h"><h2 class="card-t">Modèles de saisie rapide</h2>
            <div class="flex gap-2">
                <button class="btn-secondary btn-sm" @click="$dispatch('mod-edit', {type: 'achat', tva_taux: {{ \App\Services\Saisie::tauxParDefaut($pa) }}, timbre: {{ (float) $pa['timbre'] }}, retenue_taux: {{ (float) $pa['retenue_taux'] }}, tva_deductible: '{{ $pa['tva_deductible'] ? 1 : 0 }}', comptes: [{ compte: @js((string) $pa['compte']), tva_taux: {{ \App\Services\Saisie::tauxParDefaut($pa) }} }]}); $dispatch('open-modal', 'modele')">+ Achat</button>
                <button class="btn-secondary btn-sm" @click="$dispatch('mod-edit', {type: 'vente', tva_taux: {{ \App\Services\Saisie::tauxParDefaut($pv) }}, timbre: {{ (float) $pv['timbre'] }}, comptes: [{ compte: @js((string) $pv['compte']), tva_taux: {{ \App\Services\Saisie::tauxParDefaut($pv) }} }]}); $dispatch('open-modal', 'modele')">+ Vente</button>
            </div>
        </div>
        @forelse($modeles->groupBy('type') as $type => $liste)
            <div class="border-b border-slate-100 px-5 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800">{{ \App\Models\ModeleSaisie::TYPES[$type] }}</div>
            @foreach($liste as $mo)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-2.5 text-sm last:border-0 dark:border-slate-800">
                    <div class="min-w-0 flex-1"><div class="font-medium">{{ $mo->nom }}</div>
                        <div class="text-xs text-slate-500">{{ $mo->tiers }}{{ $mo->tiers ? ' · ' : '' }}@forelse($mo->lignes() as $i => $c){{ $i ? ' · ' : '' }}<span class="font-mono">{{ $c['compte'] }}</span> {{ (float) $c['tva_taux'] }} %@empty TVA {{ (float) $mo->tva_taux }} %@endforelse{{ $mo->timbre > 0 ? ' · timbre '.n3($mo->timbre) : '' }}{{ $mo->retenue_taux > 0 ? ' · RS '.(float) $mo->retenue_taux.' %' : '' }}</div></div>
                    <button class="btn-icon" title="Modifier" @click="$dispatch('mod-edit', @js($mo->only(['id', 'type', 'nom', 'tiers', 'matricule_fiscal', 'description', 'tva_taux', 'timbre', 'retenue_taux']) + ['tva_deductible' => $mo->tva_deductible ? '1' : '0', 'comptes' => $mo->lignes()])); $dispatch('open-modal', 'modele')"><x-icon name="edit" class="h-4 w-4"/></button>
                    <x-confirm-delete :action="route('compta.modeles.destroy', $mo)" message="Supprimer ce modèle ?"/>
                </div>
            @endforeach
        @empty
            <x-empty icon="copy" title="Aucun modèle">Créez un modèle par fournisseur ou client habituel (STEG, loyer, honoraires…), ou cochez « Mémoriser comme modèle » lors de la saisie d’une facture.</x-empty>
        @endforelse

        <x-modal name="modele" title="Modèle de saisie" size="max-w-2xl">
            <form method="POST" :action="m.id ? '{{ url('comptabilite/modeles') }}/' + m.id : '{{ route('compta.modeles.store') }}'" class="grid gap-3 md:grid-cols-2">@csrf
                <template x-if="m.id"><input type="hidden" name="_method" value="PUT"></template>
                <div><label class="label" for="mo-type">Type</label><select id="mo-type" name="type" class="input" x-model="m.type">@foreach(\App\Models\ModeleSaisie::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div><label class="label" for="mo-nom">Nom du modèle *</label><input id="mo-nom" name="nom" class="input" x-model="m.nom" required placeholder="ex. STEG électricité"></div>
                <div><label class="label" for="mo-tiers" x-text="m.type === 'vente' ? 'Client' : 'Fournisseur'"></label><input id="mo-tiers" name="tiers" class="input" x-model="m.tiers"></div>
                <div><label class="label" for="mo-mf">Matricule fiscal</label><input id="mo-mf" name="matricule_fiscal" class="input" x-model="m.matricule_fiscal"></div>
                <div class="md:col-span-2"><label class="label" for="mo-desc">Description proposée</label><input id="mo-desc" name="description" class="input" x-model="m.description"></div>
                <div class="md:col-span-2">
                    <div class="label"><span x-text="m.type === 'vente' ? 'Comptes de vente' : 'Comptes de charge / immobilisation'"></span> et leur taux de TVA <span class="font-normal text-slate-400">— le premier est appliqué</span></div>
                    <template x-for="(x, i) in m.comptes" :key="i">
                        <div class="mb-2 grid grid-cols-12 items-start gap-2">
                            <div class="col-span-7"><input class="input font-mono" placeholder="Compte" list="cpts-param" :name="`comptes[${i}][compte]`" x-model="x.compte" data-compte autocomplete="off" aria-label="Compte"><p data-compte-libelle class="mt-0.5 truncate text-xs text-slate-400" x-init="$el.textContent = (window.PLAN || {})[x.compte] || ''"></p></div>
                            <div class="col-span-4"><input type="number" step="0.01" min="0" max="100" class="input" list="taux-tva" placeholder="TVA %" :name="`comptes[${i}][tva_taux]`" x-model="x.tva_taux" aria-label="Taux de TVA" required></div>
                            <button type="button" class="btn-icon col-span-1" @click="m.comptes.length > 1 ? m.comptes.splice(i, 1) : (m.comptes[0] = { compte: '', tva_taux: 19 })" title="Retirer"><x-icon name="trash" class="h-4 w-4"/></button>
                        </div>
                    </template>
                    <button type="button" class="btn-secondary btn-sm" @click="m.comptes.push({ compte: '', tva_taux: 19 })">+ Ligne compte · TVA</button>
                </div>
                <div><label class="label" for="mo-tim">Droit de timbre</label><input id="mo-tim" type="number" step="0.001" min="0" name="timbre" class="input" x-model="m.timbre"></div>
                <template x-if="m.type === 'achat'"><div><label class="label" for="mo-rs">Retenue à la source (%)</label><input id="mo-rs" type="number" step="0.01" min="0" max="100" name="retenue_taux" class="input" x-model="m.retenue_taux"></div></template>
                <template x-if="m.type === 'achat'"><div><label class="label" for="mo-ded">TVA récupérable</label><select id="mo-ded" name="tva_deductible" class="input" x-model="m.tva_deductible"><option value="1">Oui (4366)</option><option value="0">Non (incluse en charge)</option></select></div></template>
                <div class="flex justify-end md:col-span-2"><button class="btn-primary">Enregistrer le modèle</button></div>
            </form>
        </x-modal>
    </div>
</div>
