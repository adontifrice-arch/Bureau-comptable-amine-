{{-- Case « en suspension de TVA » d'un formulaire de facture (achat : $p = 'fr', vente : $p = 'fv'). Alpine : e.tva_suspension, e.suspension_ref, e.suspension_date. --}}
@php $achat = $p === 'fr'; @endphp
<div class="rounded-xl border border-slate-200 p-3 md:col-span-3 dark:border-slate-700" :class="e.tva_suspension ? '!border-amber-300 bg-amber-50/60 dark:bg-amber-500/5' : ''">
    <input type="hidden" name="tva_suspension" value="0">
    <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" id="{{ $p }}-susp" name="tva_suspension" value="1" x-model="e.tva_suspension" class="rounded border-slate-300 text-brand-600">
        {{ $achat ? 'Achat en suspension de TVA' : 'Vente en suspension de TVA' }}</label>
    <div x-show="e.tva_suspension" x-cloak class="mt-3 grid gap-4 md:grid-cols-3">
        <div><label class="label" for="{{ $p }}-sref">{{ $achat ? 'N° du bon de commande (chiffres)' : 'N° de l’autorisation administrative (vente)' }}</label><input id="{{ $p }}-sref" name="suspension_ref" class="input" x-model="e.suspension_ref" @if($achat) maxlength="13" inputmode="numeric" pattern="\d{1,13}" title="Chiffres uniquement (13 au plus)" @else maxlength="20" @endif></div>
        <div><label class="label" for="{{ $p }}-sdate">{{ $achat ? 'Date du bon de commande' : 'Date de l’autorisation' }}</label><input id="{{ $p }}-sdate" type="date" name="suspension_date" class="input" x-model="e.suspension_date"></div>
        <p class="self-end text-xs text-slate-500">La TVA calculée (<b class="font-mono" x-text="tnd(totalTva)"></b>) n’est pas facturée : elle n’est pas comptabilisée et figure dans la liste trimestrielle des {{ $achat ? 'achats' : 'ventes' }} en suspension de TVA.</p>
    </div>
</div>
