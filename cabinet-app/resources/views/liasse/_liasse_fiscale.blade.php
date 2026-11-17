{{--
    Liasse fiscale — cahier des charges CIMF (cas général) : entête du dépôt, documents F6001 à F6005 (montants de la balance,
    remplaçables par saisie ; totaux recalculés), contrôles de cohérence et fichiers XML [CODE]-[MATRICULE]-[EXERCICE].xml.
--}}
@php
    use App\Services\LiasseFiscale;
    $def = LiasseFiscale::document($doc);
    $vals = $lf['docs'][$doc];
    $saisies = $lf['saisies'][$doc] ?? [];
    $base = $lf['balance'][$doc] ?? [];
    $modifiables = LiasseFiscale::SAISIE[$doc];
    $calculees = ['F6005' => [2, 55, 56, 61, 63, 68, 80, 98, 101, 102, 107], 'F6002' => [7]][$doc] ?? [];
    $ok = collect($lf['controles'])->every(fn ($c) => $c[1]);
    $fmt = fn ($v) => \App\Services\EtatsFinanciers::montant($v);
@endphp

<div class="mb-4 grid gap-4 xl:grid-cols-3">
    {{-- Entête du dépôt --}}
    <form method="POST" action="{{ route('liasse.lf.entete') }}" class="card xl:col-span-2">@csrf
        <input type="hidden" name="annee" value="{{ $annee }}">
        <div class="card-h"><h2 class="card-t">Entête du dépôt</h2>
            <span class="text-xs text-slate-500">{{ $client->nom }} · MF {{ LiasseFiscale::matricule($client->matricule_fiscal) ?? ($client->matricule_fiscal ?: 'non renseigné') }}</span></div>
        <div class="card-b grid gap-3 text-sm md:grid-cols-4">
            <label class="md:col-span-2">Activité<input name="activite" value="{{ $entete['activite'] }}" maxlength="250" class="input mt-1 w-full"></label>
            <label class="md:col-span-2">Adresse<input name="adresse" value="{{ $entete['adresse'] }}" maxlength="250" class="input mt-1 w-full"></label>
            <label>Début d’exercice<input name="debut" value="{{ $entete['debut'] }}" placeholder="JJ/MM/AAAA" class="input mt-1 w-full"></label>
            <label>Clôture<input name="cloture" value="{{ $entete['cloture'] }}" placeholder="JJ/MM/AAAA" class="input mt-1 w-full"></label>
            <label>Acte de dépôt
                <select name="acte" class="input mt-1 w-full">@foreach(['0' => 'Spontané', '1' => 'Rectification', '2' => 'Régularisation'] as $k => $l)<option value="{{ $k }}" @selected((string) $entete['acte'] === (string) $k)>{{ $l }}</option>@endforeach</select></label>
            <label>Nature du dépôt
                <select name="nature" class="input mt-1 w-full">@foreach(['D' => 'Définitif', 'P' => 'Provisoire'] as $k => $l)<option value="{{ $k }}" @selected($entete['nature'] === $k)>{{ $l }}</option>@endforeach</select></label>
            <label>Catégorie (F6005)
                <select name="categorie" class="input mt-1 w-full">@foreach(['M' => 'M — personne morale', 'C' => 'C — personne physique commerciale', 'P' => 'P — profession libérale', 'N' => 'N — non soumis'] as $k => $l)<option value="{{ $k }}" @selected($entete['categorie'] === $k)>{{ $l }}</option>@endforeach</select></label>
            <label>Forme juridique (F6005)
                <select name="forme" class="input mt-1 w-full">@foreach(['SC' => 'SC — société de capitaux', 'SP' => 'SP — société de personnes', 'PP' => 'PP — personne physique'] as $k => $l)<option value="{{ $k }}" @selected($entete['forme'] === $k)>{{ $l }}</option>@endforeach</select></label>
            <label>Montants XML exprimés en
                <select name="unite" class="input mt-1 w-full">@foreach(['millimes' => 'millimes (sans décimale)', 'dinars' => 'dinars arrondis'] as $k => $l)<option value="{{ $k }}" @selected($entete['unite'] === $k)>{{ $l }}</option>@endforeach</select></label>
            <div class="flex items-end md:col-span-3 md:justify-end"><button class="btn-primary">Enregistrer l’entête</button></div>
        </div>
    </form>

    {{-- Contrôles et fichiers --}}
    <div class="card card-b space-y-3 self-start text-sm">
        <div class="flex items-center justify-between"><h3 class="card-t">Contrôles</h3>
            <span class="{{ $ok ? 'badge-green' : 'badge-amber' }}">{{ $ok ? 'Liasse cohérente' : 'À vérifier' }}</span></div>
        <ul class="space-y-1.5">
            @foreach($lf['controles'] as [$libelle, $bon, $detail])
                <li class="flex gap-2"><span class="{{ $bon ? 'text-emerald-600' : 'text-rose-600' }}">{{ $bon ? '✓' : '✗' }}</span>
                    <span>{{ $libelle }}@if($detail)<span class="block text-xs text-slate-400">{{ $detail }}</span>@endif</span></li>
            @endforeach
        </ul>
        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
            <form method="POST" action="{{ route('liasse.lf.valider') }}">@csrf
                <input type="hidden" name="annee" value="{{ $annee }}"><input type="hidden" name="doc" value="{{ $doc }}">
                <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Valider</button>
            </form>
            <a href="{{ route('liasse.lf.zip', ['annee' => $annee]) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Liasse complète (ZIP)</a>
            <a href="{{ route('liasse.lf.xml', ['doc' => $doc, 'annee' => $annee]) }}" class="btn-secondary">{{ LiasseFiscale::nomFichier($doc, $client, $annee) }}</a>
        </div>
        @php $xsd = collect(LiasseFiscale::DOCUMENTS)->filter(fn ($c) => LiasseFiscale::xsd($c)); @endphp
        <form method="POST" action="{{ route('liasse.lf.xsd') }}" enctype="multipart/form-data" class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">@csrf
            <div class="text-slate-500">Schémas XSD officiels :
                @if($xsd->count())<b class="text-emerald-700">{{ $xsd->map(fn ($c) => LiasseFiscale::schema($c).(LiasseFiscale::xsdDepose($c) ? ' (déposé)' : ''))->implode(', ') }}</b>@else<b>aucun déposé</b> (contrôle selon le cahier des charges uniquement)@endif
                <span class="block text-slate-400">Les schémas de la DGI (XSD 1.1 : types, formules de totalisation et règles du tableau F6005) sont livrés avec l’application ; un schéma déposé ici les remplace, par exemple pour une nouvelle version.</span></div>
            <div class="flex items-center gap-2"><input type="file" name="fichiers[]" multiple accept=".xsd,.zip" class="text-xs" required>
                <button class="btn-secondary !py-1 text-xs">Déposer</button></div>
            @error('fichiers')<div class="text-rose-600">{{ $message }}</div>@enderror
        </form>
        <p class="text-xs text-slate-400">F6006 (notes) et F6007 (faits marquants) ne sont pas générés : à joindre lors du dépôt sur le portail.</p>
    </div>
