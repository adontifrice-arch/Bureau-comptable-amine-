@php $DC = \App\Http\Controllers\DeclarationController::class; $fmt = $DC::format($type);
     $L = $d->annexes['l'] ?? []; $tot = $DC::totauxSuspension($d); $sach = $type === 'sach';
     $idk = ['1' => 'MF', '2' => 'CIN', '3' => 'CS', '4' => 'ND']; $edit = empty($print) && ! $d->verrouillee();
     $th = 'px-2 py-1'; $pct = fn ($v) => (float) $v ? (float) $v.' %' : ''; @endphp
<table class="{{ empty($print) ? 'tbl' : 'w-full text-xs' }}">
    <thead><tr class="{{ empty($print) ? '' : 'bg-slate-100 text-left' }}">
        <th class="{{ $th }}">N°</th>
        @if($sach)<th class="{{ $th }}">Fournisseur</th><th class="{{ $th }}">Bon de commande</th><th class="{{ $th }}">Facture</th>
        @else<th class="{{ $th }}">Facture</th><th class="{{ $th }}">Client</th><th class="{{ $th }}">Autorisation</th>@endif
        <th class="{{ $th }} text-right">Prix HT</th>
        @unless($sach)<th class="{{ $th }} text-right">FODEC</th><th class="{{ $th }} text-right">Droit de cons.</th>@endunless
        <th class="{{ $th }} text-right">TVA suspendue</th>
        <th class="{{ $th }}">{{ empty($print) ? 'Contrôle' : ($sach ? 'Autorisation' : '') }}</th>
        @if($edit)<th></th>@endif
    </tr></thead>
    <tbody>
    @forelse($L as $i => $l)
        @php $err = $fmt::controles($l, $d->champs ?? []); $auto = !empty($l['auto']) && empty($print) ? '<span class="badge-blue ml-1">auto</span>' : ''; @endphp
        <tr class="{{ empty($print) ? '' : 'border-b' }} align-top">
            <td class="{{ $th }} text-xs text-slate-500">{{ $i + 1 }}</td>
            @if($sach)
                <td class="{{ $th }} font-medium">{{ $l['nom'] }} {!! $auto !!}<div class="font-mono text-xs font-normal text-slate-500">{{ $l['id'] ?? '' }}</div>
                    @if(!empty($l['objet']))<div class="text-xs font-normal text-slate-500">{{ \Illuminate\Support\Str::limit($l['objet'], 80) }}</div>@endif</td>
                <td class="{{ $th }} text-xs">{{ $l['att'] ?? '' }}{{ !empty($l['att_date']) ? ' du '.dfr($l['att_date']) : '' }}</td>
                <td class="{{ $th }} text-xs">{{ $l['fac'] }} du {{ dfr($l['fac_date']) }}</td>
            @else
                <td class="{{ $th }} text-xs">{{ $l['fac'] }} du {{ dfr($l['fac_date']) }}</td>
                <td class="{{ $th }} font-medium">{{ $l['nom'] }} {!! $auto !!}<div class="font-mono text-xs font-normal text-slate-500">{{ $idk[$l['idk'] ?? ''] ?? '?' }} {{ $l['id'] ?? '' }}</div>
                    @if(!empty($l['adr']))<div class="text-xs font-normal text-slate-500">{{ $l['adr'] }}</div>@endif</td>
                <td class="{{ $th }} text-xs">{{ $l['att'] ?? '' }}{{ !empty($l['att_date']) ? ' du '.dfr($l['att_date']) : '' }}</td>
            @endif
            <td class="num {{ $th }} text-right font-mono">{{ tnd($l['ht'], false) }}</td>
            @unless($sach)
                <td class="num {{ $th }} text-right font-mono">{{ (float) ($l['fodec'] ?? 0) ? tnd($l['fodec'], false) : '' }}<div class="text-[11px] text-slate-400">{{ $pct($l['fodec_taux'] ?? 0) }}</div></td>
                <td class="num {{ $th }} text-right font-mono">{{ (float) ($l['dc'] ?? 0) ? tnd($l['dc'], false) : '' }}<div class="text-[11px] text-slate-400">{{ $pct($l['dc_taux'] ?? 0) }}</div></td>
            @endunless
            <td class="num {{ $th }} text-right font-mono">{{ tnd($l['tva'], false) }}<div class="text-[11px] text-slate-400">{{ $pct($l['taux'] ?? 0) }}</div></td>
            <td class="{{ $th }} text-xs">@if(empty($print)) @if($err) @foreach($err as $e)<div class="text-rose-600">{{ $e }}</div>@endforeach @else<span class="text-emerald-600">Conforme</span>@endif @elseif($sach) {{ ($l['aut'] ?? '') ?: ($d->champs['aut'] ?? '') }} @endif</td>
            @if($edit)<td><form method="POST" action="{{ route('declarations.suspension.destroy') }}">@include('declarations._hidden') @method('DELETE')<input type="hidden" name="i" value="{{ $i }}"><button class="btn-icon hover:!text-rose-600" title="Supprimer"><x-icon name="trash" class="h-4 w-4"/></button></form></td>@endif
        </tr>
    @empty
        <tr><td colspan="{{ ($sach ? 7 : 9) + ($edit ? 1 : 0) }}">@if(empty($print))<x-empty icon="list" title="Aucune ligne">Ajoutez une ligne par facture {{ $sach ? 'd’achat' : 'de vente' }} en suspension de TVA, ou utilisez « Pré-remplir ».</x-empty>@else Néant. @endif</td></tr>
    @endforelse
    </tbody>
    <tfoot><tr class="{{ empty($print) ? '' : 'font-semibold' }}"><td class="{{ $th }}" colspan="4">Total ({{ $tot['n'] }} ligne{{ $tot['n'] > 1 ? 's' : '' }})</td>
        <td class="num {{ $th }} text-right font-mono">{{ tnd($tot['ht'], false) }}</td>
        @unless($sach)<td class="num {{ $th }} text-right font-mono">{{ tnd($tot['fodec'], false) }}</td><td class="num {{ $th }} text-right font-mono">{{ tnd($tot['dc'], false) }}</td>@endunless
        <td class="num {{ $th }} text-right font-mono">{{ tnd($tot['tva'], false) }}</td><td></td>@if($edit)<td></td>@endif</tr></tfoot>
</table>
