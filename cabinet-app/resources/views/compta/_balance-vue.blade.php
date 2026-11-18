{{-- Balance selon la vue choisie : générale, auxiliaire (tiers), centralisée ou comparative. $lien = false à l'impression. --}}
@php
    $lien ??= true;
    $gl = fn ($c, $exact = true) => route('compta.index', ['tab' => 'gl', 'annee' => $annee, 'compte' => $c, 'exact' => $exact ? 1 : 0]);
    $cpt = fn ($c, $exact = true) => $lien ? '<a href="'.e($gl($c, $exact)).'" class="hover:underline">'.e($c).'</a>' : e($c);
@endphp

@if($vue === 'generale')
    @include('compta._balance')

@elseif($vue === 'centralisee')
    @include('compta._balance', ['exact' => false])
    <p class="mt-2 text-xs text-slate-500">Comptes regroupés sur leurs {{ $niveau }} premiers chiffres : reports et mouvements additionnés, solde net du groupe. Double-clic : grand livre du groupe.</p>

@elseif($vue === 'auxiliaire')
    <div class="card overflow-x-auto print-area">
        <table class="tbl min-w-[900px]">
            <thead>
                <tr><th rowspan="2">Compte</th><th rowspan="2">Tiers</th><th colspan="2" class="!text-center">Report à nouveau</th><th colspan="2" class="!text-center">Mouvements</th><th colspan="2" class="!text-center">Soldes</th></tr>
                <tr><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Débiteur</th><th class="text-right">Créditeur</th></tr>
            </thead>
            @forelse($sections as $s)
                <tbody>
                    <tr class="bg-slate-50 dark:bg-slate-800/40"><td colspan="8" class="font-semibold">{{ $s['titre'] }}</td></tr>
                    @foreach($s['lignes'] as $b)
                        <tr @if($lien) class="cursor-pointer hover:bg-brand-50/60 dark:hover:bg-slate-800/40" ondblclick="window.location = {{ \Illuminate\Support\Js::from($gl($b['compte'])) }}" @endif>
                            <td class="font-mono text-xs">{!! $cpt($b['compte']) !!}</td><td>{{ $b['libelle'] }}</td>@foreach(['rd','rc','md','mc','sd','sc'] as $k)<td class="num">{{ n3($b[$k]) }}</td>@endforeach</tr>
                    @endforeach
                    <tr class="border-t border-slate-300 font-semibold"><td></td><td>Total {{ mb_strtolower($s['titre']) }}</td>@foreach(['rd','rc','md','mc','sd','sc'] as $k)<td class="num">{{ tnd($s['T'][$k], false) }}</td>@endforeach</tr>
                </tbody>
            @empty
                <tbody><tr><td colspan="8"><x-empty icon="users" title="Aucun compte de tiers (40, 41) mouvementé sur {{ $annee }}"/></td></tr></tbody>
            @endforelse
            @if($sections)
                <tfoot class="border-t-2 border-slate-300"><tr><td colspan="2">Totaux des comptes de tiers</td>@foreach(['rd','rc','md','mc','sd','sc'] as $k)<td class="num">{{ tnd($T[$k], false) }}</td>@endforeach</tr></tfoot>
            @endif
        </table>
    </div>

@elseif($vue === 'comparative')
    <div class="card overflow-x-auto print-area">
        <table class="tbl min-w-[900px]">
            <thead>
                <tr><th rowspan="2">Compte</th><th rowspan="2">Libellé</th><th colspan="2" class="!text-center">Solde {{ $annee }}</th><th colspan="2" class="!text-center">Solde {{ $annee - 1 }}</th><th colspan="2" class="!text-center">Variation</th></tr>
                <tr><th class="text-right">Débiteur</th><th class="text-right">Créditeur</th><th class="text-right">Débiteur</th><th class="text-right">Créditeur</th><th class="text-right">Montant</th><th class="text-right">%</th></tr>
            </thead>
            <tbody>
            @forelse($C as $c)
                <tr @if($lien) class="cursor-pointer hover:bg-brand-50/60 dark:hover:bg-slate-800/40" ondblclick="window.location = {{ \Illuminate\Support\Js::from($gl($c['compte'])) }}" @endif>
                    <td class="font-mono text-xs">{!! $cpt($c['compte']) !!}</td><td>{{ $c['libelle'] }}</td>
                    @foreach(['nd','nc','pd','pc'] as $k)<td class="num">{{ n3($c[$k]) }}</td>@endforeach
                    <td class="num {{ $c['var'] > 0 ? 'text-emerald-700' : ($c['var'] < 0 ? 'text-rose-700' : '') }}">{{ n3($c['var']) }}</td>
                    <td class="num">{{ $c['pct'] === null ? '—' : number_format($c['pct'], 1, ',', ' ').' %' }}</td>
                </tr>
            @empty <tr><td colspan="8"><x-empty icon="book" title="Aucun solde sur {{ $annee }} ni sur {{ $annee - 1 }}"/></td></tr> @endforelse
            </tbody>
            @if($C)
                <tfoot class="border-t-2 border-slate-300"><tr><td colspan="2">Totaux</td>@foreach(['nd','nc','pd','pc'] as $k)<td class="num">{{ tnd($T[$k], false) }}</td>@endforeach<td colspan="2"></td></tr></tfoot>
            @endif
        </table>
        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">Variation = solde {{ $annee }} − solde {{ $annee - 1 }}, en sens débiteur (positive : solde débiteur en hausse ou créditeur en baisse). Les comptes de charges et de produits {{ $annee - 1 }} sont ceux de l’exercice {{ $annee - 1 }}.</p>
    </div>
@endif