</div>

@if($validation = session('validation'))
    <div class="card mb-4">
        <div class="card-h"><h2 class="card-t">Résultat de la validation — exercice {{ $annee }}</h2>
            @if(session('validation_ko'))<span class="badge-red">{{ session('validation_ko') }}</span>@else<span class="badge-green">Fichiers conformes</span>@endif</div>
        <div class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            @foreach($validation as $code => $v)
                <div class="px-5 py-2">
                    <div class="flex items-center gap-2">
                        <span class="{{ $v['erreurs'] ? 'text-rose-600' : 'text-emerald-600' }}">{{ $v['erreurs'] ? '✗' : '✓' }}</span>
                        <b class="font-mono">{{ $v['fichier'] }}</b>
                        <span class="text-xs text-slate-400">{{ $v['xsd'] ? 'schéma XSD officiel + cahier des charges' : 'cahier des charges (schéma XSD non déposé)' }}</span>
                        <span class="ml-auto text-xs {{ $v['erreurs'] ? 'text-rose-600' : 'text-emerald-600' }}">{{ $v['erreurs'] ? count($v['erreurs']).' anomalie(s)' : 'conforme' }}</span>
                    </div>
                    @if($v['erreurs'])
                        <ul class="mt-1 list-disc space-y-0.5 pl-10 text-xs text-rose-700">
                            @foreach(array_slice($v['erreurs'], 0, 40) as $e)<li>{{ $e }}</li>@endforeach
                            @if(count($v['erreurs']) > 40)<li>… et {{ count($v['erreurs']) - 40 }} autre(s)</li>@endif
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

