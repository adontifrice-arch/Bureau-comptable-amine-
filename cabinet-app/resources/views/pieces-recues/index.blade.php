@use('App\Support\TypesPieces')
@php
    $titres = ['compta' => 'Comptabilité', 'immobilisations' => 'Immobilisations', 'paie' => 'Paie'];
    $retour = ['compta' => route('compta.index'), 'immobilisations' => route('immobilisations.index'), 'paie' => route('paie.index')][$destination];
    $valideur = auth()->user()->peutValider();
    $typesModule = collect(TypesPieces::TYPES)->filter(fn ($t) => $t[1] === $destination)->map(fn ($t) => $t[0]);
@endphp
<x-layouts.app :title="'Documents reçus — '.$titres[$destination]">
    <x-page-header :title="'Documents reçus des clients — '.$titres[$destination]" :back="$retour"
        :subtitle="$destination === 'paie' ? 'Pointages, congés, acomptes et prêts importés dans la paie ; documents des salariés classés dans leur fiche.'
            : ($destination === 'immobilisations' ? 'Factures d’immobilisations et contrats de leasing lus automatiquement.' : 'Pièces comptables lues automatiquement et proposées dans leur journal, à vérifier et valider.')">
        <x-client-picker :clients="$clients" :current="$client" :route="route($route)" :params="['vue' => $vue]"/>
    </x-page-header>

    @unless($ia)
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
            <div>La lecture automatique (OCR par l’IA) n’est pas configurée : les documents arrivent ici « à saisir ». Ajoutez <span class="font-mono">ANTHROPIC_API_KEY</span> dans le fichier <span class="font-mono">.env</span> pour qu’ils soient lus et proposés automatiquement.
                Les relevés bancaires Excel / CSV et les pointages Excel sont déjà importés sans IA.</div>
        </div>
    @endunless

    <div class="mb-4 flex gap-2">
        <a href="{{ route($route, ['vue' => 'en_cours']) }}" class="{{ $vue === 'en_cours' ? 'btn-primary' : 'btn-secondary' }} btn-sm">À traiter</a>
        <a href="{{ route($route, ['vue' => 'tous']) }}" class="{{ $vue === 'tous' ? 'btn-primary' : 'btn-secondary' }} btn-sm">Tous</a>
    </div>

    {{-- Écritures proposées (traites, chèques, caisse, opérations diverses) --}}
    @if($destination === 'compta' && $proposees->isNotEmpty())
        <div class="card mb-6">
            <div class="card-h"><h2 class="card-t">Écritures proposées à valider</h2><span class="badge-amber">{{ $proposees->count() }}</span></div>
            @foreach($proposees as $ep)
                <form method="POST" action="{{ route('compta.proposees.update', $ep) }}" class="border-b border-slate-100 p-5 last:border-0 dark:border-slate-800"
                      x-data="{ l: @js(array_values($ep->lignes)), get d() { return this.l.reduce((s, x) => s + (+x.debit || 0), 0) }, get c() { return this.l.reduce((s, x) => s + (+x.credit || 0), 0) } }">@csrf @method('PUT')
                    <div class="mb-3 grid gap-3 md:grid-cols-6">
                        <input type="date" name="date" value="{{ $ep->date->format('Y-m-d') }}" class="input-sm">
                        <select name="journal" class="input-sm">@foreach($journaux as $k => $lib)<option value="{{ $k }}" @selected($ep->journal === $k)>{{ $k }} — {{ $lib }}</option>@endforeach</select>
                        <input name="libelle" value="{{ $ep->libelle }}" class="input-sm md:col-span-3">
                        @if($ep->document)<a href="{{ route('ged.download', $ep->document) }}" class="btn-ghost btn-sm justify-self-end" title="Voir la pièce"><x-icon name="eye" class="h-4 w-4"/> Pièce</a>@endif
                    </div>
                    <table class="tbl">
                        <thead><tr><th>Compte</th><th>Libellé</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th></th></tr></thead>
                        <tbody>
                            <template x-for="(x, i) in l" :key="i">
                                <tr>
                                    <td class="!py-1.5"><input :name="`lignes[${i}][compte]`" x-model="x.compte" class="input-sm w-28 font-mono"></td>
                                    <td class="!py-1.5"><input :name="`lignes[${i}][libelle]`" x-model="x.libelle" class="input-sm"></td>
                                    <td class="!py-1.5"><input :name="`lignes[${i}][debit]`" x-model="x.debit" type="number" step="0.001" min="0" class="input-sm text-right"></td>
                                    <td class="!py-1.5"><input :name="`lignes[${i}][credit]`" x-model="x.credit" type="number" step="0.001" min="0" class="input-sm text-right"></td>
                                    <td class="!py-1.5"><button type="button" class="btn-icon" @click="l.splice(i, 1)" title="Retirer la ligne"><x-icon name="x" class="h-4 w-4"/></button></td>
                                </tr>
                            </template>
                            <tr><td colspan="2"><button type="button" class="btn-ghost btn-sm" @click="l.push({ compte: '', libelle: '', debit: 0, credit: 0 })"><x-icon name="plus" class="h-3.5 w-3.5"/> Ligne</button></td>
                                <td class="text-right font-mono" x-text="d.toFixed(3)"></td><td class="text-right font-mono" x-text="c.toFixed(3)"></td>
                                <td><span x-show="Math.abs(d - c) > 0.0005" class="badge-red">Écart</span></td></tr>
                        </tbody>
                    </table>
                    @if($ep->anomalies)<ul class="mt-2 list-disc pl-5 text-xs text-amber-700">@foreach($ep->anomalies as $a)<li>{{ $a }}</li>@endforeach</ul>@endif
                    <div class="mt-3 flex flex-wrap justify-end gap-2">
                        <button class="btn-secondary btn-sm">Enregistrer les corrections</button>
                        @if($valideur)
                            <button class="btn-success btn-sm" form="ep-valider-{{ $ep->id }}" title="Enregistrez d’abord vos corrections">Valider et passer au journal</button>
                            <button class="btn-secondary btn-sm !text-rose-600" form="ep-rejeter-{{ $ep->id }}" onclick="return confirm('Rejeter cette écriture proposée ?')">Rejeter</button>
                        @endif
                    </div>
                </form>
                @if($valideur)
                    <form id="ep-valider-{{ $ep->id }}" method="POST" action="{{ route('compta.proposees.valider', $ep) }}" class="hidden">@csrf</form>
                    <form id="ep-rejeter-{{ $ep->id }}" method="POST" action="{{ route('compta.proposees.rejeter', $ep) }}" class="hidden">@csrf</form>
                @endif
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-h"><h2 class="card-t">Documents</h2><span class="badge-gray">{{ $docs->total() }}</span></div>
        @forelse($docs as $d)
            @php
                $r = (array) $d->resultat;
                [$libStatut, $badge] = TypesPieces::STATUTS[$d->traitement] ?? ['Classé', 'badge-gray'];
                $liens = [];
                if (! empty($r['factures_recues'])) $liens[] = ['Factures d’achat', route('compta.index', ['tab' => 'ach', 'client' => $client->id])];
                if (! empty($r['factures_ventes'])) $liens[] = ['Factures de vente', route('compta.index', ['tab' => 'vte', 'client' => $client->id])];
                if (! empty($r['releve'])) $liens[] = ['Relevé bancaire', route('compta.index', ['tab' => 'bq', 'releve' => $r['releve'], 'client' => $client->id])];
                if (! empty($r['immobilisations'])) $liens[] = ['Registre des immobilisations', route('immobilisations.index', ['client' => $client->id])];
                if (! empty($r['mois'])) $liens[] = ['Paie de '.mois_fr($r['mois']), route('paie.index', ['mois' => $r['mois'], 'client' => $client->id])];
                if (! empty($r['salarie'])) $liens[] = ['Fiche du salarié', route('paie.employes.edit', $r['salarie'])];
            @endphp
            <div class="border-b border-slate-100 px-5 py-4 last:border-0 dark:border-slate-800">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-[10px] font-bold uppercase text-brand-700 dark:bg-brand-500/10">{{ pathinfo($d->nom, PATHINFO_EXTENSION) ?: 'doc' }}</div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $d->nom }} <span class="{{ $badge }}">{{ $libStatut }}</span></div>
                        <div class="text-xs text-slate-500">{{ TypesPieces::libelle($d->type_piece) }}{{ $d->periode ? ' · '.mois_fr($d->periode) : '' }} · reçu le {{ $d->created_at->format('d/m/Y H:i') }}{{ $d->deposant ? ' de '.$d->deposant->name : '' }}</div>
                        @if($d->description)<div class="text-xs text-slate-600 dark:text-slate-400">« {{ $d->description }} »</div>@endif
                        @if($d->traitement_message)<div class="mt-1 text-sm">{{ $d->traitement_message }}</div>@endif
                        @if(! empty($r['anomalies']))<ul class="mt-1 list-disc pl-5 text-xs text-amber-700">@foreach(array_slice((array) $r['anomalies'], 0, 6) as $a)<li>{{ $a }}</li>@endforeach</ul>@endif
                        @if($liens)<div class="mt-1 flex flex-wrap gap-3 text-xs">@foreach($liens as [$l, $u])<a href="{{ $u }}" class="font-medium text-brand-700 hover:underline">{{ $l }} →</a>@endforeach</div>@endif
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-1">
                        <a href="{{ route('ged.download', $d) }}" class="btn-icon" title="Télécharger"><x-icon name="download" class="h-4 w-4"/></a>
                        @if(in_array($d->traitement, ['a_saisir', 'erreur'], true))
                            @if($ia)<form method="POST" action="{{ route('pieces-recues.relancer', $d) }}">@csrf<button class="btn-secondary btn-sm">Relire</button></form>@endif
                            <form method="POST" action="{{ route('pieces-recues.saisi', $d) }}">@csrf<button class="btn-secondary btn-sm" title="Je l’ai saisi à la main">Marquer saisi</button></form>
                        @endif
                        <form method="POST" action="{{ route('pieces-recues.reclasser', $d) }}" class="flex items-center gap-1" x-data="{ o: false }">@csrf
                            <button type="button" class="btn-ghost btn-sm" @click="o = !o" x-show="!o">Reclasser</button>
                            <select name="type_piece" class="input-sm w-56" x-show="o" x-cloak>@foreach(TypesPieces::parDestination() as $g => $ts)<optgroup label="{{ $g }}">@foreach($ts as $k => $lib)<option value="{{ $k }}" @selected($d->type_piece === $k)>{{ $lib }}</option>@endforeach</optgroup>@endforeach</select>
                            <button class="btn-secondary btn-sm" x-show="o" x-cloak>OK</button>
                        </form>
                    </div>
                </div>

                {{-- Paie : lignes de congés, acomptes ou prêts à importer --}}
                @if($destination === 'paie' && $d->traitement === 'propose' && ! empty($r['lignes']) && in_array($d->type_piece, ['etat_conges', 'etat_acomptes_prets'], true))
                    <form method="POST" action="{{ route('paie.recus.importer', $d) }}" class="mt-3 overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">@csrf
                        <table class="tbl">
                            <thead><tr><th></th><th>Salarié lu</th><th>Salarié du dossier</th><th>Détail</th></tr></thead>
                            <tbody>
                            @foreach($r['lignes'] as $i => $l)
                                @php $match = \App\Http\Controllers\PaieDossierController::trouverSalarie($salaries, $l['matricule'] ?? null, $l['cin'] ?? null, $l['nom'] ?? null); @endphp
                                <tr class="{{ ! empty($l['importe']) ? 'opacity-50' : '' }}">
                                    <td>@if(empty($l['importe']))<input type="checkbox" name="importer[]" value="{{ $i }}" @checked($match) class="rounded border-slate-300 text-brand-600">@else<x-icon name="check" class="h-4 w-4 text-emerald-600"/>@endif</td>
                                    <td class="text-xs">{{ $l['nom'] ?? '' }}{{ ! empty($l['matricule']) ? ' · mat. '.$l['matricule'] : '' }}{{ ! empty($l['cin']) ? ' · CIN '.$l['cin'] : '' }}</td>
                                    <td>@if(empty($l['importe']))<select name="salarie[{{ $i }}]" class="input-sm w-48"><option value="">— Choisir —</option>@foreach($salaries as $s)<option value="{{ $s->id }}" @selected($match?->id === $s->id)>{{ $s->nomComplet() }}</option>@endforeach</select>@endif</td>
                                    <td class="text-xs">
                                        @if($d->type_piece === 'etat_conges')
                                            {{ \App\Models\Conge::TYPES[$l['type'] ?? 'paye'] ?? 'Congé' }} du {{ $l['du'] ?? '?' }} au {{ $l['au'] ?? '?' }}{{ ! empty($l['jours']) ? ' ('.$l['jours'].' j)' : '' }}
                                        @else
                                            {{ ['acompte' => 'Acompte', 'pret_societe' => 'Prêt société', 'pret_cnss' => 'Prêt CNSS'][$l['nature'] ?? 'acompte'] ?? '' }} de {{ tnd($l['montant'] ?? 0) }} le {{ $l['date'] ?? '?' }}, retenue {{ $l['mois_retenue'] ?? '?' }}{{ ! empty($l['nb_echeances']) ? ', '.$l['nb_echeances'].' échéance(s)' : '' }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <div class="flex justify-end p-3"><button class="btn-primary btn-sm">Importer les lignes cochées dans la paie</button></div>
                    </form>
                @endif

                {{-- Paie : document d'un salarié à classer dans sa fiche --}}
                @if($destination === 'paie' && in_array($d->traitement, ['a_saisir', 'erreur'], true) && in_array($d->type_piece, ['contrat_travail', 'piece_identite', 'document_salarie'], true))
                    <form method="POST" action="{{ route('paie.recus.classer', $d) }}" class="mt-3 flex flex-wrap items-center gap-2">@csrf
                        <select name="salarie_id" class="input-sm w-56" required><option value="">— Salarié —</option>@foreach($salaries as $s)<option value="{{ $s->id }}">{{ $s->nomComplet() }}</option>@endforeach</select>
                        <select name="type" class="input-sm w-44">@foreach(\App\Models\SalarieDocument::TYPES as $k => $lib)@continue($k === 'photo')<option value="{{ $k }}" @selected($k === \App\Services\TraitementPieces::typeDocumentSalarie($d->type_piece))>{{ $lib }}</option>@endforeach</select>
                        <button class="btn-primary btn-sm">Classer dans la fiche</button>
                    </form>
                @endif
            </div>
        @empty
            <x-empty icon="folder" :title="$vue === 'en_cours' ? 'Aucun document à traiter' : 'Aucun document reçu'">
                Les documents de type {{ $typesModule->map(fn ($t) => mb_strtolower($t))->implode(', ') }} envoyés par le client depuis son espace arrivent ici.
            </x-empty>
        @endforelse
        @if($docs->hasPages())<div class="p-4">{{ $docs->links() }}</div>@endif
    </div>
</x-layouts.app>
