{{-- Grand livre → Échéancier : filtres, délais de paiement du dossier, tableau. --}}
@php $pe = array_filter(['annee' => $annee, 'date' => $dateEch, 'section' => $section, 'compte' => $compte, 'detail' => $detail ? 1 : null]); @endphp
<form class="card mb-4 flex flex-wrap items-end gap-3 p-4"><input type="hidden" name="tab" value="gl"><input type="hidden" name="vue" value="echeancier"><input type="hidden" name="annee" value="{{ $annee }}">
    <div><label class="label" for="ec-d">Arrêté au</label><input id="ec-d" type="date" name="date" value="{{ $dateEch }}" class="input"></div>
    <div><label class="label" for="ec-s">Tiers</label><select id="ec-s" name="section" class="input">
        <option value="">Tous (40, 41, 42)</option>@foreach(\App\Services\Balances::AUXILIAIRES as $k => $l)<option value="{{ $k }}" @selected((string) $section === (string) $k)>{{ $k }} — {{ $l }}</option>@endforeach</select></div>
    <div class="w-56"><label class="label" for="ec-c">Compte (ou début)</label><input id="ec-c" name="compte" value="{{ $compte }}" class="input font-mono" list="cpts-ec" autocomplete="off">
        <datalist id="cpts-ec" data-plan>@foreach($plan as $n => $l)@if(preg_match('/^4[012]/', (string) $n))<option value="{{ $n }}">{{ $l }}</option>@endif @endforeach</datalist></div>
    <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="detail" value="1" @checked($detail) class="rounded border-slate-300 text-brand-600"> Détail des pièces</label>
    <button class="btn-primary">Afficher</button>
    <div class="ml-auto flex gap-2">
        <a href="{{ route('compta.echeancier.csv', $pe) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel (CSV)</a>
        <a href="{{ route('compta.echeancier.print', $pe) }}" target="_blank" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</a>
    </div>
</form>

<details class="card mb-4">
    <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Délais de paiement du dossier :
        @foreach(\App\Services\Balances::AUXILIAIRES as $k => $l){{ mb_strtolower(explode(' ', $l)[0]) }} {{ $ECH['delais'][$k] }} j{{ $loop->last ? '' : ' · ' }}@endforeach</summary>
    <form method="POST" action="{{ route('compta.echeancier.delais') }}" class="flex flex-wrap items-end gap-3 border-t border-slate-100 p-4 dark:border-slate-800">@csrf
        @foreach(\App\Services\Balances::AUXILIAIRES as $k => $l)
            <div><label class="label" for="ec-dl-{{ $k }}">{{ $k }} — {{ $l }}</label><div class="flex items-center gap-2"><input id="ec-dl-{{ $k }}" type="number" min="0" max="365" name="delais[{{ $k }}]" value="{{ $ECH['delais'][$k] }}" class="input w-24 text-right"><span class="text-sm text-slate-500">jours</span></div></div>
        @endforeach
        @if(\App\Services\Ecritures::autorise(auth()->user()))<button class="btn-secondary">Enregistrer</button>@endif
        <p class="w-full text-xs text-slate-500">Échéance d’une pièce = sa date + le délai de paiement. Les factures ne portant pas de date d’échéance, ce délai s’applique à toutes les pièces du tiers.</p>
    </form>
</details>

@include('compta._echeancier')