@if($lf['vide'])<div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Aucun solde pour {{ $annee }} : les montants peuvent être saisis directement.</div>@endif

{{-- Documents --}}
<div class="mb-3 flex flex-wrap gap-2">
    @foreach(LiasseFiscale::DOCUMENTS as $code)
        <a href="{{ route('liasse.index', ['tab' => 'lf', 'doc' => $code, 'annee' => $annee]) }}"
           class="rounded-lg px-3 py-1.5 text-sm {{ $code === $doc ? 'bg-brand-600 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700' }}">
            <b>{{ $code }}</b> {{ LiasseFiscale::document($code)['titre'] }}</a>
    @endforeach
</div>

<form method="POST" action="{{ route('liasse.lf.saisie') }}" class="card">@csrf
    <input type="hidden" name="annee" value="{{ $annee }}"><input type="hidden" name="doc" value="{{ $doc }}">
    <div class="card-h"><h2 class="card-t">{{ $doc }} — {{ $def['titre'] }} · exercice {{ $annee }}</h2>
        <span class="text-xs text-slate-500">Zone vide = montant de la balance (en grisé). Les totaux sont recalculés.</span></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800/50">
                <tr><th class="px-3 py-2 text-left">Code</th><th class="px-3 py-2 text-left">Libellé</th>
                    @foreach($def['colonnes'] as $col => $c)<th class="px-3 py-2 text-right">{{ $c[1] }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach($def['lignes'] as $l => $ligne)
                    @php $total = ! empty($ligne[1]) || in_array($l, $calculees, true); $chiffre = reset($def['colonnes'])[0]; @endphp
                    <tr class="{{ $total ? 'bg-slate-50/60 font-semibold dark:bg-slate-800/30' : '' }}">
                        <td class="whitespace-nowrap px-3 py-1 font-mono text-xs text-slate-400">{{ sprintf('%s%s%03d', $doc, $chiffre, $l) }}</td>
                        <td class="px-3 py-1">{{ $ligne[0] }}</td>
                        @foreach($def['colonnes'] as $col => $c)
                            <td class="px-3 py-1 text-right font-mono">
                                @if(! $total && in_array($col, $modifiables, true))
                                    @php $s = $saisies[$col][$l] ?? null; $b = $base[$col][$l] ?? null; @endphp
                                    <input type="number" step="0.001" name="v[{{ $col }}][{{ $l }}]" value="{{ $s }}" placeholder="{{ $b !== null ? number_format($b, 3, '.', '') : ($doc === 'F6005' || $doc === 'F6004' ? number_format($vals[$col][$l] ?? 0, 3, '.', '') : '0.000') }}"
                                           class="input-sm w-36 text-right {{ $s !== null ? 'ring-2 ring-amber-300' : '' }}" title="{{ $s !== null ? 'Montant saisi (balance : '.$fmt($b ?? 0).')' : 'Montant de la balance' }}">
                                @else
                                    {{ $fmt($vals[$col][$l] ?? 0) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-100 p-4 dark:border-slate-800">
        <button name="reinit" value="1" class="btn-secondary" onclick="return confirm('Effacer les montants saisis de {{ $doc }} et reprendre ceux de la balance ?')">Reprendre la balance</button>
        <button class="btn-primary">Enregistrer {{ $doc }}</button>
    </div>
</form>
