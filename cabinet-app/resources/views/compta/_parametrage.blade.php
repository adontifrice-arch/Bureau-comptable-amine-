{{-- Paramétrage de la saisie du dossier : valeurs par défaut, banque et règles d'affectation, modèles de factures --}}
@php
    $pa = $saisie['achat']; $pv = $saisie['vente']; $pb = $saisie['banque'];
    $charges = collect($plan)->filter(fn ($l, $n) => preg_match('/^[26]/', $n) && ! preg_match('/^2[89]/', $n));
    $produits = collect($plan)->filter(fn ($l, $n) => str_starts_with($n, '7') && strlen($n) >= 3);
    $lib = fn ($n) => $n ? $n.' '.\App\Services\Compta::libelle((string) $n) : '—';
@endphp
<datalist id="cpts-param">@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>

<div class="grid gap-6 xl:grid-cols-2">
    <form method="POST" action="{{ route('compta.parametres') }}" class="card" x-data="{ regles: @js(array_values($pb['regles'])) }">@csrf
        <div class="card-h"><h2 class="card-t">Paramétrage de la saisie — {{ $client->nom }}</h2>@unless($chef)<span class="badge-gray">Lecture seule</span>@endunless</div>
        <fieldset @disabled(! $chef) class="space-y-6 p-5">
            <div>
                <h3 class="mb-2 text-sm font-semibold">Factures d’achat</h3>
                <div class="grid gap-3 md:grid-cols-3">
                    <div><label class="label" for="pa-t">Compte fournisseur</label><input id="pa-t" name="achat[compte_tiers]" value="{{ $pa['compte_tiers'] }}" list="cpts-param" class="input font-mono" required></div>
                    <div class="md:col-span-2"><label class="label" for="pa-c">Compte de charge proposé</label><select id="pa-c" name="achat[compte]" class="input"><option value="">— aucun —</option>@foreach($charges as $n => $l)<option value="{{ $n }}" @selected((string) $pa['compte'] === (string) $n)>{{ $n }} {{ $l }}</option>@endforeach</select></div>
                    <div><label class="label" for="pa-tx">Taux de TVA (%)</label><input id="pa-tx" type="number" step="0.01" min="0" max="100" name="achat[tva_taux]" value="{{ $pa['tva_taux'] }}" class="input" required></div>
                    <div><label class="label" for="pa-tim">Droit de timbre</label><input id="pa-tim" type="number" step="0.001" min="0" name="achat[timbre]" value="{{ $pa['timbre'] }}" class="input"></div>
                    <div><label class="label" for="pa-rs">Retenue à la source (%)</label><input id="pa-rs" type="number" step="0.01" min="0" max="100" name="achat[retenue_taux]" value="{{ $pa['retenue_taux'] }}" class="input"></div>
                    <label class="flex items-center gap-2 text-sm md:col-span-3"><input type="hidden" name="achat[tva_deductible]" value="0"><input type="checkbox" name="achat[tva_deductible]" value="1" @checked($pa['tva_deductible']) class="rounded border-slate-300 text-brand-600"> TVA récupérable par défaut (4366)</label>
                </div>
            </div>
            <div>
                <h3 class="mb-2 text-sm font-semibold">Factures de vente</h3>
                <div class="grid gap-3 md:grid-cols-3">
                    <div><label class="label" for="pv-t">Compte client</label><input id="pv-t" name="vente[compte_tiers]" value="{{ $pv['compte_tiers'] }}" list="cpts-param" class="input font-mono" required></div>
                    <div class="md:col-span-2"><label class="label" for="pv-c">Compte de vente proposé</label><select id="pv-c" name="vente[compte]" class="input"><option value="">— aucun —</option>@foreach($produits as $n => $l)<option value="{{ $n }}" @selected((string) $pv['compte'] === (string) $n)>{{ $n }} {{ $l }}</option>@endforeach</select></div>
                    <div><label class="label" for="pv-tx">Taux de TVA (%)</label><input id="pv-tx" type="number" step="0.01" min="0" max="100" name="vente[tva_taux]" value="{{ $pv['tva_taux'] }}" class="input" required></div>
                    <div><label class="label" for="pv-tim">Droit de timbre</label><input id="pv-tim" type="number" step="0.001" min="0" name="vente[timbre]" value="{{ $pv['timbre'] }}" class="input"></div>
                    <div><label class="label" for="pv-ct">Compte du timbre</label><input id="pv-ct" name="vente[compte_timbre]" value="{{ $pv['compte_timbre'] }}" list="cpts-param" class="input font-mono" required></div>
                </div>
            </div>
            <div>
                <h3 class="mb-2 text-sm font-semibold">Banque (import des relevés)</h3>
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="label" for="pb-c">Compte de banque</label><input id="pb-c" name="banque[compte]" value="{{ $pb['compte'] }}" list="cpts-param" class="input font-mono" required><p class="mt-1 text-xs text-slate-400">{{ \App\Services\Compta::libelle((string) $pb['compte']) }}</p></div>
                    <div><label class="label" for="pb-a">Compte d’attente</label><input id="pb-a" name="banque[attente]" value="{{ $pb['attente'] }}" list="cpts-param" class="input font-mono" required><p class="mt-1 text-xs text-slate-400">{{ \App\Services\Compta::libelle((string) $pb['attente']) }} — contrepartie des opérations non reconnues</p></div>
                </div>
                <div class="mt-3">
                    <div class="label">Règles d’affectation : si le libellé contient…</div>
                    <template x-for="(x, i) in regles" :key="i">
                        <div class="mb-2 grid grid-cols-12 gap-2">
                            <input class="input col-span-6" placeholder="Mot-clé (ex. STEG)" :name="`banque[regles][${i}][mot_cle]`" x-model="x.mot_cle" aria-label="Mot-clé">
                            <input class="input col-span-5 font-mono" placeholder="Compte" list="cpts-param" :name="`banque[regles][${i}][compte]`" x-model="x.compte" aria-label="Compte">
                            <button type="button" class="btn-icon col-span-1" @click="regles.splice(i, 1)" title="Retirer"><x-icon name="trash" class="h-4 w-4"/></button>
                        </div>
                    </template>
                    <button type="button" class="btn-secondary btn-sm" @click="regles.push({mot_cle: '', compte: ''})">+ Règle</button>
                </div>
            </div>
            @if($chef)<div class="flex justify-end"><button class="btn-primary">Enregistrer le paramétrage</button></div>@endif
        </fieldset>
    </form>

    <div class="card self-start" x-data="{ m: {} }" @mod-edit.window="m = $event.detail">
        <div class="card-h"><h2 class="card-t">Modèles de saisie rapide</h2>
            <div class="flex gap-2">
                <button class="btn-secondary btn-sm" @click="$dispatch('mod-edit', {type: 'achat', tva_taux: {{ (float) $pa['tva_taux'] }}, timbre: {{ (float) $pa['timbre'] }}, retenue_taux: {{ (float) $pa['retenue_taux'] }}, tva_deductible: '{{ $pa['tva_deductible'] ? 1 : 0 }}', compte: @js((string) $pa['compte'])}); $dispatch('open-modal', 'modele')">+ Achat</button>
                <button class="btn-secondary btn-sm" @click="$dispatch('mod-edit', {type: 'vente', tva_taux: {{ (float) $pv['tva_taux'] }}, timbre: {{ (float) $pv['timbre'] }}, compte: @js((string) $pv['compte'])}); $dispatch('open-modal', 'modele')">+ Vente</button>
            </div>
        </div>
        @forelse($modeles->groupBy('type') as $type => $liste)
            <div class="border-b border-slate-100 px-5 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800">{{ \App\Models\ModeleSaisie::TYPES[$type] }}</div>
            @foreach($liste as $mo)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-2.5 text-sm last:border-0 dark:border-slate-800">
                    <div class="min-w-0 flex-1"><div class="font-medium">{{ $mo->nom }}</div>
                        <div class="text-xs text-slate-500">{{ $mo->tiers }}{{ $mo->tiers ? ' · ' : '' }}{{ $lib($mo->compte) }} · TVA {{ (float) $mo->tva_taux }} %{{ $mo->timbre > 0 ? ' · timbre '.n3($mo->timbre) : '' }}{{ $mo->retenue_taux > 0 ? ' · RS '.(float) $mo->retenue_taux.' %' : '' }}</div></div>
                    <button class="btn-icon" title="Modifier" @click="$dispatch('mod-edit', @js($mo->only(['id', 'type', 'nom', 'tiers', 'matricule_fiscal', 'description', 'compte', 'tva_taux', 'timbre', 'retenue_taux']) + ['tva_deductible' => $mo->tva_deductible ? '1' : '0'])); $dispatch('open-modal', 'modele')"><x-icon name="edit" class="h-4 w-4"/></button>
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
                <div class="md:col-span-2"><label class="label" for="mo-cpt" x-text="m.type === 'vente' ? 'Compte de vente' : 'Compte de charge / immobilisation'"></label><input id="mo-cpt" name="compte" class="input font-mono" list="cpts-param" x-model="m.compte"></div>
                <div><label class="label" for="mo-tx">Taux de TVA (%)</label><input id="mo-tx" type="number" step="0.01" min="0" max="100" name="tva_taux" class="input" x-model="m.tva_taux" required></div>
                <div><label class="label" for="mo-tim">Droit de timbre</label><input id="mo-tim" type="number" step="0.001" min="0" name="timbre" class="input" x-model="m.timbre"></div>
                <template x-if="m.type === 'achat'"><div><label class="label" for="mo-rs">Retenue à la source (%)</label><input id="mo-rs" type="number" step="0.01" min="0" max="100" name="retenue_taux" class="input" x-model="m.retenue_taux"></div></template>
                <template x-if="m.type === 'achat'"><div><label class="label" for="mo-ded">TVA récupérable</label><select id="mo-ded" name="tva_deductible" class="input" x-model="m.tva_deductible"><option value="1">Oui (4366)</option><option value="0">Non (incluse en charge)</option></select></div></template>
                <div class="flex justify-end md:col-span-2"><button class="btn-primary">Enregistrer le modèle</button></div>
            </form>
        </x-modal>
    </div>
</div>
