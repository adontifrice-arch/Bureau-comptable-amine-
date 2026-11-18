{{-- Reprise d'un dossier : import de la balance N-1, contrôle des comptes lus, puis écriture d'à-nouveaux au 31/12/N-1. --}}
<x-layouts.app title="Reprise de la balance N-1">
    <x-page-header :title="'Reprise de la balance '.($annee - 1)" :subtitle="$client->nom.' · comptabilité tenue à partir de '.$annee" :back="route('compta.index', ['tab' => 'bal', 'annee' => $annee])"/>

    @if($existante)
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
            <x-icon name="check" class="h-4 w-4"/> La balance {{ $annee - 1 }} a déjà été reprise le {{ $existante->created_at?->format('d/m/Y') }} ({{ $existante->lignes()->count() }} lignes).
            <a href="{{ route('compta.ecritures.show', $existante) }}" class="underline">Voir l’écriture</a> — un nouvel import la remplacera.
        </div>
    @endif

    @if(! $import)
        <div class="card max-w-3xl">
            <div class="card-h"><h2 class="card-t">Importer la balance de clôture {{ $annee - 1 }}</h2></div>
            <form method="POST" action="{{ route('compta.balance-n1.lire') }}" enctype="multipart/form-data" class="card-b space-y-4">@csrf
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="md:col-span-2"><label class="label" for="bn-f">Balance (PDF, Excel ou Word)</label>
                        <input id="bn-f" type="file" name="fichier" accept=".pdf,.xlsx,.xls,.ods,.csv,.txt,.docx,.doc,.rtf" class="input" required></div>
                    <div><label class="label" for="bn-a">Exercice à tenir</label><input id="bn-a" type="number" name="annee" value="{{ $annee }}" min="2000" max="2100" class="input" required>
                        <p class="mt-1 text-xs text-slate-400">La balance importée est celle de l’exercice précédent.</p></div>
                </div>
                <ul class="list-disc space-y-1 pl-5 text-xs text-slate-500">
                    <li>Les soldes de chaque compte sont lus (dernières colonnes Débit / Crédit, ou colonne Solde) ; les lignes de total et de regroupement sont écartées.</li>
                    <li>Vous contrôlez et corrigez les comptes lus avant l’enregistrement.</li>
                    <li>La balance est passée au journal des à-nouveaux le 31/12/{{ $annee - 1 }} : la balance {{ $annee }} en reprend les comptes de bilan et le résultat {{ $annee - 1 }} (compte 121), et les états financiers et la liasse {{ $annee }} ont leur colonne N-1.</li>
                </ul>
                <div class="flex justify-end"><button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Lire la balance</button></div>
            </form>
        </div>
    @else
        @php
            $lignes = $import['lignes'];
            $doutes = count(array_filter($lignes, fn ($l) => $l['doute']));
            $methodes = ['pdf' => 'PDF', 'ia' => 'PDF lu par l’IA', 'xlsx' => 'Excel', 'xls' => 'Excel', 'ods' => 'Tableur', 'csv' => 'CSV', 'txt' => 'Texte', 'docx' => 'Word', 'doc' => 'Word', 'rtf' => 'Word (RTF)'];
        @endphp
        <form method="POST" action="{{ route('compta.balance-n1.enregistrer') }}" class="space-y-4"
              x-data="{ l: @js($lignes), plan: @js(array_fill_keys(array_map('strval', array_keys($plan)), 1)),
                        get d() { return this.l.reduce((s, x) => s + (+x.debit || 0), 0) }, get c() { return this.l.reduce((s, x) => s + (+x.credit || 0), 0) },
                        get ecart() { return Math.round((this.d - this.c) * 1000) / 1000 }, get nouveaux() { return this.l.filter(x => x.compte && ! this.plan[x.compte] && x.libelle).length },
                        inverser(x) { [x.debit, x.credit] = [x.credit, x.debit]; x.doute = false } }"
              @submit="$refs.json.value = JSON.stringify(l)">@csrf
            <input type="hidden" name="annee" value="{{ $import['annee'] }}"><input type="hidden" name="lignes" x-ref="json">

            <div class="card">
                <div class="card-h flex-wrap gap-2">
                    <h2 class="card-t">{{ $import['fichier'] }}</h2>
                    <span class="badge-gray">{{ $methodes[$import['methode']] ?? $import['methode'] }}</span>
                    <span class="badge-blue"><span x-text="l.length"></span> comptes</span>
                    @if($doutes)<span class="badge-amber">{{ $doutes }} sens à vérifier</span>@endif
                </div>
                <div class="card-b space-y-2 text-sm">
                    @if($doutes)<p class="text-amber-700">⚠ Pour les comptes marqués « à vérifier », le sens du solde n’a pas pu être déduit des colonnes du fichier : il suit la nature du compte. Utilisez ⇄ pour l’inverser.</p>@endif
                    @if($autres)<p class="text-amber-700">⚠ Le dossier contient déjà {{ $autres }} écriture(s) datée(s) de {{ $import['annee'] - 1 }} ou avant : la reprise s’y ajoutera.</p>@endif
                </div>
                <div class="max-h-[60vh] overflow-auto border-t border-slate-100 dark:border-slate-800">
                    <table class="tbl">
                        <thead class="sticky top-0 bg-white dark:bg-slate-900"><tr><th class="w-36">Compte</th><th>Intitulé</th><th class="w-40 text-right">Solde débiteur</th><th class="w-40 text-right">Solde créditeur</th><th class="w-28"></th></tr></thead>
                        <tbody>
                            <template x-for="(x, i) in l" :key="i">
                                <tr :class="x.doute ? 'bg-amber-50/70 dark:bg-amber-950/20' : ''">
                                    <td><input class="input !py-1 font-mono" x-model="x.compte" list="cpts-bn" aria-label="Compte"></td>
                                    <td><input class="input !py-1" x-model="x.libelle" aria-label="Intitulé">
                                        <p class="mt-0.5 text-xs" :class="plan[x.compte] ? 'text-slate-400' : 'text-sky-700'" x-text="plan[x.compte] ? '' : (x.libelle ? 'nouveau compte' : 'compte absent du plan')"></p></td>
                                    <td><input type="number" step="0.001" min="0" class="input !py-1 text-right font-mono" x-model.number="x.debit" aria-label="Solde débiteur"></td>
                                    <td><input type="number" step="0.001" min="0" class="input !py-1 text-right font-mono" x-model.number="x.credit" aria-label="Solde créditeur"></td>
                                    <td class="whitespace-nowrap text-right">
                                        <span x-show="x.doute" class="badge-amber">à vérifier</span>
                                        <button type="button" class="btn-icon" title="Inverser le sens du solde" @click="inverser(x)">⇄</button>
                                        <button type="button" class="btn-icon" title="Retirer" @click="l.splice(i, 1)"><x-icon name="trash" class="h-4 w-4"/></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="sticky bottom-0 bg-white font-semibold dark:bg-slate-900"><tr>
                            <td colspan="2"><button type="button" class="btn-secondary btn-sm" @click="l.push({compte: '', libelle: '', debit: 0, credit: 0, doute: false})">+ Compte</button>
                                <span class="ml-3" x-show="Math.abs(ecart) > 0.0005"><span class="badge-red">Écart <span x-text="tnd(Math.abs(ecart))"></span></span></span>
                                <span class="ml-3" x-show="Math.abs(ecart) <= 0.0005"><span class="badge-green">Équilibrée</span></span></td>
                            <td class="num" x-text="tnd(d)"></td><td class="num" x-text="tnd(c)"></td><td></td>
                        </tr></tfoot>
                    </table>
                </div>
                <datalist id="cpts-bn">@foreach($plan as $n => $lib)<option value="{{ $n }}">{{ $lib }}</option>@endforeach</datalist>
            </div>

            <div class="card"><div class="card-b space-y-3 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="ajouter_comptes" value="1" checked class="rounded border-slate-300 text-brand-600">
                    Ajouter au plan comptable les comptes absents, avec leur intitulé (<span x-text="nouveaux"></span>)</label>
                <div class="flex flex-wrap items-center gap-2" x-show="Math.abs(ecart) > 0.0005">
                    <label class="flex items-center gap-2"><input type="checkbox" name="ecart" value="1" class="rounded border-slate-300 text-brand-600"> Passer l’écart sur le compte d’attente</label>
                    <input name="compte_ecart" value="{{ $attente }}" class="input w-32 font-mono" aria-label="Compte d’attente">
                </div>
            </div></div>

            <div class="flex justify-end gap-2">
                <button type="submit" form="bn-abandon" class="btn-secondary">Abandonner</button>
                @if($chef)<button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer la reprise au 31/12/{{ $import['annee'] - 1 }}</button>
                @else<span class="self-center text-xs text-slate-500">Seul le chef comptable peut enregistrer la reprise.</span>@endif
            </div>
        </form>
        <form id="bn-abandon" method="POST" action="{{ route('compta.balance-n1.abandonner') }}">@csrf</form>
    @endif
</x-layouts.app>
