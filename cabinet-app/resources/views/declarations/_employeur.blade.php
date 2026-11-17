{{-- Déclaration d'employeur (cahier des charges DGI) : déposant, tableau récapitulatif DECEMP, annexes I à VII. --}}
@php
    $E = \App\Services\Exports\DeclarationEmployeur::class;
    $an = (int) $periode; $h = $d->champs ?? [];
    $recap = $E::lignesRecap($d); $calcR = $E::recapitulatif($d->annexes ?? []);
    $errEntete = $E::controlesEntete($client, $h);
    $errAx = collect($E::ANNEXES)->map(fn ($t, $k) => collect($d->annexes[$k] ?? [])->filter(fn ($l) => $E::controlesLigne($k, $l, $an))->count());
    $axs = ['rec' => 'Récapitulatif'] + collect($E::ANNEXES)->mapWithKeys(fn ($t, $k) => [$k => 'Annexe '.['a1' => 'I', 'a2' => 'II', 'a3' => 'III', 'a4' => 'IV', 'a5' => 'V', 'a6' => 'VI', 'a7' => 'VII'][$k].' ('.count($d->annexes[$k] ?? []).')'])->all();
    $num = fn ($v) => number_format((float) $v, 3, '.', '');
@endphp
<div class="mb-4 flex flex-wrap gap-1">@foreach($axs as $k => $l)<a href="{{ route('declarations.index', $q + ['ax' => $k]) }}" class="{{ $ax === $k ? 'badge-blue' : 'badge-gray' }} !py-1.5 !px-3">{{ $l }}@if($k !== 'rec' && $errAx[$k]) <span class="text-rose-600">· {{ $errAx[$k] }} à corriger</span>@endif</a>@endforeach</div>

@if($ax === 'rec')
    @if($errEntete || $errAx->sum())
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <div class="font-semibold">Contrôles du cahier des charges</div>
            <ul class="mt-1 list-disc pl-5">@foreach($errEntete as $e)<li>{{ $e }}</li>@endforeach
                @foreach($errAx as $k => $n) @if($n)<li><a class="underline" href="{{ route('declarations.index', $q + ['ax' => $k]) }}">{{ $axs[$k] }}</a> : {{ $n }} ligne(s) non conforme(s).</li>@endif @endforeach</ul>
        </div>
    @endif
    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('declarations.save') }}" class="card self-start">@include('declarations._hidden')
            <div class="card-h"><h2 class="card-t">Déposant</h2></div>
            <div class="card-b grid gap-3 text-sm">
                <div class="text-xs text-slate-500">{{ $client->nom }} · matricule {{ $client->matricule_fiscal ?: '—' }}</div>
                <x-select label="Code acte" name="e[acte]" :value="$h['acte'] ?? '0'" :options="['0' => 'Spontané', '1' => 'Régularisation', '2' => 'Redressement']"/>
                <x-field label="Activité (40 car.)" name="e[activite]" :value="$h['activite'] ?? ''" maxlength="40"/>
                <x-field label="Ville" name="e[ville]" :value="$h['ville'] ?? ''" maxlength="40"/>
                <x-field label="Rue" name="e[rue]" :value="$h['rue'] ?? ''" maxlength="72"/>
                <div class="grid grid-cols-2 gap-3"><x-field label="Numéro" name="e[num]" :value="$h['num'] ?? ''" maxlength="4" inputmode="numeric"/><x-field label="Code postal" name="e[cp]" :value="$h['cp'] ?? ''" maxlength="4" inputmode="numeric"/></div>
                @unless($lock)<div class="flex justify-end"><button class="btn-primary">Enregistrer</button></div>@endunless
            </div>
        </form>
        <form method="POST" action="{{ route('declarations.save') }}" class="card overflow-hidden lg:col-span-2">@include('declarations._hidden')
            <div class="card-h"><h2 class="card-t">Tableau récapitulatif · fichier {{ $E::nomRecap($an) }}</h2>
                <span class="text-xs text-slate-500">{{ isset($h['r']) ? 'Montants saisis' : 'Calculé depuis les annexes' }}</span></div>
            <div class="max-h-[70vh] overflow-auto"><table class="tbl text-xs"><thead class="sticky top-0"><tr><th>Code</th><th>Nature</th><th class="text-right">Assiette</th><th class="text-right">Taux</th><th class="text-right">Retenue</th></tr></thead><tbody>
                @foreach($E::RECAP as $code => [$lib, $tx])
                    @php $x = $recap[$code]; $c = $calcR[$code]; $ecart = isset($h['r']) && (abs($x['a'] - $c['a']) > 0.0005 || abs($x['r'] - $c['r']) > 0.0005); @endphp
                    <tr class="{{ $x['a'] || $x['r'] ? '' : 'text-slate-400' }}"><td class="font-mono">{{ $code }}</td><td>{{ $lib }}@if($ecart)<div class="text-amber-600" title="Montants calculés depuis les annexes">annexes : {{ tnd($c['a'], false) }} / {{ tnd($c['r'], false) }}</div>@endif</td>
                        <td class="text-right">@if($tx === 'css')—@else<input name="r[{{ $code }}][a]" value="{{ $num($x['a']) }}" class="input !w-32 !py-1 text-right font-mono" inputmode="decimal" @disabled($lock)>@endif</td>
                        <td class="text-right">{{ is_numeric($tx) ? str_replace('.', ',', (string) $tx).' %' : '' }}</td>
                        <td class="text-right"><input name="r[{{ $code }}][r]" value="{{ $num($x['r']) }}" class="input !w-32 !py-1 text-right font-mono" inputmode="decimal" @disabled($lock)></td></tr>
                @endforeach
            </tbody><tfoot><tr><td></td><td>Total général des retenues (enregistrement 999)</td><td></td><td></td><td class="num">{{ tnd(array_sum(array_column($recap, 'r'))) }}</td></tr></tfoot></table></div>
            @unless($lock)<div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 p-4 dark:border-slate-800">
                <button name="reprendre" value="1" class="btn-secondary" title="Abandonne les montants saisis et reprend le calcul depuis les annexes">Reprendre des annexes</button>
                <button class="btn-primary">Enregistrer le récapitulatif</button></div>@endunless
        </form>
    </div>
    <div class="card card-b mt-6 text-xs text-slate-500">
        Le bouton <b>Fichiers DGI</b> produit une archive contenant <span class="font-mono">{{ $E::nomRecap($an) }}</span>
        @foreach($E::ANNEXES as $k => $t) @if(! empty($d->annexes[$k])), <span class="font-mono">{{ $E::nomAnnexe($k, $an) }}</span>@endif @endforeach
        (enregistrements de 38 et 399 caractères, ASCII, montants en millimes), à graver sur CD. Les annexes sans ligne sont déclarées « non déposées ».
        « Pré-remplir » reprend la paie clôturée (annexe I) et les factures reçues avec retenue (annexes II et V).
    </div>
