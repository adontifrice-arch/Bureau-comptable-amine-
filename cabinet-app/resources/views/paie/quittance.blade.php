{{-- Quittance de solde de tout compte : reçu signé par le salarié (le texte peut être retouché avant l'impression). --}}
@php
    $d = $x['stc'] ?? [];
    $motifs = \App\Models\Salarie::MOTIFS_SORTIE;
    $lignes = array_values(array_filter(\App\Services\PaieCalc::lignesBulletin($x, \App\Services\PaieCalc::parametres($client)), fn ($l) => ($l[2] ?? 0) !== 2));
    $ville = trim((string) (($client->paie_config ?? [])['ville'] ?? '')) ?: '……………';
@endphp
<x-layouts.app :title="'Quittance STC — '.$x['nom']">
    <style>@media print { @page { size: A4 portrait; margin: 18mm; } }</style>
    <div class="no-print"><x-page-header title="Quittance de solde de tout compte" :subtitle="$x['nom'].' — '.$paie->libelle()" :back="route('paie.index', ['tab' => 'stc', 'stc' => $paie->id, 'mois' => $paie->mois])">
        <span class="self-center text-xs text-slate-500">Cliquez dans le texte pour le retoucher avant l’impression.</span>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer</button>
    </x-page-header></div>
    <x-doc-sheet titre="REÇU POUR SOLDE DE TOUT COMPTE" :numero="null">
        <div contenteditable="true" class="space-y-4 text-sm leading-relaxed outline-none">
            <p>Je soussigné(e) <b>{{ $x['nom'] }}</b>, titulaire de la CIN n° <b>{{ $x['cin'] ?: '……………' }}</b>{{ $s?->adresse ? ', demeurant '.$s->adresse.($s->ville ? ' '.$s->ville : '') : '' }},
                ayant occupé l’emploi de <b>{{ $x['poste'] ?: '……………' }}</b> au sein de <b>{{ $client->nom }}</b>{{ $client->adresse ? ', '.$client->adresse : '' }}
                @if($s?->date_embauche) du {{ $s->date_embauche->format('d/m/Y') }}@endif @if(! empty($d['date_sortie'])) au {{ $d['date_sortie'] }}@endif{{ ! empty($d['motif']) ? ' (motif : '.mb_strtolower($motifs[$d['motif']] ?? $d['motif']).')' : '' }},</p>
            <p>reconnais avoir reçu de mon employeur la somme nette de <b>{{ tnd($x['net']) }}</b> pour solde de tout compte, en paiement de :</p>
            <table class="w-full border border-slate-300">
                @foreach($lignes as $l)
                    <tr class="border-b border-slate-200 {{ ($l[2] ?? 0) === 1 ? 'bg-slate-50 font-bold' : '' }}"><td class="px-3 py-1.5">{{ $l[0] }}</td><td class="px-3 py-1.5 text-right font-mono">{{ tnd($l[1], false) }}</td></tr>
                @endforeach
            </table>
            <p>Ce paiement a été effectué par {{ \App\Models\Salarie::MODES[$s?->mode_paiement ?? 'virement'] ?? 'virement' }}{{ ($s?->mode_paiement ?? 'virement') === 'virement' && $s?->rib ? ' sur le compte n° '.$s->rib : '' }}.</p>
            <p>Le présent reçu est établi en double exemplaire, dont un m’a été remis. Il peut être dénoncé dans les délais prévus par la législation du travail en vigueur.</p>
            <p>Fait à {{ $ville }}, le {{ now()->format('d/m/Y') }}.</p>
            <div class="grid grid-cols-2 gap-8 pt-6">
                <div><div class="font-semibold">Pour l’employeur</div><div class="text-xs text-slate-500">signature et cachet</div><div class="h-20"></div></div>
                <div><div class="font-semibold">Le salarié</div><div class="text-xs text-slate-500">mention manuscrite « Pour solde de tout compte » et signature</div><div class="h-20"></div></div>
            </div>
        </div>
    </x-doc-sheet>
</x-layouts.app>
