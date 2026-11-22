{{-- Fenêtres des documents des menus Prêt, Acompte et Congé : échéancier de prêt du salarié, reçus d'avance sur salaire, demande de congé. --}}
@php
    $avecPret = \App\Models\Pret::where('client_id', $client->id)->distinct()->pluck('salarie_id')->all();
    $salPrets = \App\Services\PaieCalc::trier($salaries->whereIn('id', $avecPret), 'nom');
    $acomptesMois = \App\Models\Acompte::with('salarie')->where('client_id', $client->id)->where('mois', $mois)->orderBy('date')->get();
    $salConges = \App\Services\PaieCalc::trier($salaries->filter(fn ($e) => \App\Services\PaieCalc::payeLeMois($e, $mois)), 'nom');
@endphp

<x-modal name="paie-echeancier-salarie" title="Échéancier de prêt (salarié)">
    <form method="GET" action="{{ route('paie.prets.echeancier-salarie') }}" target="_blank" class="space-y-4">
        <div><label class="label">Salarié</label>
            <select name="salarie" class="input" required><option value="">— Choisir —</option>@foreach($salPrets as $x)<option value="{{ $x->id }}">{{ $x->matricule ? $x->matricule.' — ' : '' }}{{ $x->nomComplet() }}</option>@endforeach</select>
            @if($salPrets->isEmpty())<p class="mt-1 text-xs text-slate-400">Aucun prêt saisi dans ce dossier.</p>@endif</div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Prêts</label><select name="type" class="input"><option value="tous">Tous les prêts</option>@foreach(\App\Models\Pret::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
            <div><label class="label">Situation à fin</label><input type="month" name="mois" value="{{ $mois }}" class="input"></div>
        </div>
        <p class="text-xs text-slate-400">Chaque prêt du salarié avec ses échéances mensuelles, leur état (retenue, à venir) et le reste dû, à faire signer par le salarié.</p>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Afficher</button></div>
    </form>
</x-modal>

<x-modal name="paie-recus-avance" title="Reçus d’avance sur salaire — {{ mois_fr($mois) }}">
    <div class="space-y-3">
        <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-800">
            @forelse($acomptesMois as $a)
                <a href="{{ route('paie.acomptes.recu', $a) }}" target="_blank" class="flex items-center justify-between border-b border-slate-100 px-4 py-2 text-sm last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800">
                    <span>{{ $a->salarie?->nomComplet() }} <span class="text-xs text-slate-500">— {{ $a->date->format('d/m/Y') }}</span></span>
                    <span class="flex items-center gap-2 font-mono">{{ tnd($a->montant) }} <x-icon name="printer" class="h-4 w-4 text-slate-400"/></span>
                </a>
            @empty <div class="px-4 py-6 text-center text-sm text-slate-400">Aucun acompte retenu sur la paie de {{ mois_fr($mois) }}.</div> @endforelse
        </div>
        <p class="text-xs text-slate-400">Acomptes retenus sur la paie du mois de la barre d’outils. Le reçu est aussi proposé dès l’enregistrement d’un acompte (Acompte → Saisie des acomptes).</p>
        <div class="flex justify-end"><a href="{{ route('paie.index', ['tab' => 'acomptes', 'mois' => $mois]) }}" class="btn-secondary"><x-icon name="plus" class="h-4 w-4"/> Saisir un acompte</a></div>
    </div>
</x-modal>

<x-modal name="paie-demande-conge" title="Demande de congé">
    <form method="GET" action="{{ route('paie.conges.demande') }}" target="_blank" class="space-y-4">
        <input type="hidden" name="mois" value="{{ $mois }}">
        <div><label class="label">Salarié</label>
            <select name="salarie" class="input" required><option value="">— Choisir —</option>@foreach($salConges as $x)<option value="{{ $x->id }}">{{ $x->matricule ? $x->matricule.' — ' : '' }}{{ $x->nomComplet() }}</option>@endforeach</select></div>
        <div><label class="label">Type de congé</label><select name="type" class="input">@foreach(\App\Models\Conge::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Du</label><input type="date" name="du" class="input"></div>
            <div><label class="label">Au</label><input type="date" name="au" class="input"></div>
        </div>
        <p class="text-xs text-slate-400">Formulaire à faire remplir et signer par le salarié (période laissée en blanc si elle n’est pas indiquée), avec son solde de congés payés et la décision de l’employeur. Rien n’est enregistré : le congé accordé se saisit dans Congé → Saisie des congés, d’où sa demande peut aussi être imprimée.</p>
        <div class="flex justify-end"><button class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Afficher</button></div>
    </form>
</x-modal>