@else
    @php $Z = array_filter($E::ZONES[$ax], fn ($z) => $z[1] !== 'x'); if ($ax === 'a1') unset($Z['brut']); $L = array_values($d->annexes[$ax] ?? []);
         $vide = array_fill_keys(array_keys($Z), '') + ['i' => null, 'idk' => (string) $E::IDK[$ax][0], 'id' => '', 'nom' => '', 'act' => '', 'adr' => '', 'pm' => false]; @endphp
    <div class="card">
        <div class="card-h"><h2 class="card-t">{{ $E::ANNEXES[$ax] }} · {{ $E::nomAnnexe($ax, $an) }}</h2>
            @unless($lock)<button class="btn-primary btn-sm" x-data @click="$dispatch('anx-edit', @js($vide)); $dispatch('open-modal', 'anx')"><x-icon name="plus" class="h-3.5 w-3.5"/> Ligne</button>@endunless</div>
        <div class="overflow-x-auto"><table class="tbl text-xs"><thead><tr><th>N°</th><th>Identifiant</th><th>Bénéficiaire</th>
            @if($ax === 'a1')<th>Période</th>@endif<th class="text-right">Montant servi</th><th class="text-right">Retenues</th><th class="text-right">Net servi</th><th>Contrôle</th><th></th></tr></thead><tbody>
            @forelse($L as $i => $l0)
                @php $l = $E::normaliser($ax, $l0); $err = $E::controlesLigne($ax, $l0, $an); @endphp
                <tr><td class="num">{{ $i + 1 }}</td><td>{{ ['1' => 'MF', '2' => 'CIN', '3' => 'CS', '4' => 'ND'][$l['idk'] ?? ''] ?? '' }} <span class="font-mono">{{ $l['id'] ?? '' }}</span></td>
                    <td><div class="font-medium">{{ $l['nom'] }} @if(!empty($l['auto']))<span class="badge-blue ml-1">auto</span>@endif</div><div class="text-slate-500">{{ $l['act'] ?? '' }}</div></td>
                    @if($ax === 'a1')<td class="whitespace-nowrap">{{ ! empty($l['deb']) ? dfr($l['deb']) : '' }} – {{ ! empty($l['fin']) ? dfr($l['fin']) : '' }}</td>@endif
                    <td class="num">{{ tnd($l['brut'], false) }}</td><td class="num">{{ tnd($l['retenues'], false) }}</td><td class="num">{{ tnd($l['net'], false) }}</td>
                    <td>@if($err)<ul class="list-disc pl-4 text-rose-600">@foreach($err as $e)<li>{{ $e }}</li>@endforeach</ul>@else<span class="badge-green">Conforme</span>@endif</td>
                    <td class="whitespace-nowrap">@unless($lock)
                        <button class="btn-icon" x-data @click="$dispatch('anx-edit', @js(array_merge($vide, array_intersect_key($l, $vide), ['i' => $i, 'pm' => ! empty($l['pm'])]))); $dispatch('open-modal', 'anx')"><x-icon name="edit" class="h-4 w-4"/></button>
                        <form method="POST" action="{{ route('declarations.annexe.destroy') }}" class="inline">@include('declarations._hidden') @method('DELETE')<input type="hidden" name="ax" value="{{ $ax }}"><input type="hidden" name="i" value="{{ $i }}"><button class="btn-icon hover:!text-rose-600"><x-icon name="trash" class="h-4 w-4"/></button></form>
                    @endunless</td></tr>
            @empty <tr><td colspan="9"><x-empty icon="list" title="Aucune ligne">Utilisez « Pré-remplir » ou ajoutez les lignes manuellement. Une annexe sans ligne n’est pas déposée.</x-empty></td></tr> @endforelse
        </tbody>
        @if($L)@php $T = collect($L)->map(fn ($l) => $E::normaliser($ax, $l)); @endphp
            <tfoot><tr><td colspan="{{ $ax === 'a1' ? 4 : 3 }}">Total ({{ count($L) }} bénéficiaire(s))</td><td class="num">{{ tnd($T->sum('brut'), false) }}</td><td class="num">{{ tnd($T->sum('retenues'), false) }}</td><td class="num">{{ tnd($T->sum('net'), false) }}</td><td colspan="2"></td></tr></tfoot>@endif
        </table></div>
    </div>
    <x-modal name="anx" title="Ligne de l’annexe" size="max-w-4xl">
        <form method="POST" action="{{ route('declarations.annexe.store') }}" x-data="{ l: @js($vide) }" @anx-edit.window="l = $event.detail" class="grid gap-3 text-sm md:grid-cols-3">@include('declarations._hidden')
            <input type="hidden" name="ax" value="{{ $ax }}"><input type="hidden" name="i" :value="l.i ?? ''">
            <div><label class="label">Type d’identifiant</label><select name="idk" class="input" x-model="l.idk">@foreach($E::IDK[$ax] as $k)<option value="{{ $k }}">{{ $E::TYPES_ID[$k] }}</option>@endforeach</select></div>
            <div><label class="label">Identifiant *</label><input name="id" class="input font-mono" x-model="l.id" required maxlength="20" :placeholder="l.idk == '1' ? '1234567ABM000' : '8 chiffres'"></div>
            <div><label class="label">Nom et prénom ou raison sociale *</label><input name="nom" class="input" x-model="l.nom" required maxlength="40"></div>
            <div><label class="label">{{ $ax === 'a1' ? 'Emploi occupé' : 'Activité' }}</label><input name="act" class="input" x-model="l.act" maxlength="40"></div>
            <div class="md:col-span-2"><label class="label">Dernière adresse</label><input name="adr" class="input" x-model="l.adr" maxlength="120"></div>
            @if(in_array($ax, ['a3', 'a4'], true))<label class="flex items-center gap-2 md:col-span-3" x-show="l.idk == '4'"><input type="checkbox" name="pm" value="1" x-model="l.pm" class="rounded border-slate-300"> Personne morale (non résidente)</label>@endif
            <div class="border-t border-slate-100 md:col-span-3 dark:border-slate-800"></div>
            @foreach($Z as $k => [$lib, $nat, $len])
                <div><label class="label">{{ $nat === 't' ? 'Taux (%)' : $lib }}</label>
                    @if($nat === 'c')<select name="{{ $k }}" class="input" x-model="l.{{ $k }}"><option value="">—</option>@foreach($E::CODES["$ax.$k"] as $v => $t)<option value="{{ $v }}">{{ $v }} – {{ $t }}</option>@endforeach</select>
                    @elseif($nat === 'd')<input type="date" name="{{ $k }}" class="input" x-model="l.{{ $k }}">
                    @elseif($nat === 'n')<input type="number" min="0" name="{{ $k }}" class="input" x-model="l.{{ $k }}">
                    @else<input type="number" min="0" step="{{ $nat === 't' ? '0.01' : '0.001' }}" name="{{ $k }}" class="input text-right font-mono" x-model="l.{{ $k }}" @if($k === 'net') placeholder="calculé si vide" @endif>@endif
                </div>
            @endforeach
            <div class="flex items-end justify-end md:col-span-3"><button class="btn-primary">Enregistrer la ligne</button></div>
        </form>
    </x-modal>
@endif
