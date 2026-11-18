{{-- Fiche d'une écriture comptable : consultation, modification, suppression (vers « Pièces supprimées ») ou restauration --}}
@php
    $lignes = $e->lignes->map(fn ($l) => ['compte' => $l->compte, 'debit' => (float) $l->debit ?: '', 'credit' => (float) $l->credit ?: ''])->values();
    $gl = fn ($c) => route('compta.index', ['tab' => 'gl', 'annee' => $e->date->year, 'compte' => $c, 'exact' => 1]);
@endphp
<x-layouts.app title="Écriture {{ $e->journal }} du {{ dfr($e->date) }}">
    <script>window.PLAN = @js((object) $plan); window.URL_COMPTES = @js(route('compta.comptes.store'));</script>
    <x-page-header :title="'Écriture — '.$e->libelle" :subtitle="$e->client->nom.' · journal '.(\App\Models\Ecriture::journaux($e->client_id)[$e->journal] ?? $e->journal).' · '.dfr($e->date)">
        <a href="{{ $retour }}" class="btn-secondary"><x-icon name="arrow-left" class="h-4 w-4"/> Retour</a>
    </x-page-header>

    @if($e->trashed())
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            <x-icon name="trash" class="h-4 w-4"/>
            <span><b>Écriture supprimée</b> le {{ $e->deleted_at->format('d/m/Y à H:i') }}{{ $e->supprimePar ? ' par '.$e->supprimePar->name : '' }}{{ $e->motif_suppression ? ' — motif : '.$e->motif_suppression : '' }}. Elle n’entre plus dans le journal, la balance ni le grand livre.</span>
            @if($autorise)<form method="POST" action="{{ route('compta.ecritures.restaurer', $e) }}" class="ml-auto">@csrf<button class="btn-success btn-sm">Restaurer l’écriture</button></form>@endif
        </div>
    @elseif($e->source)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            Écriture générée depuis : <b>{{ $e->origine() }}</b>. La modifier ici ne modifie pas la pièce d’origine ; la supprimer remet la pièce en attente de comptabilisation.
        </div>
    @endif

    <div x-data="{ edition: {{ $errors->any() || session()->hasOldInput('lignes') ? 'true' : 'false' }}, l: @js(old('lignes', $lignes)), get d() { return this.l.reduce((s, x) => s + (+x.debit || 0), 0) }, get c() { return this.l.reduce((s, x) => s + (+x.credit || 0), 0) } }">
        {{-- Consultation --}}
        <div class="card" x-show="! edition">
            <div class="card-h">
                <div class="text-sm text-slate-500">
                    <span class="badge-blue">{{ $e->journal }}</span> n° {{ $e->id }} · saisie le {{ $e->created_at->format('d/m/Y à H:i') }}{{ $e->validePar ? ' · validée par '.$e->validePar->name : '' }}
                </div>
                @if($autorise && ! $e->trashed())
                    <div class="flex gap-2">
                        <button type="button" class="btn-secondary btn-sm" @click="edition = true"><x-icon name="edit" class="h-3.5 w-3.5"/> Modifier</button>
                        <form method="POST" action="{{ route('compta.ecritures.annuler', $e) }}" onsubmit="const m = prompt('Supprimer cette écriture ? Elle sera conservée dans « Pièces supprimées », d’où elle pourra être restaurée.\n\nMotif (facultatif) :'); if (m === null) return false; this.motif.value = m">@csrf
                            <input type="hidden" name="motif"><input type="hidden" name="depuis_fiche" value="1"><input type="hidden" name="retour" value="{{ $retour }}">
                            <button class="btn-secondary btn-sm !text-rose-600"><x-icon name="trash" class="h-3.5 w-3.5"/> Supprimer</button>
                        </form>
                    </div>
                @elseif(! $autorise)
                    <span class="text-xs text-slate-400" title="Le chef comptable peut vous y autoriser (Paramètres → Utilisateurs)"><x-icon name="lock" class="inline h-3.5 w-3.5"/> Modification et suppression réservées</span>
                @endif
            </div>
            <table class="tbl">
                <thead><tr><th class="w-32">Compte</th><th>Libellé du compte</th><th class="text-right">Débit</th><th class="text-right">Crédit</th></tr></thead>
                <tbody>
                @foreach($e->lignes as $l)
                    <tr><td class="font-mono text-xs"><a href="{{ $gl($l->compte) }}" class="hover:underline" title="Grand livre du compte">{{ $l->compte }}</a></td><td class="{{ $l->credit > 0 ? 'pl-8' : '' }} text-slate-600 dark:text-slate-300">{{ \App\Services\Compta::libelle($l->compte) }}</td><td class="num">{{ n3($l->debit) }}</td><td class="num">{{ n3($l->credit) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot><tr class="font-semibold"><td colspan="2">Totaux</td><td class="num">{{ tnd($e->lignes->sum('debit'), false) }}</td><td class="num">{{ tnd($e->lignes->sum('credit'), false) }}</td></tr></tfoot>
            </table>
        </div>

        {{-- Modification --}}
        @if($autorise && ! $e->trashed())
        <form method="POST" action="{{ route('compta.ecritures.update', $e) }}" class="card card-b space-y-4" x-show="edition" x-cloak>@csrf @method('PUT')
            <h2 class="card-t">Modifier l’écriture</h2>
            <div class="grid gap-4 md:grid-cols-4">
                <div><label class="label" for="e-date">Date</label><input id="e-date" type="date" name="date" class="input" value="{{ old('date', $e->date->format('Y-m-d')) }}" required></div>
                <div><label class="label" for="e-jnl">Journal</label><select id="e-jnl" name="journal" class="input">@foreach(\App\Models\Ecriture::journaux($e->client_id) as $k => $j)<option value="{{ $k }}" @selected(old('journal', $e->journal) === $k)>{{ $j }}</option>@endforeach</select></div>
                <div class="md:col-span-2"><label class="label" for="e-lib">Libellé</label><input id="e-lib" name="libelle" class="input" value="{{ old('libelle', $e->libelle) }}" required maxlength="255"></div>
            </div>
            <datalist id="cpts-ecr" data-plan>@foreach($plan as $n => $lib)<option value="{{ $n }}">{{ $lib }}</option>@endforeach</datalist>
            <div>
                <div class="mb-1 grid grid-cols-12 gap-2 text-xs font-medium text-slate-500"><div class="col-span-6">Compte</div><div class="col-span-2 text-right">Débit</div><div class="col-span-2 text-right">Crédit</div></div>
                <template x-for="(x, i) in l" :key="i">
                    <div class="mb-2 grid grid-cols-12 items-start gap-2">
                        <div class="col-span-6"><input class="input font-mono" list="cpts-ecr" :name="`lignes[${i}][compte]`" x-model="x.compte" data-compte autocomplete="off" aria-label="Compte"><p data-compte-libelle class="mt-0.5 truncate text-xs text-slate-400" x-effect="$el.textContent = window.libelleCompte(x.compte)"></p></div>
                        <div class="col-span-2"><input type="number" step="0.001" min="0" class="input text-right" :name="`lignes[${i}][debit]`" x-model="x.debit" aria-label="Débit"></div>
                        <div class="col-span-2"><input type="number" step="0.001" min="0" class="input text-right" :name="`lignes[${i}][credit]`" x-model="x.credit" aria-label="Crédit"></div>
                        <div class="col-span-2"><button type="button" class="btn-icon" x-show="l.length > 2" @click="l.splice(i, 1)" title="Retirer la ligne"><x-icon name="trash" class="h-4 w-4"/></button></div>
                    </div>
                </template>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" class="btn-secondary btn-sm" @click="l.push({ compte: '', debit: '', credit: '' })">+ Ligne</button>
                    <div class="text-sm">Débit <b class="font-mono" x-text="tnd(d)"></b> · Crédit <b class="font-mono" x-text="tnd(c)"></b>
                        <span x-show="Math.abs(d - c) > 0.0005" class="badge-red ml-2">Déséquilibrée</span><span x-show="d > 0 && Math.abs(d - c) <= 0.0005" class="badge-green ml-2">Équilibrée</span></div>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="edition = false">Annuler</button>
                <button class="btn-primary" :disabled="Math.abs(d - c) > 0.0005">Enregistrer les modifications</button>
            </div>
        </form>
        @endif
    </div>
</x-layouts.app>
