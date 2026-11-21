<x-layouts.espace title="Mes documents" :u="$u" :client="$client">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Bonjour {{ $u->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Dossier <strong>{{ $client->nom }}</strong> · échangez vos documents avec {{ $cab['nom'] }}.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        {{-- Envoi au cabinet --}}
        <div class="card self-start lg:col-span-2">
            <div class="card-h"><h2 class="card-t">Envoyer des documents au cabinet</h2></div>
            <form method="POST" action="{{ route('espace.envoyer') }}" enctype="multipart/form-data" class="card-b space-y-4"
                  x-data="{ t: @js(old('type_piece', '')), avecPeriode: @js(\App\Support\TypesPieces::AVEC_PERIODE) }">@csrf
                <div><label class="label" for="f-type">Type de documents</label>
                    <select id="f-type" name="type_piece" class="input" required x-model="t">
                        <option value="">Choisissez…</option>
                        @foreach(\App\Support\TypesPieces::parDestination() as $groupe => $types)
                            <optgroup label="{{ $groupe === 'GED (classement)' ? 'Autres documents' : $groupe }}">
                                @foreach($types as $k => $lib)<option value="{{ $k }}">{{ $lib }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Un envoi par type : vos documents sont transmis directement au service concerné du cabinet.</p>
                </div>
                <div x-show="avecPeriode.includes(t)" x-cloak><label class="label" for="f-periode">Mois concerné</label>
                    <input id="f-periode" type="month" name="periode" value="{{ old('periode') }}" class="input"></div>
                <div><label class="label" for="f-fichiers">Fichiers</label>
                    <input id="f-fichiers" type="file" name="fichiers[]" multiple required class="input">
                    <p class="mt-1 text-xs text-slate-400">PDF, images, Word, Excel, CSV ou ZIP · 20 Mo maximum par fichier, 20 fichiers par envoi.</p>
                </div>
                <x-field label="Message pour le cabinet (facultatif)" name="message" type="textarea" placeholder="Ex. : relevés de janvier à mars, il manque celui de la BIAT."/>
                <button class="btn-primary w-full"><x-icon name="upload" class="h-4 w-4"/> Envoyer</button>
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
