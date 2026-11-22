{{-- Acompte → Saisie des acomptes : avances sur salaire, retenues sur la paie du mois choisi (colonne Acom de Saisie Paie). --}}
@php
    $liste = \App\Models\Acompte::with('salarie')->where('client_id', $client->id)->where('mois', $mois)->orderByDesc('date')->get();
    $actifs = $salaries->filter(fn ($e) => \App\Services\PaieCalc::payeLeMois($e, $mois));
    $modes = \App\Models\Salarie::MODES;
    $paieClose = $paie && $paie->statut !== 'brouillon';
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Saisie des acomptes — retenus sur la paie de {{ mois_fr($mois) }}</h2>
    <span class="text-xs text-slate-500">Changez de mois avec la période de la barre d’outils.</span>
</div>

@if($nouveau = $liste->firstWhere('id', session('recu_acompte')) ?? (session('recu_acompte') ? \App\Models\Acompte::with('salarie')->where('client_id', $client->id)->find(session('recu_acompte')) : null))
    {{-- Après la saisie : reçu d'avance sur salaire à imprimer et faire signer. --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm dark:border-emerald-900 dark:bg-emerald-950/30">
        <span>Acompte de <b>{{ tnd($nouveau->montant) }}</b> versé à <b>{{ $nouveau->salarie?->nomComplet() }}</b> : imprimez le reçu d’avance sur salaire et faites-le signer.</span>
        <a href="{{ route('paie.acomptes.recu', $nouveau) }}" target="_blank" class="btn-primary btn-sm"><x-icon name="printer" class="h-4 w-4"/> Reçu d’avance sur salaire</a>
    </div>
@endif

<div class="grid gap-4 lg:grid-cols-[24rem_1fr]">
    <form method="POST" action="{{ route('paie.acomptes.store') }}" class="card card-b space-y-3 text-sm">@csrf
        <input type="hidden" name="retour" value="{{ $mois }}">
        <div class="font-semibold">Nouvel acompte</div>
        <div><label class="label">Salarié</label>
            <select name="salarie_id" class="input" required><option value="">— Choisir —</option>@foreach($actifs as $e)<option value="{{ $e->id }}" @selected(old('salarie_id') == $e->id)>{{ $e->matricule ? $e->matricule.' — ' : '' }}{{ $e->nomComplet() }}</option>@endforeach</select></div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Date de versement</label><input type="date" name="date" value="{{ old('date', now()->format('Y-m-d')) }}" class="input" required></div>
            <div><label class="label">Montant</label><input type="number" step="0.001" min="0.001" name="montant" value="{{ old('montant') }}" class="input text-right" required></div>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Mode</label><select name="mode" class="input">@foreach($modes as $k => $l)<option value="{{ $k }}" @selected(old('mode', 'especes') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label class="label">Retenu sur la paie de</label><input type="month" name="mois" value="{{ old('mois', $mois) }}" class="input" required></div>
        </div>
        <div><label class="label">Observation</label><input name="observation" value="{{ old('observation') }}" class="input"></div>
        <p class="text-xs text-slate-400">L’acompte est déduit du net de la paie du mois de retenue (colonne « Acom » de Saisie Paie, si elle n’est pas saisie) ; l’écriture de paie le reprend au crédit du compte 425.</p>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Enregistrer l’acompte</button></div>
    </form>

    <div class="card overflow-x-auto">
        <div class="card-h"><h3 class="card-t">Acomptes retenus en {{ mois_fr($mois) }}</h3>
            <span class="flex gap-3 text-xs">
                <a href="{{ route('paie.edition', ['acomptes-mois', 'imprimer', 'mois' => $mois]) }}" target="_blank" class="text-brand-600 hover:underline">Imprimer l’état</a>
                @if($paieClose)<span class="badge-amber">Paie clôturée</span>@endif
            </span></div>
        <table class="tbl text-sm">
            <thead><tr><th>Salarié</th><th>Date</th><th>Mode</th><th class="text-right">Montant</th><th>Observation</th><th></th></tr></thead>
            <tbody>
            @forelse($liste as $a)
                <tr><td class="font-medium">{{ $a->salarie?->nomComplet() }}</td><td>{{ $a->date->format('d/m/Y') }}</td><td>{{ $modes[$a->mode] ?? $a->mode }}</td>
                    <td class="num">{{ n3($a->montant) }}</td><td class="text-xs text-slate-500">{{ $a->observation }}</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('paie.acomptes.recu', $a) }}" target="_blank" class="btn-icon" title="Reçu d’avance sur salaire"><x-icon name="printer" class="h-4 w-4"/></a>
                        @unless($paieClose)<form method="POST" action="{{ route('paie.acomptes.destroy', $a) }}" class="inline" onsubmit="return confirm('Supprimer cet acompte ?')">@csrf @method('DELETE')<button class="btn-icon text-rose-600" title="Supprimer"><x-icon name="trash" class="h-4 w-4"/></button></form>@endunless
                    </td></tr>
            @empty <tr><td colspan="6"><x-empty icon="bank" title="Aucun acompte retenu en {{ mois_fr($mois) }}"/></td></tr> @endforelse
            </tbody>
            @if($liste->count())<tfoot class="border-t-2 border-slate-200 font-semibold"><tr><td colspan="3">Total ({{ $liste->count() }})</td><td class="num">{{ n3($liste->sum('montant')) }}</td><td colspan="2"></td></tr></tfoot>@endif
        </table>
    </div>
</div>
