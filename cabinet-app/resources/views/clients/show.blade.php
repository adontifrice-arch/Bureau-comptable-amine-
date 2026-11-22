<x-layouts.app :title="$client->nom">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <a href="{{ route('clients.index') }}" class="btn-icon"><x-icon name="arrow-left" class="h-4 w-4"/></a>
            <div class="grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-lg font-bold text-white shadow-lg shadow-brand-900/20">{{ $client->initiales() }}</div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $client->nom }}</h1>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                    <span>{{ ($client->estPhysique() ? ($client->activite ?: $client->forme) : $client->forme) ?: '—' }}</span>
                    <span class="badge-gray" @if(!$client->type_personne) title="Déduit de la forme juridique" @endif>{{ $client->estPhysique() ? 'Personne physique' : 'Personne morale' }}</span>
                    @if($client->mission)<span class="badge-blue">{{ $client->mission }}</span>@endif
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('echeances.calendrier', $client) }}" class="btn-secondary"><x-icon name="calendar" class="h-4 w-4"/> Calendrier</a>
            @unless($client->estPhysique())<a href="{{ route('actes.create', $client) }}" class="btn-secondary"><x-icon name="file-text" class="h-4 w-4"/> Rédiger statuts / PV</a>@endunless
            <a href="{{ route('factures.create', ['client' => $client->id]) }}" class="btn-secondary"><x-icon name="invoice" class="h-4 w-4"/> Facturer</a>
            <a href="{{ route('clients.edit', $client) }}" class="btn-primary"><x-icon name="edit" class="h-4 w-4"/> Modifier</a>
        </div>
    </div>

    @php $u = fn($t) => route('clients.show', [$client, 'tab' => $t]); @endphp
    <x-tabs :active="$tab" :items="['fiche' => ['Fiche', $u('fiche')], 'echeances' => ['Échéances ('.$echeances->whereNull('accomplie_le')->count().')', $u('echeances')], 'exigences' => ['Exigences ('.$client->exigences->count().')', $u('exigences')], 'factures' => ['Factures ('.$factures->count().')', $u('factures')], 'documents' => ['Documents ('.$documents->count().')', $u('documents')], 'actes' => ['Actes juridiques ('.$actes->count().')', $u('actes')], 'espace' => ['Espace client'.(($n = $documents->where('origine', 'client')->whereNull('traite_at')->count()) ? ' ('.$n.' à traiter)' : ''), $u('espace')]]"/>

    @if($tab === 'fiche')
        @php
            $bloc = function ($titre, $rows) { return [$titre, array_filter($rows, fn($v) => $v !== null && $v !== '' && $v !== '—')]; };
            $pp = $client->estPhysique();
            $identite = ['Nationalité' => $client->gerant_nationalite, 'CIN' => trim($client->gerant_cin.($client->gerant_cin_date ? ' du '.dfr($client->gerant_cin_date) : '').($client->gerant_cin_lieu ? ' à '.$client->gerant_cin_lieu : '')), 'Passeport' => $client->gerant_passeport ? trim($client->gerant_passeport.($client->gerant_passeport_date ? ' du '.dfr($client->gerant_passeport_date) : '').($client->gerant_passeport_validite ? ', valable jusqu’au '.dfr($client->gerant_passeport_validite) : '')) : null, 'Carte de séjour' => $client->gerant_carte_sejour, 'Né(e) le' => $client->gerant_naissance_date ? dfr($client->gerant_naissance_date).($client->gerant_naissance_lieu ? ' à '.$client->gerant_naissance_lieu : '') : null, 'Adresse' => $client->gerant_adresse];
            $blocs = $pp ? [
                $bloc('Identification', ['Activité' => $client->activite, 'Dénomination commerciale' => $client->denomination_commerciale, 'Résidence' => ['residente' => 'Résident(e)', 'non_residente' => 'Non résident(e)'][$client->residence] ?? null, 'Régime d’exportation' => ['non' => 'Non exportateur', 'totale' => 'Totalement exportateur', 'partielle' => 'Partiellement exportateur'][$client->regime_export] ?? null, 'Matricule fiscal' => $client->matricule_fiscal, 'Matricule CNSS' => $client->matricule_cnss, 'Matricule indépendant' => $client->matricule_independant, 'Adresse' => $client->adresse, 'Téléphone' => $client->telephone, 'E-mail' => $client->email]),
                $bloc('Mission & imposition', ['Mission' => $client->mission, 'Honoraires mensuels' => $client->honoraires_mensuels > 0 ? tnd($client->honoraires_mensuels) : null, 'Compte comptable' => $client->compte_comptable, 'Régime d’imposition' => \App\Models\Client::REGIMES_IMPOSITION[$client->regime_imposition] ?? null]
                    + collect($client->categories_revenus ?? [])->values()->mapWithKeys(fn ($k, $i) => [($i ? 'Catégorie '.($i + 1) : 'Catégorie de revenus') => \App\Models\Client::CATEGORIES_REVENUS[$k] ?? $k])->all()),
                $bloc('Identité', $identite),
                $bloc('Situation familiale', ['Situation' => \App\Models\Client::SITUATIONS_FAMILIALES[$client->situation_familiale] ?? null,
                    'Chef de famille' => $client->chef_famille === null ? null : ($client->chef_famille ? 'Oui' : 'Non')]
                    + collect($client->chef_famille ? ($client->enfants_charge ?? []) : [])->values()->mapWithKeys(fn ($e, $i) => ['Enfant '.($i + 1).($e['prenom'] ? ' — '.$e['prenom'] : '') => (\App\Models\Client::TYPES_ENFANT[$e['type']][0] ?? $e['type']).($i < \App\Models\Client::ENFANTS_DEDUCTIBLES ? ' ('.number_format(\App\Models\Client::TYPES_ENFANT[$e['type']][1] ?? 0, 0, ',', ' ').' DT)' : '')])->all()
                    + ['Déduction pour enfants à charge' => $client->deductionEnfants() ? number_format($client->deductionEnfants(), 0, ',', ' ').' DT' : null,
                       'Parents à charge' => $client->parents_charge ? $client->parents_charge.' (déduction : 5 % du revenu net imposable, 450 DT au plus par parent)' : null]),
            ] : [
                $bloc('Identification', ['Dénomination commerciale' => $client->denomination_commerciale, 'Résidence' => ['residente' => 'Résidente', 'non_residente' => 'Non résidente'][$client->residence] ?? null, 'Régime d’exportation' => ['non' => 'Non exportatrice', 'totale' => 'Totalement exportatrice', 'partielle' => 'Partiellement exportatrice'][$client->regime_export] ?? null, 'Dépôt des déclarations' => \App\Models\Client::MODES_DEPOT[$client->mode_depot] ?? null, 'Matricule fiscal' => $client->matricule_fiscal, 'Matricule CNSS' => $client->matricule_cnss, 'Matricule indépendant' => $client->matricule_independant, 'Adresse' => $client->adresse, 'Téléphone' => $client->telephone, 'E-mail' => $client->email]),
                $bloc('Mission & capital', ['Mission' => $client->mission, 'Honoraires mensuels' => $client->honoraires_mensuels > 0 ? tnd($client->honoraires_mensuels) : null, 'Compte comptable' => $client->compte_comptable, 'Capital' => $client->capital > 0 ? tnd($client->capital) : null, 'Nombre de parts' => $client->nb_parts, 'Valeur nominale' => $client->valeur_nominale ? tnd($client->valeur_nominale) : null]),
                $bloc('Gérant', ['Nom' => trim((\App\Models\Client::CIVILITES[$client->gerant_civilite] ?? '').' '.$client->gerant_nom) ?: null, 'Nationalité' => $client->gerant_nationalite, 'CIN' => trim($client->gerant_cin.($client->gerant_cin_date ? ' du '.dfr($client->gerant_cin_date) : '').($client->gerant_cin_lieu ? ' à '.$client->gerant_cin_lieu : '')), 'Passeport' => $client->gerant_passeport ? trim($client->gerant_passeport.($client->gerant_passeport_date ? ' du '.dfr($client->gerant_passeport_date) : '').($client->gerant_passeport_validite ? ', valable jusqu’au '.dfr($client->gerant_passeport_validite) : '')) : null, 'Carte de séjour' => $client->gerant_carte_sejour, 'Né(e) le' => $client->gerant_naissance_date ? dfr($client->gerant_naissance_date).($client->gerant_naissance_lieu ? ' à '.$client->gerant_naissance_lieu : '') : null, 'Adresse' => $client->gerant_adresse, 'Nommé le' => $client->gerant_nomination_date ? dfr($client->gerant_nomination_date).($client->gerant_nomination_duree ? ' ('.$client->gerant_nomination_duree.')' : '') : null, 'Associé' => $client->gerant_associe === null ? null : ($client->gerant_associe ? 'Oui'.($client->gerant_parts ? ' — '.$client->gerant_parts.' parts' : '').($client->gerant_parts_de ? ' (n° '.$client->gerant_parts_de.' à '.$client->gerant_parts_a.')' : '').($client->gerant_apport ? ', apport en '.($client->gerant_apport === 'nature' ? 'nature' : 'numéraire') : '') : 'Non')]),
            ];
        @endphp
        <div class="grid gap-6 lg:grid-cols-3">
            @foreach($blocs as [$t, $rows])
                <div class="card">
                    <div class="card-h"><h2 class="card-t">{{ $t }}</h2></div>
                    <dl class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse($rows as $k => $v)
                            <div class="flex justify-between gap-4 px-5 py-2.5 text-sm"><dt class="text-slate-500">{{ $k }}</dt><dd class="text-right font-medium text-slate-800 dark:text-slate-100">{{ $v }}</dd></div>
                        @empty
                            <div class="px-5 py-4 text-sm text-slate-400">Non renseigné.</div>
                        @endforelse
                    </dl>
                </div>
            @endforeach
        </div>
        <div class="card mt-6">
            <div class="card-h"><h2 class="card-t">Cadre légal de l’activité</h2></div>
            @if($client->cadres_legaux)
                <table class="tbl"><thead><tr><th>Cadre légal</th><th>Déclaration d’investissement</th><th>Date de la déclaration</th><th>Entrée en activité</th></tr></thead><tbody>
                @foreach($client->cadres_legaux as $l)
                    <tr><td class="font-medium">{{ \App\Models\Client::libelleCadre($l['cadre'] ?? null) }}</td>
                        <td>{{ trim((\App\Models\Client::ORGANISMES_INVESTISSEMENT[$l['organisme'] ?? ''] ?? '').(! empty($l['numero']) ? ' n° '.$l['numero'] : '')) ?: '—' }}</td>
                        <td>{{ ! empty($l['date_declaration']) ? dfr($l['date_declaration']) : '—' }}</td>
                        <td>{{ ! empty($l['date_entree']) ? dfr($l['date_entree']) : '—' }}</td></tr>
                @endforeach
                </tbody></table>
            @else <x-empty icon="file-text" title="Cadre légal non renseigné"/> @endif
        </div>

        @unless($pp)
        <div class="card mt-6">
            <div class="card-h"><h2 class="card-t">Associés</h2></div>
            @if($client->associes->count())
                <table class="tbl"><thead><tr><th>Associé</th><th>Pièce d’identité</th><th>Nationalité</th><th>Apport</th><th class="text-right">Parts</th></tr></thead><tbody>
                @foreach($client->associes as $a)
                    <tr><td class="font-medium">{{ trim((\App\Models\Client::CIVILITES[$a->civilite] ?? '').' '.$a->nom) }}</td><td>{{ collect([$a->identifiant_unique ? 'Identifiant unique '.$a->identifiant_unique : null, $a->rcs ? 'RCS '.$a->rcs : null, $a->cin ? 'CIN '.$a->cin.($a->cin_date ? ' du '.dfr($a->cin_date) : '') : null, $a->passeport ? 'Passeport '.$a->passeport.($a->passeport_validite ? ' (valable jusqu’au '.dfr($a->passeport_validite).')' : '') : null, $a->carte_sejour ? 'Carte de séjour '.$a->carte_sejour : null])->filter()->implode(' · ') ?: '—' }}</td><td>{{ $a->nationalite ?: '—' }}</td><td>{{ $a->apport === 'nature' ? 'En nature' : 'En numéraire' }}</td><td class="num">{{ $a->parts }}{{ $a->parts_de ? ' (n° '.$a->parts_de.' à '.$a->parts_a.')' : '' }}</td></tr>
                @endforeach
                </tbody></table>
            @else <x-empty icon="users" title="Aucun associé renseigné"/> @endif
        </div>
        @endunless
        <div class="mt-6 flex justify-end"><x-confirm-delete :action="route('clients.destroy', $client)" :icon="false" label="Supprimer ce client" message="Supprimer ce client avec toutes ses échéances, factures, écritures et documents ?"/></div>

    @elseif($tab === 'echeances')
        <div class="card">
            <div class="card-h"><h2 class="card-t">Échéances du client</h2>
                <button class="btn-primary btn-sm" x-data @click="$dispatch('open-modal', 'ech')"><x-icon name="plus" class="h-3.5 w-3.5"/> Ajouter</button></div>
            @include('echeances._list', ['items' => $echeances, 'showClient' => false])
        </div>
        <x-modal name="ech" title="Nouvelle échéance">
            @include('echeances._form', ['clientId' => $client->id])
        </x-modal>

    @elseif($tab === 'exigences')
        <div class="card" x-data="{ e: {} }">
            <div class="card-h"><h2 class="card-t">Exigences et obligations</h2>
                <button class="btn-primary btn-sm" @click="e = {}; $dispatch('open-modal', 'exi')"><x-icon name="plus" class="h-3.5 w-3.5"/> Ajouter</button></div>
            @if($client->exigences->count())
            <div class="overflow-x-auto"><table class="tbl">
                <thead><tr><th>Exigence</th><th>Organisme</th><th>Périodicité</th><th>Date limite</th><th>Remarque</th><th></th></tr></thead><tbody>
                @foreach($client->exigences as $x)
                    <tr>
                        <td class="font-medium">{{ $x->intitule }}</td><td>{{ $x->organisme }}</td><td>{{ $x->periodicite }}</td>
                        <td>@if($x->date_limite)<span class="{{ $x->date_limite->isPast() ? 'badge-red' : 'badge-gray' }}">{{ dfr($x->date_limite) }}</span>@else — @endif</td>
                        <td class="max-w-xs text-xs text-slate-500">{{ $x->remarque }}</td>
                        <td class="whitespace-nowrap text-right">
                            <form method="POST" action="{{ route('clients.exigences.echeance', $x) }}" class="inline">@csrf<button class="btn-ghost btn-sm" title="Créer l’échéance correspondante"><x-icon name="calendar" class="h-3.5 w-3.5"/> Échéance</button></form>
                            <button class="btn-icon" @click="e = @js($x->only(['id','intitule','organisme','periodicite','remarque']) + ['date_limite' => optional($x->date_limite)->format('Y-m-d')]); $dispatch('open-modal', 'exi')"><x-icon name="edit" class="h-4 w-4"/></button>
                            <x-confirm-delete :action="route('clients.exigences.destroy', $x)"/>
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
            @else <x-empty icon="list" title="Aucune exigence">Recensez les obligations du client (TVA, CNSS, RNE, douane…).</x-empty> @endif

            <x-modal name="exi" title="Exigence">
                <form method="POST" :action="e.id ? '{{ url('exigences') }}/' + e.id : '{{ route('clients.exigences.store', $client) }}'" class="grid gap-4 md:grid-cols-2">
                    @csrf <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="md:col-span-2"><label class="label">Exigence *</label><input name="intitule" class="input" x-model="e.intitule" required placeholder="Déclaration mensuelle de TVA"></div>
                    <div><label class="label">Organisme</label><select name="organisme" class="input" x-model="e.organisme">@foreach(config('cabinet.ORGS') as $o)<option>{{ $o }}</option>@endforeach</select></div>
                    <div><label class="label">Périodicité</label><select name="periodicite" class="input" x-model="e.periodicite">@foreach(config('cabinet.PERS') as $o)<option>{{ $o }}</option>@endforeach</select></div>
                    <div><label class="label">Prochaine date limite</label><input type="date" name="date_limite" class="input" x-model="e.date_limite"></div>
                    <div class="md:col-span-2"><label class="label">Remarque</label><textarea name="remarque" class="input" rows="2" x-model="e.remarque"></textarea></div>
                    <div class="flex justify-end md:col-span-2"><button class="btn-primary">Enregistrer</button></div>
                </form>
            </x-modal>
        </div>

    @elseif($tab === 'factures')
        <div class="card">
            <div class="card-h"><h2 class="card-t">Factures et devis</h2><a href="{{ route('factures.create', ['client' => $client->id]) }}" class="btn-primary btn-sm"><x-icon name="plus" class="h-3.5 w-3.5"/> Nouvelle facture</a></div>
            @include('factures._table', ['items' => $factures])
        </div>

    @elseif($tab === 'documents')
        @php $base = $client->piecesDeBase(); $requises = $client->piecesRequises(); $have = $documents->pluck('categorie')->unique(); @endphp
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card">
                <div class="card-h"><h2 class="card-t">Pièces de base</h2><span class="badge-blue">{{ collect($requises)->filter(fn($c) => $have->contains($c))->count() }}/{{ count($requises) }}</span></div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($base as $c => $condition)
                        <li class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm">
                            <span>{{ $c }}@if($condition)<span class="block text-xs text-slate-400">{{ $condition }}</span>@endif</span>
                            @if($have->contains($c))<x-icon name="check" class="h-4 w-4 text-emerald-600"/>
                            @elseif(in_array($c, $requises, true))<span class="badge-amber">Manquante</span>
                            @else<span class="badge-gray" title="À fournir seulement si le cas se présente">Si applicable</span>@endif
                        </li>
                        @if($c === 'Liste des associés')
                            <li class="flex flex-wrap items-center gap-2 bg-slate-50/60 px-5 py-2 text-xs dark:bg-slate-800/30">
                                <form method="POST" action="{{ route('clients.liste-associes.ged', $client) }}">@csrf
                                    <button class="btn-secondary btn-sm" title="Excel et PDF, d’après les associés de la fiche">{{ $have->contains($c) ? 'Régénérer' : 'Générer' }} d’après la fiche</button></form>
                                <a href="{{ route('clients.liste-associes', [$client, 'format' => 'xlsx']) }}" class="font-medium text-brand-700 hover:underline">Excel</a>
                                <a href="{{ route('clients.liste-associes', [$client, 'format' => 'pdf']) }}" class="font-medium text-brand-700 hover:underline">PDF</a>
                            </li>
                        @endif
                    @endforeach
                </ul>
                <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 dark:border-slate-800">Une pièce conditionnelle devient requise quand la fiche l’indique (matricule CNSS employeur ou indépendant, déclaration d’investissement, régime d’export, société non résidente).</p>
            </div>
            <div class="card lg:col-span-2">
                <div class="card-h"><h2 class="card-t">Documents du dossier</h2><a href="{{ route('ged.index', ['client' => $client->id]) }}" class="btn-primary btn-sm"><x-icon name="upload" class="h-3.5 w-3.5"/> Ajouter dans la GED</a></div>
                @forelse($documents as $doc)
                    <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                        <x-icon name="file-text" class="h-5 w-5 text-brand-600"/>
                        <div class="min-w-0 flex-1"><div class="truncate font-medium">{{ $doc->nom }}
                            @if($doc->envoiClient())<span class="badge-amber">Envoyé par le client</span>@elseif($doc->partage)<span class="badge-blue">Partagé</span>@endif</div>
                            <div class="text-xs text-slate-500">{{ $doc->categorie }} · {{ dfr($doc->date) }}</div></div>
                        <a href="{{ route('ged.download', $doc) }}" class="btn-icon"><x-icon name="download" class="h-4 w-4"/></a>
                    </div>
                @empty <x-empty icon="folder" title="Aucun document"/> @endforelse
            </div>
        </div>

    @elseif($tab === 'actes')
        <div class="card">
            <div class="card-h"><h2 class="card-t">Statuts, PV et contrats</h2><a href="{{ route('actes.create', $client) }}" class="btn-primary btn-sm"><x-icon name="plus" class="h-3.5 w-3.5"/> Nouveau document</a></div>
            @forelse($actes as $a)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                    <x-icon name="file-text" class="h-5 w-5 text-brand-600"/>
                    <a href="{{ route('actes.edit', $a) }}" class="min-w-0 flex-1"><div class="truncate font-medium hover:text-brand-700">{{ $a->titre }}</div><div class="text-xs text-slate-500">{{ dfr($a->date) }} · modifié {{ $a->updated_at->diffForHumans() }}</div></a>
                    <x-confirm-delete :action="route('actes.destroy', $a)"/>
                </div>
            @empty <x-empty icon="file-text" title="Aucun acte rédigé">Générez des statuts SARL/SUARL, des PV d’assemblée ou un contrat de cession de parts.</x-empty> @endforelse
        </div>

    @elseif($tab === 'espace')
        @php
            $chef = auth()->user()->estChef();
            $recus = $documents->filter->envoiClient();
            $partages = $documents->filter(fn ($d) => $d->partage && ! $d->envoiClient());
        @endphp
        <div class="grid gap-6 lg:grid-cols-5">
            <div class="space-y-6 lg:col-span-2">
                <div class="card">
                    <div class="card-h"><h2 class="card-t">Accès à l’espace client</h2><span class="badge-gray">{{ $acces->count() }}</span></div>
                    @forelse($acces as $a)
                        <div class="border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                            <div class="flex items-center gap-2">
                                <div class="min-w-0 flex-1"><div class="truncate font-medium">{{ $a->name }}</div><div class="truncate text-xs text-slate-500">{{ $a->email }}</div></div>
                                @php $st = $a->statut(); @endphp
                                <span class="{{ ['Actif' => 'badge-green', 'Invitation envoyée' => 'badge-blue', 'Invitation expirée' => 'badge-amber'][$st] ?? 'badge-gray' }}">{{ $st }}</span>
                            </div>
                            <div class="mt-1 text-xs text-slate-400">{{ $a->derniere_connexion_at ? 'Dernière connexion '.$a->derniere_connexion_at->diffForHumans() : 'Jamais connecté' }}</div>
                            @if($chef)
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @if($a->actif)
                                        <form method="POST" action="{{ route('echanges.acces.lien', $a) }}">@csrf<button class="btn-secondary btn-sm">{{ $a->estActive() ? 'Envoyer un lien de mot de passe' : 'Renvoyer l’invitation' }}</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('echanges.acces.basculer', $a) }}">@csrf @method('PATCH')<button class="btn-secondary btn-sm">{{ $a->actif ? 'Désactiver' : 'Réactiver' }}</button></form>
                                    <x-confirm-delete :action="route('echanges.acces.destroy', $a)" :icon="false" label="Supprimer" message="Supprimer cet accès ? Les documents déjà envoyés restent dans la GED."/>
                                </div>
                            @endif
                        </div>
                    @empty
                        <x-empty icon="users" title="Aucun accès">Invitez le client : il recevra un e-mail pour choisir son mot de passe et accéder à son espace.</x-empty>
                    @endforelse
                    @if($chef)
                        <form method="POST" action="{{ route('echanges.acces.store', $client) }}" class="grid gap-3 border-t border-slate-100 p-5 dark:border-slate-800">@csrf
                            <div class="text-sm font-semibold">Inviter une personne</div>
                            <x-field label="Nom" name="name" :value="$acces->isEmpty() ? ($client->gerant_nom ?: ($client->estPhysique() ? $client->nom : '')) : ''" required/>
                            <x-field label="Adresse e-mail" name="email" type="email" :value="$acces->isEmpty() ? $client->email : ''" required/>
                            <div class="flex justify-end"><button class="btn-primary btn-sm"><x-icon name="plus" class="h-3.5 w-3.5"/> Envoyer l’invitation</button></div>
                        </form>
                    @else
                        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Les accès sont gérés par le chef comptable.</p>
                    @endif
                </div>
            </div>

            <div class="space-y-6 lg:col-span-3">
                <div class="card">
                    <div class="card-h"><h2 class="card-t">Partager des documents avec le client</h2></div>
                    <form method="POST" action="{{ route('echanges.partager', $client) }}" enctype="multipart/form-data" class="card-b grid gap-4 md:grid-cols-2">@csrf
                        <div><label class="label">Catégorie (GED)</label><select name="categorie" class="input">@foreach(config('cabinet.GC') as $g)<option @selected($g === 'États financiers et déclarations')>{{ $g }}</option>@endforeach</select></div>
                        <x-field label="Date du document" name="date" type="date" :value="now()->format('Y-m-d')"/>
                        <x-field label="Note pour le client (facultatif)" name="description" span="md:col-span-2"/>
                        <div class="md:col-span-2"><label class="label">Fichiers (20 Mo max. chacun)</label><input type="file" name="fichiers[]" multiple required class="input"></div>
                        <div class="flex items-center justify-between gap-3 md:col-span-2">
                            <p class="text-xs text-slate-500">Classés dans la GED du dossier et visibles dans l’espace client{{ $acces->where('actif', true)->filter->estActive()->isNotEmpty() ? ' ; le client est prévenu par e-mail' : '' }}.</p>
                            <button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Partager</button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-h"><h2 class="card-t">Reçus du client</h2><span class="badge-amber">{{ $recus->whereNull('traite_at')->count() }} à traiter</span></div>
                    @forelse($recus as $d)
                        <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $d->nom }}</div>
                                <div class="text-xs text-slate-500">{{ $d->categorie }} · {{ $d->created_at->format('d/m/Y H:i') }}{{ $d->deposant ? ' · '.$d->deposant->name : '' }}</div>
                                @if($d->description)<div class="text-xs text-slate-600 dark:text-slate-400">« {{ $d->description }} »</div>@endif
                            </div>
                            <a href="{{ route('ged.download', $d) }}" class="btn-icon" title="Télécharger"><x-icon name="download" class="h-4 w-4"/></a>
                            <form method="POST" action="{{ route('echanges.traiter', $d) }}">@csrf @method('PATCH')
                                @if($d->traite_at)<button class="badge-green" title="Remettre à traiter"><x-icon name="check" class="h-3 w-3"/> Traité</button>
                                @else<button class="btn-primary btn-sm">Marquer traité</button>@endif
                            </form>
                        </div>
                    @empty <x-empty icon="upload" title="Rien reçu pour le moment"/> @endforelse
                </div>

                <div class="card">
                    <div class="card-h"><h2 class="card-t">Partagés avec le client</h2><a href="{{ route('ged.index', ['client' => $client->id]) }}" class="btn-secondary btn-sm">Partager depuis la GED</a></div>
                    @forelse($partages as $d)
                        <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $d->nom }}</div>
                                <div class="text-xs text-slate-500">{{ $d->categorie }} · partagé le {{ optional($d->partage_at)->format('d/m/Y') }}</div>
                            </div>
                            @if($d->consulte_at)<span class="badge-green" title="Téléchargé le {{ $d->consulte_at->format('d/m/Y H:i') }}">Ouvert</span>@else<span class="badge-gray">Non ouvert</span>@endif
                            <form method="POST" action="{{ route('echanges.partage', $d) }}" onsubmit="return confirm('Retirer ce document de l’espace client ? Il reste dans la GED.')">@csrf @method('PATCH')
                                <button class="btn-icon" title="Retirer de l’espace client"><x-icon name="x" class="h-4 w-4"/></button>
                            </form>
                        </div>
                    @empty <x-empty icon="folder" title="Aucun document partagé"/> @endforelse
                </div>
            </div>
        </div>
    @endif
</x-layouts.app>
