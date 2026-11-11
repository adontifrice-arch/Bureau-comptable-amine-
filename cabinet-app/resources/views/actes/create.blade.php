@php $ODD = config('cabinet.ODD'); @endphp
<x-layouts.app title="Rédiger un acte">
    <x-page-header title="Rédiger statuts / PV" :subtitle="$client->nom" :back="route('clients.show', [$client, 'tab' => 'actes'])">
        <a href="{{ route('modeles-actes.index') }}" class="btn-secondary"><x-icon name="upload" class="h-4 w-4"/> Modèles du cabinet</a>
    </x-page-header>
    <form method="POST" action="{{ route('actes.store', $client) }}" x-data="{ t: @js(old('type', 'sarl')), m: @js((string) old('modele_id', '')), odd: @js($ODD), modeles: @js($modeles) }" x-init="$watch('t', () => m = '')" class="card">@csrf
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
                <div class="md:col-span-2"><label class="label">Objet social</label><textarea name="objet" rows="3" class="input" placeholder="La société a pour objet…"></textarea></div>
                <div><label class="label">Durée (années)</label><input type="number" name="duree" value="99" class="input"></div>
            </div></template>
            <template x-if="['ago','age','mixte','cess'].includes(t)"><div class="md:col-span-2">
                <label class="label">Ordre du jour</label>
                <div class="grid gap-1.5 rounded-xl border border-slate-200 p-4 md:grid-cols-2 dark:border-slate-700">
                    <template x-for="item in (t === 'mixte' ? [...new Set([...odd.ago, ...odd.age])] : (odd[t] || []))" :key="item">
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="ordre[]" :value="item" checked class="mt-0.5 rounded border-slate-300 text-brand-600"><span x-text="item"></span></label>
                    </template>
                </div>
                <label class="label mt-3">Autres points (un par ligne)</label><textarea name="ordre_libre" rows="2" class="input"></textarea>
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
