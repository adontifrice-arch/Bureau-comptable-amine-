@php $u = fn($t) => route('liasse.index', ['tab' => $t, 'annee' => $annee]); @endphp
<x-layouts.app title="Liasse fiscale">
    <x-page-header title="Liasse fiscale & états financiers" :subtitle="$client->nom.' · exercice clos le 31/12/'.$annee">
        <x-client-picker :clients="$clients" :current="$client" :route="route('liasse.index')" :params="['tab' => $tab, 'annee' => $annee]"/>
        <form><input type="hidden" name="tab" value="{{ $tab }}"><input type="number" name="annee" value="{{ $annee }}" class="input w-24" onchange="this.form.submit()"></form>
        <a href="{{ route('liasse.print', ['annee' => $annee]) }}" class="btn-secondary"><x-icon name="printer" class="h-4 w-4"/> Bilan et résultat (impression rapide)</a>
        <a href="{{ $u('ef') }}" class="btn-primary"><x-icon name="file-text" class="h-4 w-4"/> États financiers complets</a>
    </x-page-header>
    @if(!$F['n'])<div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Aucune écriture comptabilisée pour cet exercice : les états sont alimentés par les écritures validées dans l’onglet Comptabilité.</div>@endif
    <x-tabs :active="$tab" :items="['bil' => ['Bilan', $u('bil')], 'res' => ['État de résultat', $u('res')], 'fis' => ['Passage au résultat fiscal', $u('fis')], 'ef' => ['États financiers', $u('ef')]]"/>
    @if($tab === 'ef')
        @include('liasse._etats-financiers')
    @elseif($tab === 'fis')
        <div class="grid gap-6 lg:grid-cols-3">
            <form method="POST" action="{{ route('liasse.passage') }}" class="card lg:col-span-2">@csrf <input type="hidden" name="annee" value="{{ $annee }}">
                <div class="card-h"><h2 class="card-t">Tableau de passage simplifié</h2></div>
                <div class="card-b grid gap-4 md:grid-cols-3">
                    <div class="md:col-span-3 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/40">Résultat comptable avant impôt : <b class="font-mono">{{ tnd($F['P']['rav']) }}</b></div>
                    <x-field label="Réintégrations fiscales" name="rei" type="number" step="0.001" :value="$fisc['rei']"/>
                    <x-field label="Déductions fiscales" name="ded" type="number" step="0.001" :value="$fisc['ded']"/>
                    @unless($client->estPhysique())<x-field label="Taux de l’IS (%)" name="taux" type="number" step="0.01" :value="$fisc['taux']"/>@endunless
                    <x-field label="Minimum d’impôt" name="min" type="number" step="0.001" :value="$fisc['min']"/>
                    <x-field label="Acomptes provisionnels versés" name="acp" type="number" step="0.001" :value="$fisc['acp']"/>
                    <x-field label="Retenues à la source subies" name="rs" type="number" step="0.001" :value="$fisc['rs']"/>
                </div>
                <div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-primary">Enregistrer le calcul</button></div>
            </form>
            <div class="card card-b space-y-2 self-start text-sm">
                <h3 class="card-t mb-2">Résultat</h3>
                <div class="flex justify-between"><span>Résultat fiscal</span><b class="font-mono">{{ tnd($fisc['rf']) }}</b></div>
                <div class="flex justify-between"><span>Impôt théorique {{ $client->estPhysique() ? '(barème IRPP)' : '' }}</span><span class="font-mono">{{ tnd($fisc['th']) }}</span></div>
                <div class="flex justify-between"><span>Impôt dû</span><b class="font-mono">{{ tnd($fisc['du']) }}</b></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base"><span>{{ $fisc['sol'] >= 0 ? 'Solde à payer' : 'Crédit d’impôt' }}</span><b class="font-mono {{ $fisc['sol'] >= 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ tnd(abs($fisc['sol'])) }}</b></div>
                <p class="pt-2 text-xs text-slate-400">À vérifier selon la situation de la société (taux, exonérations, minimum d’impôt).</p>
            </div>
        </div>
    @else
        @include('liasse._etats', ['show' => [$tab]])
    @endif
</x-layouts.app>
