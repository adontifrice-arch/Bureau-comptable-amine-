{{-- Menu Rubrique : rubriques classées par catégorie (à gauche) et fiche rubrique (à droite), comme l'écran « Ajout rubrique ». --}}
@php
    $lock = $paie && $paie->statut !== 'brouillon';
    $R0 = $rubrique;
    $form = old('_form') === 'rubrique';
    $v = fn ($c) => old($c, $R0->$c);
    $coche = fn ($c) => $form ? (bool) old($c) : (bool) $R0->$c;
    $profilsCoches = $form ? (array) old('profils', []) : (array) $R0->profils;
    $tousProfils = array_values(array_unique([...$profils, ...$profilsCoches]));
    $autres = $rubriques->reject(fn ($x) => $x->id === $R0->id);
    $notes = $autres->filter->note;
    $nombre = fn ($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float) $x, 3, '.', ''), '0'), '.');
    $colonnes = [
        ['prorata_travail', null, 'prorata_jours_pris', 'prorata_heures_pris', 'conditionnee'],
        ['non_cotisable', 'non_imposable', 'ticket_cadeau', 'ticket_restaurant', 'a_deduire'],
        ['heures_sup', 'jours_sup', 'heures_nuit', 'note', 'valeur_net'],
    ];
    $aide = [
        'prorata_travail' => 'Montant proratisé sur les jours (ou heures) travaillés du mois.',
        'prorata_jours_pris' => 'Enregistré sur la rubrique ; appliqué avec le module Congés.',
        'prorata_heures_pris' => 'Enregistré sur la rubrique ; appliqué avec le module Congés.',
        'conditionnee' => 'Versée seulement si le mois est complet.',
        'non_cotisable' => 'Hors assiette CNSS. Une retenue non cotisable est déduite du net.',
        'non_imposable' => 'Hors assiette de l’IRPP et de la CSS.',
        'ticket_cadeau' => 'Entre dans le brut mais n’est pas versé en espèces.',
        'ticket_restaurant' => 'Entre dans le brut mais n’est pas versé en espèces.',
        'a_deduire' => 'Retenue : déduite du brut si cotisable, sinon du net (compte 427).',
        'heures_sup' => 'Saisie en heures, valorisée au taux horaire.',
        'jours_sup' => 'Saisie en jours, valorisée au taux journalier.',
        'heures_nuit' => 'Saisie en heures, valorisée au taux horaire.',
        'note' => 'Quantité seulement (ex. nombre de repas), sans montant.',
        'valeur_net' => 'Le montant indiqué est le net à verser ; le brut est recalculé.',
        'depend_note' => 'Le montant est multiplié par la quantité de la note.',
    ];
