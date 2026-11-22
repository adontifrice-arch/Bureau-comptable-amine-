<x-layouts.espace title="Mes documents" :u="$u" :client="$client">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Bonjour {{ $u->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Dossier <strong>{{ $client->nom }}</strong> · échangez vos documents avec {{ $cab['nom'] }}.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        {{-- Envoi au cabinet --}}
        <div class="card self-start lg:col-span-2">
            <div class="card-h"><h2 class="card-t">Envoyer des documents au cabinet</h2></div>
            @php $anciens = collect(old('lots', [[]]))->values()->map(fn ($l, $i) => ['id' => $i + 1, 't' => $l['type_piece'] ?? '', 'p' => $l['periode'] ?? '', 'n' => 0])->all(); @endphp
            <form method="POST" action="{{ route('espace.envoyer') }}" enctype="multipart/form-data" class="card-b space-y-4"
                  x-data="{ lots: @js($anciens), suivant: {{ count($anciens) + 1 }}, avecPeriode: @js(\App\Support\TypesPieces::AVEC_PERIODE),
                            get total() { return this.lots.reduce((s, l) => s + l.n, 0) } }">@csrf
                <p class="text-xs text-slate-500">Ajoutez un groupe par type de documents (factures d’achat, relevé bancaire, pointage…) : chaque groupe part directement au service concerné du cabinet.</p>
                <template x-for="(l, i) in lots" :key="l.id">
                    <div class="space-y-3 rounded-xl border border-slate-200 p-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase text-slate-400" x-text="'Groupe ' + (i + 1)"></span>
                            <button type="button" class="text-xs text-rose-600 hover:underline" x-show="lots.length > 1" @click="lots.splice(i, 1)">Retirer</button>
                        </div>
                        <div><label class="label">Type de documents</label>
                            <select :name="`lots[${i}][type_piece]`" class="input" required x-model="l.t">
                                <option value="">Choisissez…</option>
                                @foreach(\App\Support\TypesPieces::parDestination() as $groupe => $types)
                                    <optgroup label="{{ $groupe === 'GED (classement)' ? 'Autres documents' : $groupe }}">
                                        @foreach($types as $k => $lib)<option value="{{ $k }}">{{ $lib }}</option>@endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="avecPeriode.includes(l.t)"><label class="label">Mois concerné</label>
                            <input type="month" :name="`lots[${i}][periode]`" x-model="l.p" class="input"></div>
                        <div><label class="label">Fichiers</label>
                            <input type="file" :name="`lots[${i}][fichiers][]`" multiple required class="input" @change="l.n = $event.target.files.length">
                            <p class="mt-1 text-xs text-slate-400" x-show="l.n" x-text="l.n + ' fichier(s) choisi(s)'"></p>
                        </div>
                    </div>
                </template>
                <button type="button" class="btn-secondary w-full" x-show="lots.length < 10" @click="lots.push({ id: suivant++, t: '', p: '', n: 0 })">
                    <x-icon name="plus" class="h-4 w-4"/> Ajouter un autre type de documents</button>
                <p class="text-xs text-slate-400">PDF, images, Word, Excel, CSV ou ZIP · 20 Mo maximum par fichier · 40 fichiers au plus par envoi.</p>
                <x-field label="Message pour le cabinet (facultatif)" name="message" type="textarea" placeholder="Ex. : relevés de janvier à mars, il manque celui de la BIAT."/>
                <button class="btn-primary w-full"><x-icon name="upload" class="h-4 w-4"/> <span x-text="total > 1 ? 'Envoyer les ' + total + ' documents' : 'Envoyer'">Envoyer</span></button>
            </form>
        </div>

        <div class="space-y-6 lg:col-span-3">
            {{-- Documents du cabinet --}}
            <div class="card">
                <div class="card-h"><h2 class="card-t">Documents mis à disposition par le cabinet</h2><span class="badge-blue">{{ $partages->count() }}</span></div>
                @forelse($partages as $d)
                    <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0">
                        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-[10px] font-bold uppercase text-brand-700">{{ pathinfo($d->nom, PATHINFO_EXTENSION) ?: 'doc' }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ $d->nom }} @unless($d->consulte_at)<span class="badge-green">Nouveau</span>@endunless</div>
                            <div class="text-xs text-slate-500">{{ $d->categorie }} · {{ dfr($d->date) }} · {{ $d->taillePourAffichage() }}</div>
                            @if($d->description)<div class="text-xs text-slate-400">{{ $d->description }}</div>@endif
                        </div>
                        <a href="{{ route('espace.telecharger', $d->id) }}" class="btn-secondary btn-sm"><x-icon name="download" class="h-3.5 w-3.5"/> Télécharger</a>
                    </div>
                @empty
                    <x-empty icon="folder" title="Aucun document pour le moment">Les documents que le cabinet partage avec vous (états financiers, déclarations, actes…) apparaîtront ici.</x-empty>
                @endforelse
            </div>

            {{-- Envois du client --}}
            <div class="card">
                <div class="card-h"><h2 class="card-t">Mes envois</h2><span class="badge-gray">{{ $envois->count() }}</span></div>
                @forelse($envois as $d)
                    <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0">
                        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-[10px] font-bold uppercase text-slate-600">{{ pathinfo($d->nom, PATHINFO_EXTENSION) ?: 'doc' }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ $d->nom }}</div>
                            <div class="text-xs text-slate-500">{{ $d->categorie }} · envoyé le {{ $d->created_at->format('d/m/Y à H:i') }}{{ $d->deposant && $d->deposant->id !== $u->id ? ' par '.$d->deposant->name : '' }} · {{ $d->taillePourAffichage() }}</div>
                        </div>
                        @if($d->traite_at)
                            <span class="badge-green" title="Pris en charge le {{ $d->traite_at->format('d/m/Y') }}"><x-icon name="check" class="h-3 w-3"/> Traité</span>
                        @else
                            <span class="badge-amber">Reçu</span>
                        @endif
                        <a href="{{ route('espace.telecharger', $d->id) }}" class="btn-icon" title="Télécharger"><x-icon name="download" class="h-4 w-4"/></a>
                        @unless($d->traite_at)
                            <form method="POST" action="{{ route('espace.retirer', $d->id) }}" onsubmit="return confirm('Retirer cet envoi ?')">@csrf @method('DELETE')
                                <button class="btn-icon text-rose-600" title="Retirer"><x-icon name="trash" class="h-4 w-4"/></button>
                            </form>
                        @endunless
                    </div>
                @empty
                    <x-empty icon="upload" title="Aucun envoi">Vos factures, relevés bancaires et autres pièces envoyés au cabinet apparaîtront ici.</x-empty>
                @endforelse
            </div>

            {{-- Mot de passe --}}
            <details class="card" @if($errors->has('current_password') || $errors->has('password')) open @endif>
                <summary class="card-h cursor-pointer"><h2 class="card-t">Changer mon mot de passe</h2></summary>
                <form method="POST" action="{{ route('espace.mot-de-passe') }}" class="card-b grid gap-4 sm:grid-cols-3">@csrf @method('PUT')
                    <x-field label="Mot de passe actuel" name="current_password" type="password" required autocomplete="current-password"/>
                    <x-field label="Nouveau mot de passe" name="password" type="password" required autocomplete="new-password"/>
                    <x-field label="Confirmation" name="password_confirmation" type="password" required autocomplete="new-password"/>
                    <div class="flex justify-end sm:col-span-3"><button class="btn-secondary">Enregistrer</button></div>
                </form>
            </details>
        </div>
    </div>
</x-layouts.espace>
