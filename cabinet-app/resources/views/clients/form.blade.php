@php $edit = $client->exists; $d = fn($k) => $client->{$k} instanceof \DateTimeInterface ? $client->{$k}->format('Y-m-d') : $client->{$k}; @endphp
<x-layouts.app :title="$edit ? 'Modifier '.$client->nom : 'Nouveau client'">
    <x-page-header :title="$edit ? $client->nom : 'Nouveau client'" :subtitle="$edit ? 'Modifier la fiche du client' : 'Créer un dossier client'" :back="$edit ? route('clients.show', $client) : route('clients.index')"/>

    <form method="POST" action="{{ $edit ? route('clients.update', $client) : route('clients.store') }}" class="space-y-6"
          x-data="{ associes: @js($associes), gas: '{{ $client->gerant_associe === null ? '' : ($client->gerant_associe ? '1' : '0') }}',
                    np: '{{ $client->nb_parts }}', vn: '{{ $client->valeur_nominale }}', cap: '{{ $client->capital > 0 ? $client->capital : '' }}',
                    addA() { this.associes.push({nom:'',cin:'',parts:'',apport:'numeraire'}) },
                    calcCap() { if (+this.np > 0 && +this.vn > 0) this.cap = Math.round(this.np * this.vn * 1000) / 1000 } }">
        @csrf @if($edit) @method('PUT') @endif

        <div class="card">
            <div class="card-h"><h2 class="card-t">Identification</h2></div>
            <div class="card-b grid gap-4 md:grid-cols-3">
                <x-field label="Nom / raison sociale *" name="nom" :value="$client->nom" required span="md:col-span-2"/>
                <x-field label="Forme juridique" name="forme" :value="$client->forme" placeholder="SARL, SUARL, SA, BNC…" list="formes"/>
                <datalist id="formes">@foreach(['SARL','SUARL','SA','SAS','SNC','Personne physique','Libéral (BNC)','Commerçant (BIC)','Association'] as $f)<option value="{{ $f }}">@endforeach</datalist>
                <x-select label="Type de personne" name="type_personne" :value="$client->type_personne" placeholder="—" :options="['morale' => 'Personne morale', 'physique' => 'Personne physique']"/>
                <x-field label="Dénomination commerciale" name="denomination_commerciale" :value="$client->denomination_commerciale"/>
                <x-select label="Résidence" name="residence" :value="$client->residence" placeholder="Non précisé" :options="['residente' => 'Société résidente', 'non_residente' => 'Société non résidente']"/>
                <x-select label="Régime d’exportation" name="regime_export" :value="$client->regime_export" placeholder="Aucun / non précisé" :options="['totale' => 'Totalement exportatrice', 'partielle' => 'Partiellement exportatrice']"/>
                <x-field label="Matricule fiscal" name="matricule_fiscal" :value="$client->matricule_fiscal" placeholder="1234567/A/M/000"/>
                <x-field label="Matricule CNSS" name="matricule_cnss" :value="$client->matricule_cnss"/>
                <x-field label="Matricule indépendant" name="matricule_independant" :value="$client->matricule_independant"/>
                <x-field label="Adresse du siège" name="adresse" :value="$client->adresse" span="md:col-span-2"/>
                <x-field label="Téléphone" name="telephone" :value="$client->telephone"/>
                <x-field label="E-mail" name="email" type="email" :value="$client->email"/>
            </div>
        </div>

        <div class="card">
            <div class="card-h"><h2 class="card-t">Mission & capital</h2></div>
            <div class="card-b grid gap-4 md:grid-cols-3">
                <x-field label="Mission confiée" name="mission" :value="$client->mission" placeholder="Tenue + bilan, paie…"/>
                <x-field label="Honoraires mensuels (TND)" name="honoraires_mensuels" type="number" step="0.001" :value="$client->honoraires_mensuels > 0 ? $client->honoraires_mensuels : ''"/>
                <x-field label="Compte comptable client" name="compte_comptable" :value="$client->compte_comptable" placeholder="411001"/>
                <div><label class="label">Nombre de parts sociales</label><input name="nb_parts" type="number" class="input" x-model="np" @input="calcCap()"></div>
                <div><label class="label">Valeur nominale de la part (TND)</label><input name="valeur_nominale" type="number" step="0.001" class="input" x-model="vn" @input="calcCap()"></div>
                <div><label class="label">Capital (TND)</label><input name="capital" type="number" step="0.001" class="input" x-model="cap"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-h"><h2 class="card-t">Gérant</h2></div>
            <div class="card-b grid gap-4 md:grid-cols-3">
                <x-field label="Nom et prénom" name="gerant_nom" :value="$client->gerant_nom"/>
                <x-field label="Nationalité" name="gerant_nationalite" :value="$client->gerant_nationalite"/>
                <x-field label="Adresse" name="gerant_adresse" :value="$client->gerant_adresse"/>
                <x-field label="N° CIN" name="gerant_cin" :value="$client->gerant_cin"/>
                <x-field label="CIN délivrée le" name="gerant_cin_date" type="date" :value="$d('gerant_cin_date')"/>
                <x-field label="CIN délivrée à" name="gerant_cin_lieu" :value="$client->gerant_cin_lieu"/>
                <x-field label="Carte de séjour (résident étranger)" name="gerant_carte_sejour" :value="$client->gerant_carte_sejour"/>
                <x-field label="N° de passeport" name="gerant_passeport" :value="$client->gerant_passeport"/>
                <x-field label="Passeport délivré le" name="gerant_passeport_date" type="date" :value="$d('gerant_passeport_date')"/>
                <x-field label="Passeport valable jusqu’au" name="gerant_passeport_validite" type="date" :value="$d('gerant_passeport_validite')"/>
                <x-field label="Date de naissance" name="gerant_naissance_date" type="date" :value="$d('gerant_naissance_date')"/>
                <x-field label="Lieu de naissance" name="gerant_naissance_lieu" :value="$client->gerant_naissance_lieu"/>
                <x-field label="Date de nomination" name="gerant_nomination_date" type="date" :value="$d('gerant_nomination_date')"/>
                <x-field label="Durée de nomination" name="gerant_nomination_duree" :value="$client->gerant_nomination_duree" placeholder="illimitée, 3 ans…"/>
                <div><label class="label">Le gérant est-il associé ?</label>
                    <select name="gerant_associe" class="input" x-model="gas"><option value="">—</option><option value="1">Oui, associé</option><option value="0">Non associé</option></select></div>
                <template x-if="gas === '1'">
                    <div class="grid gap-4 md:col-span-3 md:grid-cols-4">
                        <x-field label="Parts détenues" name="gerant_parts" type="number" :value="$client->gerant_parts"/>
                        <x-field label="Parts numérotées de" name="gerant_parts_de" type="number" :value="$client->gerant_parts_de"/>
                        <x-field label="à" name="gerant_parts_a" type="number" :value="$client->gerant_parts_a"/>
                        <x-select label="Apport" name="gerant_apport" :value="$client->gerant_apport" :options="['numeraire' => 'En numéraire', 'nature' => 'En nature']"/>
                    </div>
                </template>
            </div>
        </div>

        <div class="card">
            <div class="card-h"><h2 class="card-t">Associés</h2><button type="button" class="btn-secondary btn-sm" @click="addA()"><x-icon name="plus" class="h-3.5 w-3.5"/> Ajouter un associé</button></div>
            <div class="card-b space-y-3">
                <template x-for="(a, i) in associes" :key="i">
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4 md:grid-cols-4 dark:border-slate-700">
                        <div class="md:col-span-2"><label class="label">Nom et prénom</label><input class="input" :name="`associes[${i}][nom]`" x-model="a.nom"></div>
                        <div><label class="label">CIN</label><input class="input" :name="`associes[${i}][cin]`" x-model="a.cin"></div>
                        <div><label class="label">Passeport</label><input class="input" :name="`associes[${i}][passeport]`" x-model="a.passeport"></div>
                        <div><label class="label">CIN délivrée le</label><input type="date" class="input" :name="`associes[${i}][cin_date]`" x-model="a.cin_date"></div>
                        <div><label class="label">Nationalité</label><input class="input" :name="`associes[${i}][nationalite]`" x-model="a.nationalite"></div>
                        <div><label class="label">Date de naissance</label><input type="date" class="input" :name="`associes[${i}][naissance_date]`" x-model="a.naissance_date"></div>
                        <div><label class="label">Lieu de naissance</label><input class="input" :name="`associes[${i}][naissance_lieu]`" x-model="a.naissance_lieu"></div>
                        <div class="md:col-span-2"><label class="label">Adresse</label><input class="input" :name="`associes[${i}][adresse]`" x-model="a.adresse"></div>
                        <div><label class="label">Parts (de … à …)</label><div class="flex gap-2"><input type="number" class="input" placeholder="de" :name="`associes[${i}][parts_de]`" x-model="a.parts_de"><input type="number" class="input" placeholder="à" :name="`associes[${i}][parts_a]`" x-model="a.parts_a"></div></div>
                        <div><label class="label">Nombre de parts</label><input type="number" class="input" :name="`associes[${i}][parts]`" x-model="a.parts"></div>
                        <div><label class="label">Apport</label><select class="input" :name="`associes[${i}][apport]`" x-model="a.apport"><option value="numeraire">En numéraire</option><option value="nature">En nature</option></select></div>
                        <div class="flex items-end justify-end md:col-span-3"><button type="button" class="btn-ghost btn-sm !text-rose-600" @click="associes.splice(i, 1)"><x-icon name="trash" class="h-3.5 w-3.5"/> Retirer</button></div>
                    </div>
                </template>
                <p x-show="!associes.length" class="text-sm text-slate-500">Aucun associé renseigné.</p>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ $edit ? route('clients.show', $client) : route('clients.index') }}" class="btn-secondary">Annuler</a>
            <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
        </div>
    </form>
</x-layouts.app>
