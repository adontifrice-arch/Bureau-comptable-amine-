{{-- Échéancier d'un prêt (société ou CNSS) : échéances mensuelles retenues sur la paie et reste dû. --}}
@php $reste = (float) $pret->montant; $n = 0; @endphp
<x-layouts.app :title="'Échéancier — '.$pret->salarie?->nomComplet()">
    <div class="no-print"><x-page-header :title="'Échéancier — '.\App\Models\Pret::TYPES[$pret->type]" :subtitle="$pret->salarie?->nomComplet()" :back="route('paie.index', ['tab' => 'prets', 'type' => $pret->type, 'mois' => $pret->premier_mois])">
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</button>
    </x-page-header></div>
    <x-doc-sheet :titre="'ÉCHÉANCIER — '.mb_strtoupper(\App\Models\Pret::TYPES[$pret->type])" :numero="$pret->reference">
        <div class="mb-4 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <span class="text-slate-500">Employeur</span><span>{{ $client->nom }}</span>
            <span class="text-slate-500">Salarié</span><span class="font-semibold">{{ $pret->salarie?->nomComplet() }}{{ $pret->salarie?->matricule ? ' (mat. '.$pret->salarie->matricule.')' : '' }}</span>
            <span class="text-slate-500">Date du prêt</span><span>{{ $pret->date->format('d/m/Y') }}</span>
            <span class="text-slate-500">Montant</span><span class="font-mono">{{ tnd($pret->montant) }}</span>
            <span class="text-slate-500">Remboursement</span><span>{{ $pret->nb_echeances }} échéance(s) de {{ tnd($pret->echeance) }}, retenues sur la paie</span>
        </div>
        <table class="w-full text-sm">
            <thead><tr class="bg-slate-100 text-left text-xs uppercase text-slate-600"><th class="px-3 py-2">N°</th><th class="px-3 py-2">Paie de</th><th class="px-3 py-2 text-right">Échéance</th><th class="px-3 py-2 text-right">Reste dû</th></tr></thead>
            <tbody>
            @foreach($pret->echeancier() as $m => $e)
                @php $reste = r3($reste - $e); @endphp
                <tr class="border-b border-slate-200"><td class="px-3 py-1.5">{{ ++$n }}</td><td class="px-3 py-1.5 capitalize">{{ mois_fr($m) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($e, false) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($reste, false) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-10 grid grid-cols-2 gap-8 text-sm"><div class="font-semibold">Pour l’employeur</div><div class="font-semibold">Le salarié <span class="text-xs font-normal text-slate-500">(lu et approuvé)</span></div></div>
        <div class="h-16"></div>
    </x-doc-sheet>
</x-layouts.app>