@endphp
<x-layouts.app title="Paie — Rubriques">
    <x-page-header title="Paie" :subtitle="$client->nom.' · rubriques de paie'">
        <x-client-picker :clients="$clients" :current="$client" :route="route('paie.rubriques')" :params="['mois' => $mois]"/>
    </x-page-header>
    @include('paie._barre')

    <div class="grid gap-6 lg:grid-cols-[20rem_1fr]">
        {{-- Rubriques par catégorie --}}
        <div class="card self-start overflow-hidden">
            <div class="card-h"><h2 class="card-t">Rubriques</h2>
                <a href="{{ route('paie.rubriques', ['mois' => $mois]) }}" class="btn-ghost btn-sm"><x-icon name="plus" class="h-4 w-4"/> Nouvelle</a></div>
            @foreach(\App\Models\PaieRubrique::CATEGORIES as $cat => $libelle)
                @php $liste = $rubriques->where('categorie', $cat); @endphp
                <div x-data="{ o: {{ $liste->contains('id', $R0->id) || $R0->categorie === $cat || $liste->isNotEmpty() ? 'true' : 'false' }} }" class="border-t border-slate-100 dark:border-slate-800">
                    <button type="button" @click="o = !o" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                        <span class="flex h-4 w-4 items-center justify-center rounded border border-slate-300 text-[10px] text-slate-500" x-text="o ? '−' : '+'"></span>
                        <span class="flex-1">{{ $libelle }}</span><span class="badge-gray">{{ $liste->count() }}</span>
                    </button>
                    <div x-show="o" x-cloak class="max-h-[28rem] overflow-y-auto pb-1">
                        @foreach($liste as $x)
                            <a href="{{ route('paie.rubriques', ['r' => $x->id, 'mois' => $mois]) }}"
                               class="block px-4 py-1.5 pl-10 text-sm {{ $x->id === $R0->id ? 'bg-brand-50 font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                                <span class="font-mono text-xs text-slate-400">{{ $x->code }}</span> {{ $x->nom }}
                                <div class="text-xs text-slate-400">{{ $x->note ? 'Note' : $x->libelleValeur($rubriques) }}{{ $x->a_deduire ? ' · à déduire' : '' }}{{ $x->profils ? ' · '.implode(', ', $x->profils) : '' }}</div>
                            </a>
                        @endforeach
                        <a href="{{ route('paie.rubriques', ['categorie' => $cat, 'mois' => $mois]) }}" class="block px-4 py-1.5 pl-10 text-xs text-brand-600 hover:underline">+ Ajouter dans « {{ $libelle }} »</a>
                        @if(isset(\App\Models\PaieRubrique::STANDARD[$cat]))
                            <form method="POST" action="{{ route('paie.rubriques.standard') }}" class="px-4 pb-1 pl-10">@csrf <input type="hidden" name="categorie" value="{{ $cat }}">
                                <button class="text-xs text-slate-500 hover:text-brand-600 hover:underline" title="Rajoute les rubriques de la liste standard absentes du dossier">↻ Compléter avec la liste standard ({{ count(\App\Models\PaieRubrique::STANDARD[$cat]) }})</button></form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Fiche rubrique --}}
        <form method="POST" action="{{ $R0->exists ? route('paie.rubriques.update', $R0) : route('paie.rubriques.store') }}" class="card"
              x-data="{ mode: @js($v('mode') ?? 'manuelle'), dn: {{ $coche('depend_note') ? 'true' : 'false' }} }">
            @csrf @if($R0->exists) @method('PUT') @endif
            <input type="hidden" name="_form" value="rubrique"><input type="hidden" name="mois" value="{{ $mois }}">
            <div class="card-h"><h2 class="card-t">{{ $R0->exists ? 'Rubrique '.$R0->code.' — '.$R0->nom : 'Nouvelle rubrique' }}</h2>
                <span class="text-xs text-rose-600">* Mention obligatoire</span></div>
            <div class="card-b space-y-5">
                <div class="grid gap-4 sm:grid-cols-[8rem_14rem_1fr]">
                    <x-field label="Code *" name="code" type="number" min="1" :value="$R0->code" required/>
                    <div><label class="label" for="f-categorie">Catégorie *</label>
                        <select id="f-categorie" name="categorie" class="input" required><option value=""></option>
                            @foreach(\App\Models\PaieRubrique::CATEGORIES as $c => $l)<option value="{{ $c }}" @selected($v('categorie') === $c)>{{ $l }}</option>@endforeach
                        </select>@error('categorie')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                    <x-field label="Nom *" name="nom" :value="$R0->nom" required maxlength="120"/>
                </div>

                <div><label class="label">Profil <span class="font-normal text-slate-400">— aucun coché : la rubrique s’applique à tous les employés</span></label>
                    <div class="grid gap-x-4 gap-y-1 rounded-lg border border-slate-200 p-3 text-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-slate-700">
                        @foreach($tousProfils as $p)
                            <label class="flex items-center gap-2"><input type="checkbox" name="profils[]" value="{{ $p }}" @checked(in_array($p, $profilsCoches, true)) class="rounded border-slate-300"> {{ $p }}</label>
                        @endforeach
                    </div></div>

                <div class="grid gap-x-6 gap-y-2 text-sm md:grid-cols-[1fr_1fr_1fr_9rem]">
                    @foreach($colonnes as $col)
                        <div class="space-y-2">
                            @foreach($col as $c)
                                @if($c === null)<div class="hidden h-5 md:block"></div>@continue @endif
                                <label class="flex items-start gap-2" title="{{ $aide[$c] }}"><input type="checkbox" name="{{ $c }}" value="1" @checked($coche($c)) class="mt-0.5 rounded border-slate-300">
                                    <span class="font-medium">{{ \App\Models\PaieRubrique::OPTIONS[$c] }}</span></label>
                            @endforeach
                        </div>
                    @endforeach
                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-2"><label for="f-nb_jours_ouv" class="font-medium" title="Jours du mois complet pour le prorata (vide : jours ouvrables du dossier)">Nbr J Ouv</label>
                            <input id="f-nb_jours_ouv" type="number" step="0.5" min="0" max="31" name="nb_jours_ouv" value="{{ $nombre($v('nb_jours_ouv')) }}" class="input-sm w-16 text-right"></div>
                        <div class="flex items-center justify-between gap-2"><label for="f-nb_heures_ouv" class="font-medium" title="Heures du mois complet pour le prorata (régime horaire)">Nbr H Ouv</label>
                            <input id="f-nb_heures_ouv" type="number" step="0.5" min="0" name="nb_heures_ouv" value="{{ $nombre($v('nb_heures_ouv')) }}" class="input-sm w-16 text-right"></div>
                    </div>
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <fieldset class="rounded-lg border border-slate-200 p-4 text-sm dark:border-slate-700">
                        <legend class="px-1 text-xs font-semibold text-slate-500">Valeur de la rubrique</legend>
                        <div class="space-y-3">
                            <label class="flex items-center gap-3"><input type="radio" name="mode" value="montant" x-model="mode"> <span class="w-24 font-medium">Montant</span>
                                <input type="number" step="0.001" name="montant" value="{{ $nombre($v('montant')) }}" :disabled="mode !== 'montant'" class="input-sm w-32 text-right disabled:opacity-40"></label>
                            <label class="flex items-center gap-3"><input type="radio" name="mode" value="manuelle" x-model="mode"> <span class="font-medium">Saisie manuelle</span>
                                <span class="text-xs text-slate-400">(montant ou quantité saisi chaque mois dans la paie)</span></label>
                            @foreach(['pourcentage' => 'Pourcentage', 'proportion' => 'Proportion'] as $m => $l)
                                <div class="flex flex-wrap items-center gap-2"><label class="flex items-center gap-3"><input type="radio" name="mode" value="{{ $m }}" x-model="mode"> <span class="w-24 font-medium">{{ $l }}</span></label>
                                    <select name="base" :disabled="mode !== '{{ $m }}'" class="input-sm w-44 disabled:opacity-40">
                                        <option value="base" @selected(($v('base') ?? 'base') === 'base')>Salaire de base</option>
                                        <option value="salaire" @selected($v('base') === 'salaire')>Salaire du mois</option>
                                        <optgroup label="Autre (retenue sur le net seulement)">
                                            <option value="imposable" @selected($v('base') === 'imposable')>Salaire imposable</option>
                                            <option value="brut" @selected($v('base') === 'brut')>Total brut</option>
                                        </optgroup>
                                        <optgroup label="Rubriques du dossier">
                                        @foreach($autres->reject->note as $x)<option value="r:{{ $x->id }}" @selected($v('base') === 'r:'.$x->id)>{{ $x->code }} {{ $x->nom }}</option>@endforeach
                                        </optgroup>
                                    </select>
                                    <span>×</span>
                                    @if($m === 'pourcentage')
                                        <input type="number" step="0.001" name="taux" value="{{ $nombre($v('taux')) }}" :disabled="mode !== 'pourcentage'" class="input-sm w-20 text-right disabled:opacity-40"><span>%</span>
                                    @else
                                        <input type="number" step="0.001" name="numerateur" value="{{ $nombre($v('numerateur')) }}" :disabled="mode !== 'proportion'" class="input-sm w-20 text-right disabled:opacity-40"><span>/</span>
                                        <input type="number" step="0.001" name="denominateur" value="{{ $nombre($v('denominateur')) }}" :disabled="mode !== 'proportion'" class="input-sm w-20 text-right disabled:opacity-40">
                                    @endif
                                </div>
                            @endforeach
                            @foreach(['montant', 'base', 'taux', 'numerateur', 'denominateur'] as $c)@error($c)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror @endforeach
                        </div>
                    </fieldset>
                    <div class="space-y-2 text-sm">
                        <label class="flex items-center gap-2" title="{{ $aide['depend_note'] }}"><input type="checkbox" name="depend_note" value="1" x-model="dn" class="rounded border-slate-300"> <span class="font-medium">Dépend d’une note</span></label>
                        <div x-show="dn" x-cloak>
                            <select name="note_id" class="input-sm w-full"><option value="">— Choisir la note —</option>
                                @foreach($notes as $x)<option value="{{ $x->id }}" @selected((int) $v('note_id') === $x->id)>{{ $x->code }} {{ $x->nom }}</option>@endforeach
                            </select>
                            @if($notes->isEmpty())<p class="mt-1 text-xs text-amber-600">Créez d’abord une rubrique cochée « Note sans calculer sa valeur ».</p>@endif
                            @error('note_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <p class="pt-2 text-xs text-slate-400">Les rubriques à saisie manuelle, d’heures ou de jours supplémentaires, d’heures de nuit et les notes se saisissent chaque mois dans <a href="{{ route('paie.index', ['tab' => 'variable', 'mois' => $mois]) }}" class="text-brand-600 hover:underline">Paie → Saisie Paie → Variable</a>.</p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 p-4 dark:border-slate-800">
                @if($R0->exists)
                    <button type="submit" form="suppr-rubrique" class="btn-secondary text-rose-600"><x-icon name="x" class="h-4 w-4"/> Supprimer</button>
                @endif
                <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
            </div>
        </form>
        @if($R0->exists)
            <form id="suppr-rubrique" method="POST" action="{{ route('paie.rubriques.destroy', $R0) }}" onsubmit="return confirm(@js('Supprimer la rubrique « '.$R0->nom.' » ? Les paies clôturées gardent leurs montants.'))" class="hidden">@csrf @method('DELETE')<input type="hidden" name="mois" value="{{ $mois }}"></form>
        @endif
    </div>
</x-layouts.app>
