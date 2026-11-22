@php $devis = $f->estDevis(); [$sl, $sc] = $f->statutLibelle(); $c = $f->client; @endphp
<x-layouts.app :title="($devis ? 'Devis ' : 'Facture ').$f->numero">
    <div class="no-print">
        <x-page-header :title="($devis ? 'Devis ' : 'Facture ').$f->numero" :back="route('factures.index', ['type' => $f->type])">
            <span class="badge-{{ $sc }}">{{ $sl }}</span>
            @if($devis && $f->statut !== 3)<form method="POST" action="{{ route('factures.convertir', $f) }}">@csrf<button class="btn-secondary"><x-icon name="invoice" class="h-4 w-4"/> Transformer en facture</button></form>@endif
            @unless($f->teifVerrouillee())<a href="{{ route('factures.edit', $f) }}" class="btn-secondary"><x-icon name="edit" class="h-4 w-4"/> Modifier</a>@endunless
            <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button>
        </x-page-header>
        @unless($devis)
            @php $anom = \App\Services\ElFatoora\Teif::anomalies($f); [$tl, $tc] = \App\Models\Facture::STATUTS_TEIF[$f->teif_statut] ?? ['Non transmise', 'gray']; @endphp
            <div class="card mb-6">
                <div class="card-h flex flex-wrap items-center justify-between gap-3">
                    <h2 class="card-t">Facture électronique (El Fatoora) <span class="badge-{{ $tc }} ml-2">{{ $tl }}</span></h2>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('factures.teif', $f) }}" class="btn-secondary" title="XML TEIF non signé, contrôlé avec le schéma officiel"><x-icon name="download" class="h-4 w-4"/> XML TEIF</a>
                        @if($f->teif_fichier)<a href="{{ route('factures.teif.signe', $f) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> XML {{ $f->teif_statut === 'validee' ? 'validé TTN' : 'signé' }}</a>@endif
                        @if(! $anom && in_array($f->teif_statut, [null, 'signee', 'rejetee'], true))
                            <form method="POST" action="{{ route('factures.teif.signer', $f) }}">@csrf<button class="btn-secondary">{{ $f->teif_statut ? 'Signer de nouveau' : 'Signer' }}</button></form>
                        @endif
                        @if(in_array($f->teif_statut, ['signee', 'rejetee'], true))
                            <form method="POST" action="{{ route('factures.teif.deposer', $f) }}" onsubmit="return confirm('Déposer la facture {{ $f->numero }} sur El Fatoora ? Une fois déposée, elle ne pourra plus être modifiée.')">@csrf<button class="btn-primary">Déposer sur El Fatoora</button></form>
                        @endif
                        @if($f->teif_statut === 'deposee')
                            <form method="POST" action="{{ route('factures.teif.consulter', $f) }}">@csrf<button class="btn-primary">Consulter le traitement</button></form>
                        @endif
                    </div>
                </div>
                <div class="card-b space-y-2 text-sm">
                    @foreach($anom as $a)<p class="text-rose-600">• {{ $a }}</p>@endforeach
                    @if($f->teif_signe_le)<p class="text-slate-500">Signée le {{ $f->teif_signe_le->format('d/m/Y à H:i') }}@if($f->teif_depose_le) · déposée le {{ $f->teif_depose_le->format('d/m/Y à H:i') }}@endif</p>@endif
                    @if($f->teif_reference)<p>Référence TTN : <span class="font-mono font-semibold">{{ $f->teif_reference }}</span></p>@endif
                    @if($f->teif_message)<p class="whitespace-pre-line {{ $f->teif_statut === 'rejetee' ? 'text-rose-600' : 'text-slate-500' }}">{{ $f->teif_message }}</p>@endif
                    @if(! $anom && ! $f->teif_statut)<p class="text-slate-500">Signez la facture avec le certificat du cabinet, puis déposez-la : El Fatoora attribue une référence unique et un cachet (QR code) imprimés sur la facture.</p>@endif
                </div>
            </div>
        @endunless
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
        @if(!$devis && $f->teif_reference)
            <div class="mt-6 flex items-center gap-4 rounded-lg border border-slate-200 p-3 text-xs">
                @if($f->teif_qr)<img src="data:image/png;base64,{{ $f->teif_qr }}" alt="Cachet électronique TTN" class="h-24 w-24">@endif
                <div>Copie de la facture électronique enregistrée chez TTN sous la référence :<div class="mt-1 font-mono text-sm font-semibold">{{ $f->teif_reference }}</div></div>
            </div>
        @endif
        @if(!$devis && ($cab['banque'] || $cab['rib'] || $cab['iban']))
            <div class="mt-8 rounded-lg bg-slate-50 p-4 text-xs">
                <div class="mb-1 font-semibold">Coordonnées bancaires</div>
                @foreach(['banque' => 'Banque', 'titulaire' => 'Titulaire', 'rib' => 'RIB', 'iban' => 'IBAN', 'bic' => 'BIC'] as $k => $l)@if($cab[$k])<div>{{ $l }} : {{ $cab[$k] }}</div>@endif @endforeach
            </div>
        @endif
    </x-doc-sheet>
</x-layouts.app>
