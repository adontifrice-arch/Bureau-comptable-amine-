@php $devis = $f->estDevis(); [$sl, $sc] = $f->statutLibelle(); $c = $f->client; @endphp
<x-layouts.app :title="($devis ? 'Devis ' : 'Facture ').$f->numero">
    <div class="no-print">
        <x-page-header :title="($devis ? 'Devis ' : 'Facture ').$f->numero" :back="route('factures.index', ['type' => $f->type])">
            <span class="badge-{{ $sc }}">{{ $sl }}</span>
            @if($devis && $f->statut !== 3)<form method="POST" action="{{ route('factures.convertir', $f) }}">@csrf<button class="btn-secondary"><x-icon name="invoice" class="h-4 w-4"/> Transformer en facture</button></form>@endif
            @unless($devis)<a href="{{ route('factures.teif', $f) }}" class="btn-secondary" title="Brouillon XML pour la facturation électronique (El Fatoora)"><x-icon name="download" class="h-4 w-4"/> XML TEIF</a>@endunless
            <a href="{{ route('factures.edit', $f) }}" class="btn-secondary"><x-icon name="edit" class="h-4 w-4"/> Modifier</a>
            <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button>
        </x-page-header>
    </div>
    <x-doc-sheet :titre="$devis ? 'DEVIS' : 'FACTURE'" :numero="$f->numero" :sig="$devis ? 'Bon pour accord (date et signature du client)' : 'Signature du client'">
        <div class="grid grid-cols-2 gap-8">
            <div>
                <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $devis ? 'Destinataire' : 'Facturé à' }}</div>
                <div class="mt-1 text-base font-bold">{{ $c->nom }}</div>
                @if($c->adresse)<div class="text-slate-600">{{ $c->adresse }}</div>@endif
                @if($c->matricule_fiscal)<div class="text-slate-600">Matricule fiscal : {{ $c->matricule_fiscal }}</div>@endif
            </div>
            <div class="text-right">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Dates</div>
                <div class="mt-1">Date : {{ dfr($f->date) }}</div>
                @if($f->echeance)<div>{{ $devis ? 'Valable jusqu’au' : 'Échéance' }} : {{ dfr($f->echeance) }}</div>@endif
            </div>
        </div>
        <table class="mt-8 w-full">
            <thead><tr class="bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600"><th class="px-3 py-2">Désignation</th><th class="px-3 py-2 text-right">Qté</th><th class="px-3 py-2 text-right">PU HT</th><th class="px-3 py-2 text-right">Total HT</th></tr></thead>
            <tbody>
            @foreach($f->lignes as $l)
                <tr class="border-b border-slate-200"><td class="px-3 py-2.5">{{ $l->designation }}</td><td class="px-3 py-2.5 text-right font-mono">{{ rtrim(rtrim(number_format($l->quantite, 3, ',', ' '), '0'), ',') }}</td><td class="px-3 py-2.5 text-right font-mono">{{ tnd($l->prix_unitaire, false) }}</td><td class="px-3 py-2.5 text-right font-mono">{{ tnd($l->total, false) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-6 ml-auto w-72 space-y-1.5">
            <div class="flex justify-between"><span>Total HT</span><span class="font-mono">{{ tnd($t['ht']) }}</span></div>
            <div class="flex justify-between"><span>TVA ({{ rtrim(rtrim($f->tva_taux, '0'), '.') }} %)</span><span class="font-mono">{{ tnd($t['tva']) }}</span></div>
            @if($t['timbre'] > 0)<div class="flex justify-between"><span>Droit de timbre</span><span class="font-mono">{{ tnd($t['timbre']) }}</span></div>@endif
            <div class="flex justify-between border-t-2 border-slate-800 pt-2 text-base font-bold"><span>Total TTC</span><span class="font-mono">{{ tnd($t['ttc']) }}</span></div>
        </div>
        @if($f->notes)<div class="mt-6 whitespace-pre-line text-xs text-slate-600">{{ $f->notes }}</div>@endif
        @if(!$devis && ($cab['banque'] || $cab['rib'] || $cab['iban']))
            <div class="mt-8 rounded-lg bg-slate-50 p-4 text-xs">
                <div class="mb-1 font-semibold">Coordonnées bancaires</div>
                @foreach(['banque' => 'Banque', 'titulaire' => 'Titulaire', 'rib' => 'RIB', 'iban' => 'IBAN', 'bic' => 'BIC'] as $k => $l)@if($cab[$k])<div>{{ $l }} : {{ $cab[$k] }}</div>@endif @endforeach
            </div>
        @endif
    </x-doc-sheet>
</x-layouts.app>
