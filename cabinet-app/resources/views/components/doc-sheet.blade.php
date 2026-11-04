@props(['titre', 'numero' => null, 'sig' => null, 'landscape' => false])
{{-- Feuille A4 imprimable aux couleurs du cabinet --}}
<div {{ $attributes->merge(['class' => 'print-area mx-auto rounded-2xl border border-slate-200 bg-white p-10 text-[13px] text-slate-900 shadow-xl shadow-slate-900/5 '.($landscape ? 'max-w-6xl' : 'max-w-4xl')]) }}>
    <div class="flex items-start justify-between gap-6 border-b-2 border-brand-600 pb-5">
        <div>
            @if(!empty($cab['logo']))<img src="{{ asset('storage/'.$cab['logo']) }}" alt="" class="mb-2 max-h-16 max-w-48">@endif
            <div class="text-lg font-bold text-brand-800">{{ $cab['nom'] }}</div>
            <div class="mt-1 space-y-0.5 text-xs text-slate-600">
                @if($cab['adresse'])<div>{{ $cab['adresse'] }}</div>@endif
                @if($cab['mf'])<div>Matricule fiscal : {{ $cab['mf'] }}</div>@endif
                @if($cab['telephone'] || $cab['email'])<div>{{ $cab['telephone'] }} {{ $cab['email'] ? ' · '.$cab['email'] : '' }}</div>@endif
            </div>
        </div>
        <div class="text-right">
            <div class="text-2xl font-bold tracking-wide text-slate-800">{{ $titre }}</div>
            @if($numero)<div class="mt-1 font-mono text-sm text-slate-600">N° {{ $numero }}</div>@endif
        </div>
    </div>
    <div class="py-6">{{ $slot }}</div>
    @if($sig)
        <div class="mt-8 grid grid-cols-2 gap-8 text-xs text-slate-500">
            <div><div class="mb-1">{{ $sig }}</div><div class="h-20 rounded-lg border border-slate-300"></div></div>
            <div><div class="mb-1">Signature et cachet du cabinet</div><div class="h-20 rounded-lg border border-slate-300"></div></div>
        </div>
    @endif
    <div class="mt-8 border-t border-slate-200 pt-3 text-center text-xs italic text-slate-500">{{ $cab['slogan'] }}</div>
</div>
