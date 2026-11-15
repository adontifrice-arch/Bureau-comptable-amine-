{{-- Onglet « États financiers » de la liasse : informations de présentation, contrôle, aperçu et PDF --}}
@php $m = fn ($v) => \App\Services\EtatsFinanciers::montant($v); $A = $ef['N']['A']; $P = $ef['N']['P']; $R = $ef['N']['R']; @endphp
<div class="grid gap-6 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <div class="card-h"><h2 class="card-t">États financiers arrêtés au 31/12/{{ $annee }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('liasse.etats-financiers', ['annee' => $annee]) }}" target="_blank" class="btn-secondary btn-sm"><x-icon name="eye" class="h-3.5 w-3.5"/> Aperçu / imprimer</a>
                <a href="{{ route('liasse.etats-financiers', ['annee' => $annee, 'format' => 'pdf']) }}" target="_blank" class="btn-primary btn-sm"><x-icon name="download" class="h-3.5 w-3.5"/> PDF</a>
            </div>
        </div>
        <div class="card-b space-y-3 text-sm">
            <p class="text-slate-500">Document complet au modèle du cabinet : page de garde, sommaire, bilan (actifs A1–A9, capitaux propres et passifs CP1–CP6), état de résultat et état des flux de trésorerie (modèle autorisé), notes aux états financiers avec le détail de chaque rubrique (N, N-1, variation). Établi depuis la comptabilité du dossier.</p>
            @if($ef['vide'])<div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-amber-800">Aucune écriture comptabilisée pour {{ $annee }} : les états seront à zéro.</div>@endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40"><div class="text-xs text-slate-500">Total des actifs</div><div class="font-mono text-base font-semibold">{{ $m($A['total']) }}</div></div>
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40"><div class="text-xs text-slate-500">Total des capitaux propres & passifs</div><div class="font-mono text-base font-semibold">{{ $m($P['total']) }}</div></div>
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40"><div class="text-xs text-slate-500">Résultat net de l’exercice</div><div class="font-mono text-base font-semibold {{ $R['rn'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $m($R['rn']) }}</div></div>
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40"><div class="text-xs text-slate-500">Variation de trésorerie</div><div class="font-mono text-base font-semibold">{{ $m($ef['F']['variation']) }}</div></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="{{ $ef['equilibre'] ? 'badge-green' : 'badge-red' }}">{{ $ef['equilibre'] ? 'Bilan équilibré' : 'Écart actif / passif : '.$m($A['total'] - $P['total']) }}</span>
                <span class="badge-gray">{{ count($ef['notes']) }} note(s) de détail</span>
                @if(abs($ef['F']['autres_postes']) > 0.0005)<span class="badge-amber" title="Variations du bilan non classées dans les flux (placements, écarts…)">Autres postes des flux : {{ $m($ef['F']['autres_postes']) }}</span>@endif
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('liasse.etats-financiers.infos') }}" class="card card-b space-y-3 self-start">@csrf
        <h2 class="card-t">Présentation de la société</h2>
        <p class="text-xs text-slate-500">Reprise dans la note 1, avec la raison sociale, l’identifiant, la forme juridique et le capital de la fiche client.</p>
        <div><label class="label" for="ef-act">Activité</label><input id="ef-act" name="activite" value="{{ $infos['activite'] }}" class="input" placeholder="ex. Restaurant" maxlength="255"></div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="ef-cre">Créée le</label><input id="ef-cre" type="date" name="creation" value="{{ $infos['creation'] }}" class="input"></div>
            <div><label class="label" for="ef-ent">Entrée en activité</label><input id="ef-ent" type="date" name="entree" value="{{ $infos['entree'] }}" class="input"></div>
        </div>
        <div><label class="label" for="ef-eff">Effectif moyen</label><input id="ef-eff" type="number" min="0" name="effectif" value="{{ $infos['effectif'] }}" class="input w-32"></div>
        <button class="btn-primary w-full">Enregistrer</button>
    </form>
</div>
