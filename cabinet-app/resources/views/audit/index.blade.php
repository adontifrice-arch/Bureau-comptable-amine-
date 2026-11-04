@php $AMT = collect(config('cabinet.AMT'))->mapWithKeys(fn($x) => [$x[0] => $x[1]]); $q = ['annee' => $annee, 'mission' => $m?->id];
     $u = fn($t) => route('audit.index', $q + ['tab' => $t]); $AST = ['ok' => ['Conforme', 'green'], 'warn' => ['À vérifier', 'amber'], 'ko' => ['Anomalie', 'red'], 'info' => ['Information', 'gray']]; @endphp
<x-layouts.app title="Audit & CAC">
    <x-page-header title="Audit et commissariat aux comptes" :subtitle="$client->nom.' · exercice '.$annee.($m ? ' · '.$AMT[$m->type] : '')">
        <x-client-picker :clients="$clients" :current="$client" :route="route('audit.index')" :params="['tab' => $tab, 'annee' => $annee]"/>
        <form><input type="hidden" name="tab" value="{{ $tab }}"><input type="number" name="annee" value="{{ $annee }}" class="input w-24" onchange="this.form.submit()"></form>
        @if($missions->count() > 1)<form><input type="hidden" name="tab" value="{{ $tab }}"><input type="hidden" name="annee" value="{{ $annee }}"><select name="mission" class="input w-56" onchange="this.form.submit()">@foreach($missions as $x)<option value="{{ $x->id }}" @selected($m?->id === $x->id)>{{ $AMT[$x->type] }}</option>@endforeach</select></form>@endif
    </x-page-header>
    <x-tabs :active="$tab" :items="['mis' => ['1. Missions', $u('mis')], 'qci' => ['2. Contrôle interne', $u('qci')], 'trv' => ['3. Travaux', $u('trv')], 'ctl' => ['4. Contrôles automatiques', $u('ctl')], 'con' => ['5. Constats', $u('con')], 'hon' => ['6. Honoraires CAC', $u('hon')], 'rap' => ['7. Rapport', $u('rap')], 'tr' => ['Piste d’audit', $u('tr')]]"/>

    @if(in_array($tab, ['qci', 'trv', 'rap']) && !$m)
        <div class="card"><x-empty icon="shield" title="Aucune mission pour cet exercice">Créez d’abord une mission dans l’onglet « Missions ».</x-empty></div>

    @elseif($tab === 'mis')
        <div class="mb-4 flex justify-end"><button class="btn-primary" x-data @click="$dispatch('mis-edit', {}); $dispatch('open-modal', 'mis')"><x-icon name="plus" class="h-4 w-4"/> Nouvelle mission</button></div>
        <div class="grid gap-4 md:grid-cols-2">
            @forelse($missions as $x)
                @php $qa = collect($x->qci ?? []); $o = $qa->where('a', 'O')->count(); $n = $qa->where('a', 'N')->count(); $tw = collect($x->travaux ?? []); @endphp
                <div class="card p-5 {{ $m?->id === $x->id ? 'ring-2 ring-brand-500' : '' }}">
                    <div class="flex items-start justify-between gap-3"><div><div class="font-semibold">{{ $AMT[$x->type] }}</div><div class="text-xs text-slate-500">Désignée le {{ dfr($x->date_designation) }} {{ $x->duree ? '· '.$x->duree : '' }}</div></div>
                        <span class="badge-{{ ['Planifiée' => 'gray', 'En cours' => 'amber', 'Rapport émis' => 'blue', 'Clôturée' => 'green'][$x->statut] ?? 'gray' }}">{{ $x->statut }}</span></div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800"><div class="font-mono text-base font-semibold">{{ ($o + $n) ? round($o / ($o + $n) * 100).' %' : '—' }}</div>contrôle interne</div>
                        <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800"><div class="font-mono text-base font-semibold">{{ $tw->where('s', 'Terminé')->count() }}/{{ collect(config('cabinet.TWK'))->sum(fn($p) => count($p['q'])) }}</div>travaux terminés</div>
                        <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800"><div class="font-mono text-base font-semibold">{{ $x->honoraires ? tnd($x->honoraires, false, 0) : '—' }}</div>honoraires HT</div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">{{ $x->associe ? 'Associé : '.$x->associe : '' }} {{ $x->chef_mission ? '· Chef de mission : '.$x->chef_mission : '' }}</div>
                    <div class="mt-3 flex justify-end gap-1">
                        <a href="{{ route('audit.index', ['annee' => $annee, 'mission' => $x->id, 'tab' => 'qci']) }}" class="btn-secondary btn-sm">Ouvrir</a>
                        <button class="btn-icon" x-data @click="$dispatch('mis-edit', @js($x->only(['id','type','duree','associe','chef_mission','equipe','honoraires','statut','objet']) + ['date_designation' => optional($x->date_designation)->format('Y-m-d')])); $dispatch('open-modal', 'mis')"><x-icon name="edit" class="h-4 w-4"/></button>
                        <x-confirm-delete :action="route('audit.missions.destroy', $x)"/>
                    </div>
                </div>
            @empty <div class="card md:col-span-2"><x-empty icon="shield" title="Aucune mission sur {{ $annee }}"/></div> @endforelse
        </div>
        <x-modal name="mis" title="Mission d’audit" size="max-w-3xl">
            <form method="POST" x-data="{ e: {} }" @mis-edit.window="e = Object.assign({type: 'cac', statut: 'Planifiée', date_designation: '{{ now()->format('Y-m-d') }}'}, $event.detail)" :action="e.id ? '{{ url('audit/missions') }}/' + e.id : '{{ route('audit.missions.store') }}'" class="grid gap-4 md:grid-cols-3">@csrf
                <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template><input type="hidden" name="annee" value="{{ $annee }}">
                <div class="md:col-span-2"><label class="label">Type de mission</label><select name="type" class="input" x-model="e.type">@foreach($AMT as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div><label class="label">Statut</label><select name="statut" class="input" x-model="e.statut">@foreach(config('cabinet.AMS') as $s)<option>{{ $s }}</option>@endforeach</select></div>
                <div><label class="label">Date de désignation</label><input type="date" name="date_designation" class="input" x-model="e.date_designation"></div>
                <div><label class="label">Durée du mandat</label><input name="duree" class="input" x-model="e.duree" placeholder="3 exercices"></div>
                <div><label class="label">Honoraires HT</label><input type="number" step="0.001" name="honoraires" class="input" x-model="e.honoraires"></div>
                <div><label class="label">Associé signataire</label><input name="associe" class="input" x-model="e.associe"></div>
                <div><label class="label">Chef de mission</label><input name="chef_mission" class="input" x-model="e.chef_mission"></div>
                <div><label class="label">Équipe</label><input name="equipe" class="input" x-model="e.equipe"></div>
                <div class="md:col-span-3"><label class="label">Objet / étendue</label><textarea name="objet" rows="2" class="input" x-model="e.objet"></textarea></div>
                <div class="flex justify-end md:col-span-3"><button class="btn-primary">Enregistrer</button></div>
            </form>
        </x-modal>

    @elseif($tab === 'qci')
        @php $A = $m->qci ?? []; $all = collect(config('cabinet.QCI'))->flatMap(fn($c) => $c['q']); $o = collect($A)->where('a', 'O')->count(); $n = collect($A)->where('a', 'N')->count(); $pct = ($o + $n) ? round($o / ($o + $n) * 100) : null; @endphp
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-stat label="Indice global du contrôle interne" :value="$pct === null ? '—' : $pct.' %'" icon="shield" :tone="$pct === null ? 'brand' : ($pct >= 80 ? 'green' : ($pct >= 60 ? 'amber' : 'red'))" :hint="$pct === null ? 'Non évalué' : ($pct >= 80 ? 'Fiable' : ($pct >= 60 ? 'À renforcer' : 'Faible'))"/>
            <x-stat label="Questions répondues" :value="count(array_filter($A, fn($x) => $x['a'] ?? ''))" icon="check" :hint="'sur '.$all->count()"/>
            <x-stat label="Faiblesses (réponses « Non »)" :value="$n" icon="alert" :tone="$n ? 'red' : 'green'"/>
        </div>
        <form method="POST" action="{{ route('audit.qci', $m) }}" class="space-y-4">@csrf
            @foreach(config('cabinet.QCI') as $cy)
                @php $co = collect($cy['q'])->filter(fn($x) => ($A[$x[0]]['a'] ?? '') === 'O')->count(); $cn = collect($cy['q'])->filter(fn($x) => ($A[$x[0]]['a'] ?? '') === 'N')->count(); @endphp
                <details class="card group" @if($loop->first) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-4"><span class="font-semibold">{{ $cy['t'] }}</span><span class="flex items-center gap-2">@if($co + $cn)<span class="{{ $co / ($co + $cn) >= .8 ? 'badge-green' : ($co / ($co + $cn) >= .6 ? 'badge-amber' : 'badge-red') }}">{{ round($co / ($co + $cn) * 100) }} %</span>@endif<x-icon name="chevron-right" class="h-4 w-4 transition group-open:rotate-90"/></span></summary>
                    @foreach($cy['q'] as [$id, $txt])
                        <div class="grid gap-2 border-t border-slate-100 px-5 py-3 md:grid-cols-12 md:items-center dark:border-slate-800" x-data="{ a: '{{ $A[$id]['a'] ?? '' }}' }">
                            <div class="text-sm md:col-span-6">{{ $txt }}</div>
                            <div class="flex gap-1 md:col-span-3"><input type="hidden" name="q[{{ $id }}][a]" :value="a">
                                @foreach(['O' => ['Oui', 'bg-emerald-600'], 'N' => ['Non', 'bg-rose-600'], 'S' => ['S.O.', 'bg-slate-500']] as $k => [$l, $c])<button type="button" @click="a = a === '{{ $k }}' ? '' : '{{ $k }}'" class="rounded-lg border px-3 py-1 text-xs font-semibold" :class="a === '{{ $k }}' ? '{{ $c }} border-transparent text-white' : 'border-slate-200 text-slate-600 dark:border-slate-700'">{{ $l }}</button>@endforeach
                            </div>
                            <input name="q[{{ $id }}][n]" value="{{ $A[$id]['n'] ?? '' }}" class="input-sm md:col-span-3" placeholder="Commentaire / référence">
                        </div>
                    @endforeach
                </details>
            @endforeach
            <div class="sticky bottom-4 flex justify-end gap-2"><button form="faib" class="btn-secondary shadow-lg">Créer les constats depuis les « Non »</button><button class="btn-primary shadow-lg">Enregistrer le questionnaire</button></div>
        </form>
        <form id="faib" method="POST" action="{{ route('audit.faiblesses', $m) }}">@csrf</form>

    @elseif($tab === 'trv')
        @php $W = $m->travaux ?? []; @endphp
        <form method="POST" action="{{ route('audit.travaux', $m) }}" class="space-y-4">@csrf
            @foreach(config('cabinet.TWK') as $ph)
                <div class="card"><div class="card-h"><h2 class="card-t">{{ $ph['t'] }}</h2></div>
                    @foreach($ph['q'] as [$id, $txt])
                        <div class="grid gap-2 border-b border-slate-100 px-5 py-3 last:border-0 md:grid-cols-12 md:items-center dark:border-slate-800">
                            <div class="text-sm md:col-span-6">{{ $txt }}</div>
                            <select name="w[{{ $id }}][s]" class="input-sm md:col-span-2">@foreach(['À faire', 'En cours', 'Terminé', 'Sans objet'] as $s)<option @selected(($W[$id]['s'] ?? 'À faire') === $s)>{{ $s }}</option>@endforeach</select>
                            <input name="w[{{ $id }}][ref]" value="{{ $W[$id]['ref'] ?? '' }}" class="input-sm md:col-span-1" placeholder="Réf.">
                            <input name="w[{{ $id }}][n]" value="{{ $W[$id]['n'] ?? '' }}" class="input-sm md:col-span-3" placeholder="Conclusion">
                        </div>
                    @endforeach
                </div>
            @endforeach
            <div class="sticky bottom-4 flex justify-end"><button class="btn-primary shadow-lg">Enregistrer le programme de travail</button></div>
        </form>

    @elseif($tab === 'ctl')
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-stat label="Contrôles conformes" :value="collect($checks)->where('st', 'ok')->count().'/'.count($checks)" icon="check" tone="green"/>
            <x-stat label="À vérifier" :value="collect($checks)->where('st', 'warn')->count()" icon="eye" tone="amber"/>
            <x-stat label="Anomalies" :value="collect($checks)->where('st', 'ko')->count()" icon="alert" tone="red"/>
        </div>
        <div class="card">@foreach($checks as $k)
            <div class="flex items-start gap-4 border-b border-slate-100 px-5 py-3.5 last:border-0 dark:border-slate-800"><span class="badge-{{ $AST[$k['st']][1] }} mt-0.5 w-24 justify-center">{{ $AST[$k['st']][0] }}</span><div><div class="text-sm font-medium">{{ $k['l'] }}</div><div class="text-xs text-slate-500">{{ $k['d'] }}</div></div></div>
        @endforeach</div>

    @elseif($tab === 'con')
        <div class="mb-4 flex justify-end"><button class="btn-primary" x-data @click="$dispatch('con-edit', {}); $dispatch('open-modal', 'con')"><x-icon name="plus" class="h-4 w-4"/> Ajouter un constat</button></div>
        <div class="card">@forelse($constats as $x)
            <div class="flex items-start gap-4 border-b border-slate-100 px-5 py-4 last:border-0 dark:border-slate-800 {{ $x->statut === 'Résolu' ? 'opacity-60' : '' }}">
                <span class="badge-{{ ['Faible' => 'gray', 'Moyenne' => 'amber', 'Élevée' => 'red'][$x->gravite] }} mt-0.5">{{ $x->gravite }}</span>
                <div class="min-w-0 flex-1"><div class="font-medium {{ $x->statut === 'Résolu' ? 'line-through' : '' }}">{{ $x->titre }}</div><div class="whitespace-pre-line text-sm text-slate-500">{{ $x->detail }}</div><div class="mt-1 text-xs text-slate-400">{{ $x->created_at->format('d/m/Y') }} · {{ $x->user?->name }}</div></div>
                <form method="POST" action="{{ route('audit.constats.update', $x) }}">@csrf @method('PUT')<input type="hidden" name="toggle" value="1"><button class="{{ $x->statut === 'Résolu' ? 'badge-green' : 'badge-blue' }} cursor-pointer">{{ $x->statut }}</button></form>
                <button class="btn-icon" x-data @click="$dispatch('con-edit', @js($x->only(['id','titre','gravite','detail','statut','mission_id']))); $dispatch('open-modal', 'con')"><x-icon name="edit" class="h-4 w-4"/></button>
                <x-confirm-delete :action="route('audit.constats.destroy', $x)"/>
            </div>
        @empty <x-empty icon="shield" title="Aucun constat"/> @endforelse</div>
        <x-modal name="con" title="Constat d’audit">
            <form method="POST" x-data="{ e: {} }" @con-edit.window="e = Object.assign({gravite: 'Moyenne', statut: 'Ouvert', mission_id: '{{ $m?->id }}'}, $event.detail)" :action="e.id ? '{{ url('audit/constats') }}/' + e.id : '{{ route('audit.constats.store') }}'" class="grid gap-4 md:grid-cols-2">@csrf
                <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template><input type="hidden" name="annee" value="{{ $annee }}"><input type="hidden" name="mission_id" :value="e.mission_id">
                <div class="md:col-span-2"><label class="label">Intitulé *</label><input name="titre" class="input" x-model="e.titre" required></div>
                <div><label class="label">Gravité</label><select name="gravite" class="input" x-model="e.gravite"><option>Faible</option><option>Moyenne</option><option>Élevée</option></select></div>
                <div><label class="label">Statut</label><select name="statut" class="input" x-model="e.statut"><option>Ouvert</option><option>Résolu</option></select></div>
                <div class="md:col-span-2"><label class="label">Description, incidence et recommandation</label><textarea name="detail" rows="5" class="input" x-model="e.detail"></textarea></div>
                <div class="flex justify-end md:col-span-2"><button class="btn-primary">Enregistrer</button></div>
            </form>
        </x-modal>

    @elseif($tab === 'hon')
        <div class="grid gap-6 lg:grid-cols-3">
            <form class="card card-b space-y-4 self-start"><input type="hidden" name="tab" value="hon"><input type="hidden" name="annee" value="{{ $annee }}">
                <h2 class="card-t">Critères</h2>
                <x-field label="Total brut du bilan (TND)" name="bil" type="number" step="0.001" :value="$hon['bil']"/>
                <x-field label="Total des produits (TND)" name="prod" type="number" step="0.001" :value="$hon['prod']"/>
                <x-field label="Effectif total" name="eff" type="number" :value="$hon['eff']"/>
                <x-field label="Coefficient de modulation" name="coef" type="number" step="0.01" :value="$hon['coef']"/>
                <button class="btn-primary w-full">Calculer</button>
            </form>
            <div class="card lg:col-span-2">
                <div class="card-h"><h2 class="card-t">Fiche de calcul</h2><span class="text-xs text-slate-400">Barème des auditeurs des comptes (arrêtés de 2003)</span></div>
                <table class="tbl"><thead><tr><th>Critère</th><th class="text-right">Plafond tranche inférieure</th><th class="text-right">Fraction de tranche</th><th class="text-right">Total</th></tr></thead><tbody>
                    <tr><td>A — Total brut du bilan ({{ tnd($hon['bil'] / 1000, false, 0) }} mD)</td><td class="num">{{ tnd($hon['A']['pl'], false) }}</td><td class="num">{{ tnd($hon['A']['fr'], false) }}</td><td class="num">{{ tnd($hon['A']['tot'], false) }}</td></tr>
                    <tr><td>B — Total des produits ({{ tnd($hon['prod'] / 1000, false, 0) }} mD)</td><td class="num">{{ tnd($hon['B']['pl'], false) }}</td><td class="num">{{ tnd($hon['B']['fr'], false) }}</td><td class="num">{{ tnd($hon['B']['tot'], false) }}</td></tr>
                    <tr><td>C — Effectif ({{ $hon['eff'] }})</td><td class="num">{{ tnd($hon['C']['pl'], false) }}</td><td class="num">{{ tnd($hon['C']['fr'], false) }}</td><td class="num">{{ tnd($hon['C']['tot'], false) }}</td></tr>
                </tbody><tfoot><tr><td colspan="3">Honoraires de base (A + B + C) × {{ $hon['coef'] }}</td><td class="num text-base text-brand-700">{{ tnd($hon['ht']) }}</td></tr></tfoot></table>
                <div class="border-t border-slate-100 p-5 text-xs text-slate-500 dark:border-slate-800">Échéancier par défaut : 20 % au commencement des travaux, 30 % à la fin des travaux préliminaires, 30 % à la remise du rapport, le solde à l’approbation des comptes. Montants indicatifs à vérifier.</div>
                @if($m)<form method="POST" action="{{ route('audit.missions.update', $m) }}" class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800">@csrf @method('PUT')
                    @foreach($m->only(['annee','type','duree','associe','chef_mission','equipe','statut','objet']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <input type="hidden" name="date_designation" value="{{ optional($m->date_designation)->format('Y-m-d') }}"><input type="hidden" name="honoraires" value="{{ $hon['ht'] }}">
                    <button class="btn-secondary">Appliquer à la mission</button></form>@endif
            </div>
        </div>

    @elseif($tab === 'rap')
        @php $R = $m->rapport ?? []; @endphp
        <div class="grid gap-6 lg:grid-cols-3">
            <form method="POST" action="{{ route('audit.rapport', $m) }}" class="card card-b space-y-4 self-start">@csrf <input type="hidden" name="generer" value="1">
                <h2 class="card-t">Paramètres du rapport</h2>
                <x-select label="Opinion" name="opinion" :value="$R['opinion'] ?? 'sans'" :options="['sans' => 'Sans réserve (certification pure et simple)', 'avec' => 'Avec réserve', 'refus' => 'Impossibilité d’exprimer une opinion', 'defav' => 'Opinion défavorable']"/>
                <x-field label="Paragraphe d’observation (facultatif)" name="obs" type="textarea" :value="$R['obs'] ?? ''"/>
                <x-field label="Lieu" name="lieu" :value="$R['lieu'] ?? 'Tunis'"/>
                <x-field label="Date du rapport" name="date" type="date" :value="$R['date'] ?? now()->format('Y-m-d')"/>
                <button class="btn-primary w-full" onclick="return !document.getElementById('rp-ed') || confirm('Régénérer remplace le texte modifié. Continuer ?')"><x-icon name="sparkles" class="h-4 w-4"/> Générer le projet</button>
                <p class="text-xs text-slate-400">Les constats ouverts (gravité moyenne ou élevée) fondent la réserve. Le projet doit être relu et adapté.</p>
            </form>
            <div class="lg:col-span-2">
                @if(!empty($R['html']))
                    <form method="POST" action="{{ route('audit.rapport', $m) }}" x-data @submit="$refs.h.value = $refs.ed.innerHTML">@csrf <input type="hidden" name="html" x-ref="h">
                        <div class="mb-3 flex flex-wrap gap-1">@foreach([['bold','G'],['italic','I'],['underline','S'],['insertUnorderedList','• Liste'],['justifyCenter','Centrer'],['justifyFull','Justifier']] as [$c, $l])<button type="button" class="btn-secondary btn-sm" onmousedown="event.preventDefault(); document.execCommand('{{ $c }}')">{{ $l }}</button>@endforeach
                            <span class="flex-1"></span><a href="{{ route('audit.rapport.print', $m) }}" class="btn-secondary btn-sm"><x-icon name="printer" class="h-3.5 w-3.5"/> Imprimer</a><button class="btn-primary btn-sm">Enregistrer</button></div>
                        <div id="rp-ed" x-ref="ed" contenteditable="true" class="prose-doc min-h-96 rounded-xl border border-slate-200 bg-white p-8 shadow-sm focus:outline-none focus:ring-4 focus:ring-brand-500/10">{!! $R['html'] !!}</div>
                    </form>
                @else <div class="card"><x-empty icon="file-text" title="Aucun projet de rapport">Choisissez l’opinion puis générez le projet.</x-empty></div> @endif
            </div>
        </div>

    @elseif($tab === 'tr')
        <div class="card">@forelse($trail as $t)
            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-2.5 text-sm last:border-0 dark:border-slate-800"><span class="w-36 font-mono text-xs text-slate-500">{{ $t->created_at->format('d/m/Y H:i') }}</span><span class="flex-1">{{ $t->action }}</span><span class="text-xs text-slate-400">{{ $t->user?->name }}</span></div>
        @empty <x-empty icon="list" title="Aucune action enregistrée"/> @endforelse</div>
    @endif
</x-layouts.app>
