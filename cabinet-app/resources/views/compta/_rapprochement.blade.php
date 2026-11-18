{{-- Banque → État de rapprochement : choix du compte et de la date, solde du relevé, état, pointage des lignes du compte. --}}
@php
    $peutPointer = \App\Services\Ecritures::autorise(auth()->user());
    $pr = ['compte' => $compteBq, 'date' => $dateRap];
@endphp
<div class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <form class="flex flex-wrap items-end gap-3"><input type="hidden" name="tab" value="bq"><input type="hidden" name="vue" value="rapprochement">
        <div><label class="label" for="rp-c">Compte de banque</label><select id="rp-c" name="compte" class="input">@foreach($comptesBq as $c)<option value="{{ $c }}" @selected($c === $compteBq)>{{ $c }} — {{ \App\Services\Compta::libelle($c) }}</option>@endforeach</select></div>
        <div><label class="label" for="rp-d">Arrêté au</label><input id="rp-d" type="date" name="date" value="{{ $dateRap }}" class="input"></div>
        <button class="btn-secondary">Afficher</button>
    </form>
    <form method="POST" action="{{ route('compta.rapprochement.solde') }}" class="flex flex-wrap items-end gap-2">@csrf
        <input type="hidden" name="compte" value="{{ $compteBq }}"><input type="hidden" name="date" value="{{ $dateRap }}">
        <div><label class="label" for="rp-s">Solde du relevé au {{ dfr($dateRap) }}</label>
            <input id="rp-s" type="number" step="0.001" name="solde" value="{{ $R['soldeReleve'] }}" class="input w-44 text-right font-mono" placeholder="créditeur : positif" required></div>
        <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
    </form>
    <a href="{{ route('compta.rapprochement.print', $pr) }}" target="_blank" class="btn-secondary ml-auto"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</a>
    <p class="w-full text-xs text-slate-500">Solde du relevé tel qu’il figure sur l’extrait : positif s’il est créditeur (avoir en banque), négatif s’il est débiteur (découvert).</p>
</div>

<div class="card card-b mb-4">@include('compta._rapprochement-etat', ['ecran' => true])</div>

{{-- Pointage : lignes du compte de banque vues sur le relevé --}}
@foreach([['pointer', 'Écritures non pointées', $R['nonPointees'], 'Pointer au '.dfr($dateRap)], ['depointer', 'Écritures pointées à la main', $R['pointees'], 'Dépointer']] as [$action, $titre, $liste, $bouton])
    <form method="POST" action="{{ route('compta.rapprochement.pointer') }}" class="card mb-4 overflow-x-auto" x-data="{ n: 0 }">@csrf
        <input type="hidden" name="compte" value="{{ $compteBq }}"><input type="hidden" name="date" value="{{ $dateRap }}"><input type="hidden" name="action" value="{{ $action }}">
        <div class="card-h"><h2 class="card-t">{{ $titre }} <span class="text-sm font-normal text-slate-400">({{ $liste->count() }})</span></h2>
            @if($peutPointer && $liste->count())<button class="btn-{{ $action === 'pointer' ? 'primary' : 'secondary' }} btn-sm" :disabled="n === 0">{{ $bouton }} <span x-show="n" x-text="'(' + n + ')'"></span></button>@endif</div>
        <table class="tbl">
            <thead><tr>@if($peutPointer)<th class="w-8"><input type="checkbox" class="rounded border-slate-300" aria-label="Tout cocher" @change="$root.querySelectorAll('input[name=\'lignes[]\']').forEach(c => c.checked = $event.target.checked); n = $event.target.checked ? {{ $liste->count() }} : 0"></th>@endif
                <th>Date</th><th>Jnl</th><th>Libellé</th>@if($action === 'depointer')<th>Pointée le</th>@endif<th class="text-right">Débit</th><th class="text-right">Crédit</th></tr></thead>
            <tbody>
            @forelse($liste as $l)
                <tr>@if($peutPointer)<td><input type="checkbox" name="lignes[]" value="{{ $l['id'] }}" class="rounded border-slate-300 text-brand-600" @change="n += $event.target.checked ? 1 : -1" aria-label="Choisir cette ligne"></td>@endif
                    <td>{{ dfr($l['date']) }}</td><td>{{ $l['journal'] }}</td><td><a href="{{ route('compta.ecritures.show', [$l['ecriture_id'], 'retour' => request()->fullUrl()]) }}" class="hover:underline">{{ $l['libelle'] }}</a></td>
                    @if($action === 'depointer')<td>{{ $l['pointe_le'] ? dfr($l['pointe_le']) : '' }}</td>@endif
                    <td class="num">{{ n3($l['debit']) }}</td><td class="num">{{ n3($l['credit']) }}</td></tr>
            @empty <tr><td colspan="7" class="py-6 text-center text-sm text-slate-400">{{ $action === 'pointer' ? 'Toutes les écritures du compte sont pointées.' : 'Aucune écriture pointée à la main jusqu’au '.dfr($dateRap).'.' }}</td></tr> @endforelse
            </tbody>
        </table>
    </form>
@endforeach
<p class="text-xs text-slate-500">Les écritures passées depuis un relevé importé sont pointées d’office. Pointez les autres (règlements de factures, chèques, saisies manuelles) quand elles apparaissent sur le relevé.</p>
