{{-- Recherche multicritère (bouton « Rechercher » de l'en-tête) : les critères renseignés se cumulent --}}
@php
    $avecCompte = in_array($tab, ['jnl', 'bq', 'ach', 'vte'], true);
    $aide = ['ach' => 'Montant HT ou TTC · fournisseur, description, n° de facture', 'vte' => 'Montant HT ou TTC · client, description, n° de facture',
        'bq' => 'Montant au débit ou au crédit · libellé de l’opération', 'jnl' => 'Montant d’une ligne au débit ou au crédit · libellé de l’écriture'][$tab];
    $effacer = route('compta.index', array_filter(['tab' => $tab, 'annee' => $annee, 'journal' => request('journal'), 'releve' => $releve->id ?? null]));
@endphp
<div x-data="{ ouvert: @js($rech->active()) }" @recherche.window="ouvert = ! ouvert; if (ouvert) $nextTick(() => $refs.premier.focus())" x-show="ouvert" x-cloak class="card mb-4">
    <form method="GET" action="{{ route('compta.index') }}" class="p-4">
        <input type="hidden" name="tab" value="{{ $tab }}"><input type="hidden" name="annee" value="{{ $annee }}">
        @if(request('journal'))<input type="hidden" name="journal" value="{{ request('journal') }}">@endif
        @if($tab === 'bq' && ($releve ?? null))<input type="hidden" name="releve" value="{{ $releve->id }}">@endif
        @if($tab === 'bq' && request()->filled('statut'))<input type="hidden" name="statut" value="{{ request('statut') }}">@endif
        <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-12">
            <div class="lg:col-span-2"><label class="label" for="r-du">Du</label><input id="r-du" x-ref="premier" type="date" name="du" value="{{ $rech->du }}" class="input"></div>
            <div class="lg:col-span-2"><label class="label" for="r-au">Au</label><input id="r-au" type="date" name="au" value="{{ $rech->au }}" class="input"></div>
            <div class="lg:col-span-2"><label class="label" for="r-min">Montant de</label><input id="r-min" name="min" value="{{ $rech->min !== null ? number_format($rech->min, 3, ',', ' ') : '' }}" class="input text-right font-mono" inputmode="decimal" placeholder="0,000"></div>
            <div class="lg:col-span-2"><label class="label" for="r-max">à</label><input id="r-max" name="max" value="{{ $rech->max !== null ? number_format($rech->max, 3, ',', ' ') : '' }}" class="input text-right font-mono" inputmode="decimal" placeholder="illimité"></div>
            <div class="{{ $avecCompte ? 'lg:col-span-3' : 'lg:col-span-4' }}"><label class="label" for="r-txt">Libellé contient</label><input id="r-txt" name="texte" value="{{ $rech->texte }}" class="input" placeholder="ex. STEG, CNSS, facture 2026"></div>
            @if($avecCompte)<div class="lg:col-span-1"><label class="label" for="r-cpt">Compte</label><input id="r-cpt" name="cpt" value="{{ $rech->compte }}" class="input font-mono" inputmode="numeric" placeholder="6…" title="Comptes commençant par ce numéro"></div>@endif
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-500">{{ $aide }}. Un seul critère suffit ; plusieurs se cumulent. Un montant seul (« de » = « à ») cherche ce montant exact.</p>
            <div class="flex gap-2">
                @if($rech->active())<a href="{{ $effacer }}" class="btn-secondary btn-sm">Effacer la recherche</a>@endif
                <button class="btn-primary btn-sm"><x-icon name="search" class="h-3.5 w-3.5"/> Rechercher</button>
            </div>
        </div>
    </form>
</div>

@if($rech->active())
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
        <x-icon name="search" class="h-4 w-4"/>
        <span><b>{{ $resultats['n'] }}</b> résultat(s) — {{ implode(' · ', $rech->resume()) }}</span>
        @if(isset($resultats['total']))<span class="font-mono">· total {{ tnd($resultats['total']) }} {{ $resultats['unite'] }}</span>@endif
        @if(isset($resultats['debit']))<span class="font-mono">· débits {{ tnd($resultats['debit']) }} · crédits {{ tnd($resultats['credit']) }}</span>@endif
        <a href="{{ $effacer }}" class="ml-auto text-xs underline">Effacer</a>
    </div>
@endif
