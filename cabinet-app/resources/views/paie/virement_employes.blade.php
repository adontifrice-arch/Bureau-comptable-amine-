{{-- Ordre de virement → Par employé : un ordre de virement par salarié payé par virement (une page chacun). --}}
<x-layouts.app :title="'Ordres de virement '.$mois">
    <style>@media print { @page { size: A4 portrait; margin: 15mm; } }</style>
    <div class="no-print"><x-page-header :title="'Ordres de virement par employé — '.$periode" :subtitle="$client->nom" :back="route('paie.index', ['mois' => $mois])">
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</button>
    </x-page-header></div>
    @forelse($V as [$x, $s])
        <div class="mb-8 break-after-page">
            <x-doc-sheet titre="ORDRE DE VIREMENT" :numero="null">
                <div class="mb-6 text-sm">
                    <div class="font-semibold">À l’attention de {{ $client->paie_banque ?: 'notre banque' }}</div>
                    <div class="text-slate-500">{{ $client->nom }} — {{ $client->adresse }}</div>
                </div>
                <p class="mb-4 text-sm leading-relaxed">Nous vous prions de bien vouloir débiter notre compte n° <b class="font-mono">{{ $client->paie_rib ?: '………………………………' }}</b>
                    de la somme de <b class="font-mono">{{ tnd($x['net']) }}</b> et de la virer au compte du bénéficiaire ci-dessous, au titre {{ empty($x['special']) ? 'du salaire de' : 'de' }} <b>{{ empty($x['special']) ? mois_fr($mois) : $x['special'].' '.substr($mois, 0, 4) }}</b>.</p>
                <table class="mb-6 w-full text-sm">
                    <tr class="border-b border-slate-200"><td class="w-48 py-2 text-slate-500">Bénéficiaire</td><td class="py-2 font-semibold">{{ $x['nom'] }}</td></tr>
                    <tr class="border-b border-slate-200"><td class="py-2 text-slate-500">Matricule</td><td class="py-2">{{ $s?->matricule ?: '—' }}</td></tr>
                    <tr class="border-b border-slate-200"><td class="py-2 text-slate-500">Banque / agence</td><td class="py-2">{{ trim(($s?->banque ?? '').' '.($s?->agence ?? '')) ?: '—' }}</td></tr>
                    <tr class="border-b border-slate-200"><td class="py-2 text-slate-500">RIB</td><td class="py-2 font-mono {{ $s?->rib ? '' : 'text-rose-600' }}">{{ $s?->rib ?: 'RIB manquant' }}</td></tr>
                    <tr><td class="py-2 text-slate-500">Montant</td><td class="py-2 font-mono text-lg font-bold">{{ tnd($x['net']) }}</td></tr>
                </table>
                <div class="mt-12 flex justify-between text-sm"><span>Fait le {{ now()->format('d/m/Y') }}</span><span>Signature et cachet de l’employeur</span></div>
                <div class="h-16"></div>
            </x-doc-sheet>
        </div>
    @empty
        <div class="card"><x-empty icon="bank" title="Aucun salarié payé par virement ce mois-ci"/></div>
    @endforelse
</x-layouts.app>
