{{-- Fenêtres du menu Employé : afficher un employé, documents RH (choix de l'employé et de la période). --}}
@php
    $tous = \App\Services\PaieCalc::trier(\App\Models\Salarie::where('client_id', $client->id)->get(), $P['tri'] ?? 'nom');
    $docs = [
        'attestation-travail' => ['Attestation de travail', 'actifs', null, false],
        'attestation-salaire' => ['Attestation de salaire mensuelle', 'actifs', 'mois', false],
        'attestation-salaire-annuelle' => ['Attestation de salaire annuelle', 'actifs', 'dernier', false],
        'certificat-travail' => ['Certificat de travail', 'tous', null, false],
        'certificat-retenue' => ['Certificat de retenue', 'tous', 'annee', false],
        'certificat-retenue-pdf' => ['Certificat de retenue (PDF)', 'tous', 'annee', true],
        'attestation-cnss' => ['Attestation des salaires déclarés à la CNSS', 'tous', 'annee', false],
        'recu-vetements' => ['Reçu de vêtements de travail', 'actifs', 'annee', false],
        'contrat' => ['Contrat de travail', 'tous', null, false],
        'demission' => ['Lettre de démission', 'tous', null, false],
    ];
@endphp

<x-modal name="paie-afficher" title="Afficher un employé">
    <div x-data="{ q: '' }" class="space-y-3">
        <input type="search" x-model="q" placeholder="Nom, matricule ou CIN…" class="input" autofocus>
        <div class="max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
            @forelse($tous as $x)
                <a href="{{ route('paie.employes.edit', $x) }}" x-show="@js(mb_strtolower($x->nomComplet().' '.$x->matricule.' '.$x->cin)).includes(q.toLowerCase())"
                   class="flex items-center justify-between border-b border-slate-100 px-4 py-2 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800">
                    <span><span class="mr-2 font-mono text-xs text-slate-400">{{ $x->matricule }}</span>{{ $x->nomComplet() }} <span class="text-xs text-slate-500">{{ $x->poste }}</span></span>
                    @unless($x->estActif())<span class="badge-gray">sorti</span>@endunless
                </a>
            @empty <div class="px-4 py-6 text-center text-sm text-slate-400">Aucun employé.</div> @endforelse
        </div>
    </div>
</x-modal>

@foreach($docs as $cle => [$titre, $qui, $periode, $pdf])
    @php $liste = $qui === 'actifs' ? $tous->filter->estActif() : $tous; $type = str_replace('-pdf', '', $cle); $groupe = $type === 'certificat-retenue'; @endphp
    <x-modal :name="'paie-rh-'.$cle" :title="$titre">
        {{-- Certificat de retenue : un employé ou tous les employés payés dans l'année (un certificat par page). --}}
        <form method="GET" target="_blank" class="space-y-4" x-data="{ s: '' }" :action="s === 'tous' ? @js(route('paie.employes.rh-tous', $type)) : @js(route('paie.employes.rh', ['__ID__', $type])).replace('__ID__', s)">
            <div><label class="label">Employé</label>
                <select x-model="s" class="input" required><option value="">— Choisir —</option>@if($groupe)<option value="tous">Tous les employés</option>@endif @foreach($liste as $x)<option value="{{ $x->id }}">{{ $x->matricule }} — {{ $x->nomComplet() }}</option>@endforeach</select></div>
            @if($periode === 'annee')<div><label class="label">Année</label><input type="number" name="annee" min="2000" max="2100" value="{{ substr($mois, 0, 4) }}" class="input w-32"></div>@endif
            @if($periode === 'mois')<div><label class="label">Salaire du mois de</label><input type="month" name="mois" value="{{ $mois }}" class="input w-48"></div>@endif
            @if($periode === 'dernier')<div><label class="label">Dernier salaire connu au mois de</label><input type="month" name="mois" value="{{ $mois }}" class="input w-48">
                <p class="mt-1 text-xs text-slate-400">Le salaire annuel est le dernier salaire mensuel multiplié par 12.</p></div>@endif
            @if($pdf)<input type="hidden" name="pdf" value="1">@endif
            <p class="text-xs text-slate-400">@if($pdf) Le certificat est téléchargé au format PDF{{ $groupe ? ' (tous les employés : un certificat par page)' : '' }}. @else Le document s’ouvre dans un nouvel onglet : le texte peut être retouché avant l’impression. @endif</p>
            <div class="flex justify-end"><button class="btn-primary" :disabled="!s"><x-icon name="{{ $pdf ? 'download' : 'printer' }}" class="h-4 w-4"/> {{ $pdf ? 'Télécharger' : 'Afficher' }}</button></div>
        </form>
    </x-modal>
@endforeach
