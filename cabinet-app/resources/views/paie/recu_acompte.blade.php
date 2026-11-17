{{-- Reçu d'acompte sur salaire, à faire signer par le salarié (texte retouchable avant l'impression). --}}
@php $s = $a->salarie; @endphp
<x-layouts.app :title="'Reçu d’acompte — '.$s?->nomComplet()">
    <style>@media print { @page { size: A5 landscape; margin: 10mm; } }</style>
    <div class="no-print"><x-page-header title="Reçu d’acompte sur salaire" :subtitle="$s?->nomComplet()" :back="route('paie.index', ['tab' => 'acomptes', 'mois' => $a->mois])">
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</button>
    </x-page-header></div>
    <x-doc-sheet titre="REÇU D’ACOMPTE SUR SALAIRE" :numero="null">
        <div contenteditable="true" class="space-y-4 text-sm leading-relaxed outline-none">
            <p>Je soussigné(e) <b>{{ $s?->nomComplet() }}</b>{{ $s?->cin ? ', CIN n° '.$s->cin : '' }}{{ $s?->matricule ? ', matricule '.$s->matricule : '' }}, salarié(e) de <b>{{ $client->nom }}</b>,</p>
            <p>reconnais avoir reçu le {{ $a->date->format('d/m/Y') }}, par {{ mb_strtolower(\App\Models\Salarie::MODES[$a->mode] ?? $a->mode) }}, la somme de <b>{{ tnd($a->montant) }}</b> à titre d’acompte sur mon salaire,
                à retenir sur la paie de <b>{{ mois_fr($a->mois) }}</b>.</p>
            @if($a->observation)<p>Observation : {{ $a->observation }}</p>@endif
            <div class="grid grid-cols-2 gap-8 pt-6">
                <div><div class="font-semibold">Pour l’employeur</div><div class="h-16"></div></div>
                <div><div class="font-semibold">Le salarié</div><div class="text-xs text-slate-500">signature précédée de « Lu et approuvé »</div><div class="h-16"></div></div>
            </div>
        </div>
    </x-doc-sheet>
</x-layouts.app>
