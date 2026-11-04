<x-layouts.app :title="'Bulletins '.$mois">
    <div class="no-print"><x-page-header :title="'Bulletins de paie — '.mois_fr($mois)" :subtitle="$client->nom" :back="route('paie.index', ['mois' => $mois])"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @forelse($R as $x)
        <div class="mb-8 break-after-page">
        <x-doc-sheet titre="BULLETIN DE PAIE" :numero="null">
            <div class="mb-6 grid grid-cols-2 gap-6 rounded-lg bg-slate-50 p-4">
                <div><div class="text-[10px] font-semibold uppercase text-slate-400">Employeur</div><div class="font-bold">{{ $client->nom }}</div><div class="text-xs">{{ $client->adresse }}</div><div class="text-xs">MF {{ $client->matricule_fiscal }} · CNSS {{ $client->matricule_cnss }}</div></div>
                <div><div class="text-[10px] font-semibold uppercase text-slate-400">Salarié</div><div class="font-bold">{{ $x['nom'] }}</div><div class="text-xs">{{ $x['poste'] }}</div><div class="text-xs">CIN {{ $x['cin'] ?? '—' }} · CNSS {{ $x['cnss'] ?? '—' }}</div></div>
            </div>
            <div class="mb-3 text-sm">Période : <b class="capitalize">{{ mois_fr($mois) }}</b></div>
            <table class="w-full text-sm">
                <thead><tr class="bg-slate-100 text-left text-xs uppercase text-slate-600"><th class="px-3 py-2">Rubrique</th><th class="px-3 py-2 text-right">Montant (TND)</th></tr></thead>
                <tbody>
                    @foreach([['Salaire de base', $x['base']], ['Primes et indemnités imposables', $x['prime']], ['SALAIRE BRUT', $x['brut'], 1], ['Retenue CNSS ('.$P['cnssS'].' %)', -$x['cnssS']], ['Salaire imposable annuel (après abattements)', $x['bi'], 2], ['Retenue à la source IRPP', -$x['ir']], ['Contribution sociale de solidarité', -$x['css']], ['NET À PAYER', $x['net'], 1]] as $row)
                        <tr class="border-b border-slate-200 {{ ($row[2] ?? 0) === 1 ? 'bg-slate-50 font-bold' : '' }} {{ ($row[2] ?? 0) === 2 ? 'text-xs italic text-slate-500' : '' }}"><td class="px-3 py-2">{{ $row[0] }}</td><td class="px-3 py-2 text-right font-mono">{{ tnd($row[1], false) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4 text-xs text-slate-500">Charges patronales : CNSS {{ tnd($x['cnssP']) }} · TFP {{ tnd($x['tfp']) }} · FOPROLOS {{ tnd($x['fop']) }} — coût total employeur {{ tnd($x['cout']) }}</div>
        </x-doc-sheet>
        </div>
    @empty <div class="card"><x-empty title="Aucun salarié"/></div> @endforelse
</x-layouts.app>
