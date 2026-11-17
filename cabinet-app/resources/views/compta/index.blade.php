@php $chef = auth()->user()->estChef(); $u = fn($t, $x = []) => route('compta.index', ['tab' => $t, 'annee' => $annee] + $x); @endphp
<x-layouts.app title="Comptabilité">
    {{-- Plan comptable pour les champs de compte : un numéro absent propose la création du sous-compte (resources/js/comptes.js) --}}
    <script>window.PLAN = @js((object) $plan); window.URL_COMPTES = @js(route('compta.comptes.store'));</script>
    <datalist id="taux-tva">@foreach(\App\Services\Saisie::TAUX_TVA as $t)<option value="{{ $t }}">{{ $t }} %</option>@endforeach</datalist>
    <x-page-header title="Comptabilité" :subtitle="$client->nom.' · exercice '.$annee">
        <x-client-picker :clients="$clients" :current="$client" :route="route('compta.index')" :params="['tab' => $tab, 'annee' => $annee]"/>
        <form><input type="hidden" name="tab" value="{{ $tab }}"><input type="number" name="annee" value="{{ $annee }}" class="input w-24" onchange="this.form.submit()"></form>
        @if(in_array($tab, ['ach', 'vte', 'bq', 'jnl'], true))<button class="btn-secondary" x-data @click="$dispatch('recherche')" title="Recherche par date, montant, libellé ou compte"><x-icon name="search" class="h-4 w-4"/> Rechercher @if($rech->active())<span class="badge-blue !py-0">{{ $rech->nombre() }}</span>@endif</button>@endif
        <a href="{{ $u('param') }}" class="btn-secondary"><x-icon name="settings" class="h-4 w-4"/> Paramétrage</a>
        <button class="btn-primary" x-data @click="$dispatch('open-modal', 'releve')"><x-icon name="upload" class="h-4 w-4"/> Importer un relevé bancaire</button>
    </x-page-header>
    @unless($chef)<div class="mb-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800"><x-icon name="lock" class="h-4 w-4"/> Vous saisissez en tant que collaborateur : seule la validation du chef comptable comptabilise les pièces.</div>@endunless
    <x-tabs :active="$tab" :items="['ach' => ['Factures d’achat', $u('ach')], 'vte' => ['Factures de vente', $u('vte')], 'bq' => ['Banque'.($aTraiter ? ' ('.$aTraiter.')' : ''), $u('bq')], 'pai' => ['Paie à comptabiliser', $u('pai')], 'jnl' => ['Journal & écritures', $u('jnl')], 'bal' => ['Balance générale', $u('bal')], 'gl' => ['Grand livre', $u('gl')], 'plan' => ['Plan comptable', $u('plan')], 'corb' => ['Pièces supprimées'.($nbSupprimees ? ' ('.$nbSupprimees.')' : ''), $u('corb')], 'param' => ['Paramétrage', $u('param')]]"/>

    @if(in_array($tab, ['ach', 'vte', 'bq', 'jnl'], true))@include('compta._recherche')@endif

    @if($tab === 'ach')
        <div class="mb-4 flex flex-wrap justify-end gap-2">
            @if($ia)<button class="btn-secondary" x-data @click="$dispatch('open-modal', 'ia')"><x-icon name="sparkles" class="h-4 w-4"/> Lire une facture avec l’IA</button>@endif
            <button class="btn-primary" x-data @click="$dispatch('fr-edit', {}); $dispatch('open-modal', 'fr')"><x-icon name="plus" class="h-4 w-4"/> Saisir une facture d’achat</button>
        </div>
        <div class="card overflow-x-auto">
            <table class="tbl"><thead><tr><th>Date</th><th>Fournisseur</th><th>Compte</th><th class="text-right">HT</th><th class="text-right">TVA</th><th class="text-right">TTC</th><th>Statut</th><th></th></tr></thead><tbody>
            @forelse($factures as $f)
                <tr>
                    <td>{{ dfr($f->date) }}</td>
                    <td><div class="font-medium">{{ $f->fournisseur }}</div><div class="text-xs text-slate-500">{{ $f->reference }} {{ $f->matricule_fiscal ? '· MF '.$f->matricule_fiscal : '' }} {{ $f->retenue_taux > 0 ? '· RS '.(float)$f->retenue_taux.' %' : '' }}</div>
                        @if($f->tva_suspension)<div class="mt-0.5"><span class="badge-amber" title="Bon de commande visé {{ $f->suspension_ref }}">Suspension TVA · {{ tnd($f->tva_suspendue, false) }}</span></div>@endif
                        @foreach((array) $f->anomalies as $a)<div class="text-xs text-amber-700">⚠ {{ $a }}</div>@endforeach
                        @if($f->statut === 'rej')<div class="text-xs text-rose-600">Motif : {{ $f->motif_rejet }}</div>@endif</td>
                    <td class="text-xs">@php $lf = $f->lignesSaisie(); @endphp @if(count($lf) > 1)@foreach($lf as $x)<div><span class="font-mono">{{ $x['compte'] ?: '—' }}</span> <span class="text-slate-400">{{ n3($x['ht']) }} · {{ (float) $x['tva_taux'] }} %</span></div>@endforeach @else{{ $f->compte ? $f->compte.' '.\App\Services\Compta::libelle($f->compte) : '—' }}@endif</td>
                    <td class="num">{{ tnd($f->ht, false) }}</td><td class="num">{{ tnd($f->tva, false) }}</td><td class="num font-semibold">{{ tnd($f->ttc(), false) }}</td>
                    <td>@if($f->statut === 'val')<span class="badge-green">Comptabilisée</span><div class="mt-0.5 text-[11px] text-slate-400">{{ $f->validePar?->name }}</div>@elseif($f->statut === 'rej')<span class="badge-red">Rejetée</span>@else<span class="badge-amber">À valider</span>@endif</td>
                    <td class="whitespace-nowrap text-right">
                        @if($f->fichier)<a href="{{ route('compta.factures.fichier', $f) }}" target="_blank" class="btn-icon" title="Pièce jointe"><x-icon name="eye" class="h-4 w-4"/></a>@endif
                        @if($f->statut !== 'val')
                            @if($chef && $f->statut === 'prop')
                                <form method="POST" action="{{ route('compta.factures.valider', $f) }}" class="inline">@csrf<button class="btn-success btn-sm">Valider</button></form>
                                <form method="POST" action="{{ route('compta.factures.rejeter', $f) }}" class="inline" onsubmit="this.motif.value = prompt('Motif du rejet ?') || ''">@csrf<input type="hidden" name="motif"><button class="btn-secondary btn-sm !text-rose-600">Rejeter</button></form>
                            @endif
                            <button class="btn-icon" x-data @click="$dispatch('fr-edit', @js($f->only(['id','fournisseur','matricule_fiscal','reference','description','ht','tva_taux','tva','timbre','compte','retenue_taux']) + ['lignes' => $f->lignesSaisie(), 'date' => $f->date->format('Y-m-d'), 'tva_deductible' => $f->tva_deductible ? '1' : '0', 'tva_suspension' => $f->tva_suspension, 'suspension_ref' => $f->suspension_ref, 'suspension_date' => $f->suspension_date?->format('Y-m-d')])); $dispatch('open-modal', 'fr')"><x-icon name="edit" class="h-4 w-4"/></button>
                            <x-confirm-delete :action="route('compta.factures.destroy', $f)"/>
                        @endif
                    </td>
                </tr>
            @empty <tr><td colspan="8"><x-empty icon="receipt" title="Aucune facture reçue">Saisissez les factures fournisseurs du client : elles seront comptabilisées après validation.</x-empty></td></tr> @endforelse
            </tbody></table>
        </div>
        @php $imp = collect($plan)->filter(fn($l, $n) => preg_match('/^[26]/', $n) && !preg_match('/^2[89]/', $n)); $pa = $saisie['achat']; $mAch = $modeles->where('type', 'achat'); @endphp
        <x-modal name="fr" title="Facture d’achat" size="max-w-4xl">
            @php $propA = collect($pa['comptes'])->pluck('compte')->all(); @endphp
            <datalist id="cpts-ach" data-plan>@foreach($pa['comptes'] as $c)<option value="{{ $c['compte'] }}">★ TVA {{ (float) $c['tva_taux'] }} % — {{ \App\Services\Compta::libelle($c['compte']) }}</option>@endforeach @foreach($imp as $n => $l)@continue(in_array((string) $n, $propA, true))<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>
            <form method="POST" enctype="multipart/form-data"
                  x-data="factureVentilee(@js(['modeles' => $mAch->mapWithKeys(fn ($m) => [$m->id => $m->valeurs()]), 'defaut' => $pa['comptes'], 'taux' => \App\Services\Saisie::tauxParDefaut($pa),
                      'vide' => ['timbre' => (float) $pa['timbre'], 'tva_deductible' => $pa['tva_deductible'] ? '1' : '0', 'compte' => (string) $pa['compte'], 'date' => now()->format('Y-m-d'), 'retenue_taux' => (float) $pa['retenue_taux']]]))"
                  @fr-edit.window="ouvrir($event.detail)"
                  :action="e.id ? '{{ url('comptabilite/factures') }}/' + e.id : '{{ route('compta.factures.store') }}'" class="grid gap-4 md:grid-cols-3">@csrf
                <template x-if="e.id"><input type="hidden" name="_method" value="PUT"></template>
                @if($mAch->count())<div class="md:col-span-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40"><label class="label" for="fr-mod">Saisie rapide : partir d’un modèle</label><select id="fr-mod" class="input" @change="appliquer($event.target.value); $event.target.value = ''"><option value="">— choisir un modèle —</option>@foreach($mAch as $m)<option value="{{ $m->id }}">{{ $m->nom }}{{ count($m->lignes()) > 1 ? ' · '.count($m->lignes()).' comptes' : ($m->compte ? ' · '.$m->compte : '') }}</option>@endforeach</select></div>@endif
                <div><label class="label" for="fr-date">Date *</label><input id="fr-date" type="date" name="date" class="input" x-model="e.date" required></div>
                <div class="md:col-span-2"><label class="label" for="fr-four">Fournisseur *</label><input id="fr-four" name="fournisseur" class="input" x-model="e.fournisseur" required></div>
                <div><label class="label" for="fr-mf">Matricule fiscal</label><input id="fr-mf" name="matricule_fiscal" class="input" x-model="e.matricule_fiscal"></div>
                <div><label class="label" for="fr-ref">N° de facture</label><input id="fr-ref" name="reference" class="input" x-model="e.reference"></div>
                <div><label class="label" for="fr-desc">Description</label><input id="fr-desc" name="description" class="input" x-model="e.description"></div>
                <div class="md:col-span-3">
                    <div class="mb-1 hidden grid-cols-12 gap-2 text-xs font-medium text-slate-500 md:grid"><div class="col-span-5">Compte de charge / immobilisation</div><div class="col-span-2 text-right">Montant HT</div><div class="col-span-2">TVA %</div><div class="col-span-2 text-right">Montant TVA</div></div>
                    <template x-for="(l, i) in lignes" :key="i">
                        <div class="mb-2 grid grid-cols-12 items-start gap-2" @focusin="active = i">
                            <div class="col-span-12 md:col-span-5"><input class="input font-mono" :class="lignes.length > 1 && active === i ? 'ring-1 ring-brand-400' : ''" list="cpts-ach" :name="`lignes[${i}][compte]`" x-model="l.compte" @change="compteChange(i)" data-compte autocomplete="off" placeholder="Compte — un nouveau numéro peut être créé" aria-label="Compte"><p data-compte-libelle class="mt-0.5 truncate text-xs text-slate-400" x-effect="$el.textContent = window.libelleCompte(l.compte)"></p></div>
                            <div class="col-span-4 md:col-span-2"><input type="number" step="0.001" min="0" class="input text-right" :name="`lignes[${i}][ht]`" x-model="l.ht" @input="calc(l)" placeholder="HT" aria-label="Montant HT"></div>
                            <div class="col-span-3 md:col-span-2"><input type="number" step="0.01" min="0" max="100" class="input" list="taux-tva" :name="`lignes[${i}][tva_taux]`" x-model="l.tva_taux" @input="calc(l)" aria-label="Taux de TVA"></div>
                            <div class="col-span-4 md:col-span-2"><input type="number" step="0.001" min="0" class="input text-right" :name="`lignes[${i}][tva]`" x-model="l.tva" placeholder="TVA" aria-label="Montant TVA"></div>
                            <div class="col-span-1"><button type="button" class="btn-icon" x-show="lignes.length > 1" @click="retirer(i)" title="Retirer la ligne"><x-icon name="trash" class="h-4 w-4"/></button></div>
                        </div>
                    </template>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="btn-secondary btn-sm" @click="ajouter()">+ Ligne</button>
                        <div class="flex flex-wrap items-center gap-1" x-show="propositions.length"><span class="text-[11px] text-slate-400" x-text="(deModele ? 'Modèle' : 'Proposés') + (lignes.length > 1 ? ' (ligne ' + (active + 1) + ') :' : ' :')"></span><template x-for="c in propositions" :key="c.compte"><button type="button" class="!py-0.5" :class="lignes[active] && lignes[active].compte === c.compte ? 'badge-blue' : 'badge-gray'" @click="choisirCompte(c.compte)" :title="window.libelleCompte(c.compte)" x-text="c.compte + ' · ' + pourcent(c.tva_taux)"></button></template></div>
                        <div class="ml-auto text-sm">HT <b class="font-mono" x-text="tnd(totalHt)"></b> · <span x-text="e.tva_suspension ? 'TVA suspendue' : 'TVA'"></span> <b class="font-mono" x-text="tnd(totalTva)"></b> · TTC <b class="font-mono" x-text="tnd(ttc)"></b></div>
                    </div>
                </div>
                <div><label class="label" for="fr-tim">Droit de timbre</label><input id="fr-tim" type="number" step="0.001" name="timbre" class="input" x-model="e.timbre"></div>
                <div><label class="label" for="fr-ded">TVA récupérable</label><select id="fr-ded" name="tva_deductible" class="input" x-model="e.tva_deductible"><option value="1">Oui (4366)</option><option value="0">Non (incluse en charge)</option></select></div>
                <div><label class="label" for="fr-rs">Retenue à la source (%)</label><input id="fr-rs" type="number" step="0.01" name="retenue_taux" class="input" x-model="e.retenue_taux"></div>
                @include('compta._suspension_tva', ['p' => 'fr'])
                <div class="md:col-span-2"><label class="label" for="fr-fic">Pièce (PDF / image)</label><input id="fr-fic" type="file" name="fichier" accept=".pdf,image/*" class="input"></div>
                <div class="flex flex-wrap items-center justify-between gap-3 md:col-span-3"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="memoriser" value="1" class="rounded border-slate-300 text-brand-600"> Mémoriser comme modèle pour ce fournisseur</label><button class="btn-primary">Enregistrer (en attente de validation)</button></div>
            </form>
        </x-modal>
        @if($ia)
        <x-modal name="ia" title="Lecture d’une facture par l’IA">
            <form method="POST" action="{{ route('compta.factures.store') }}" enctype="multipart/form-data" class="space-y-4">@csrf <input type="hidden" name="ia" value="1">
                <p class="text-sm text-slate-500">Les propositions de l’IA sont des suggestions : seule la validation du chef comptable comptabilise la facture.</p>
                <div><label class="label">Photo ou PDF de la facture</label><input type="file" name="fichier" accept=".pdf,image/*" class="input"></div>
                <div><label class="label">ou texte de la facture</label><textarea name="texte" rows="5" class="input"></textarea></div>
                <div class="flex justify-end"><button class="btn-primary"><x-icon name="sparkles" class="h-4 w-4"/> Analyser</button></div>
            </form>
        </x-modal>
        @endif

    @elseif($tab === 'pai')
        <div class="card">
            @forelse($paies as $p)
                <div class="flex flex-wrap items-center gap-4 border-b border-slate-100 px-5 py-4 last:border-0 dark:border-slate-800">
                    <div class="min-w-0 flex-1"><div class="font-medium">{{ $p->libelle() }}</div><div class="text-xs text-slate-500">{{ count($p->resultats ?? []) }} salarié(s) · brut {{ tnd($p->totaux['brut'] ?? 0) }} · net {{ tnd($p->totaux['net'] ?? 0) }}</div></div>
                    @if($p->statut === 'val')<span class="badge-green">Comptabilisée</span>
                    @else <span class="badge-amber">Transmise</span>
                        @if($chef)<form method="POST" action="{{ route('compta.paies.valider', $p) }}">@csrf<button class="btn-success btn-sm">Valider et comptabiliser</button></form>@endif
                    @endif
                </div>
            @empty <x-empty icon="wallet" title="Aucune paie transmise">Clôturez une paie dans l’onglet Paie pour la transmettre à la comptabilité.</x-empty> @endforelse
        </div>

    @elseif($tab === 'jnl')
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1">@foreach(['' => 'Tous', 'ACH' => 'Achats', 'VTE' => 'Ventes', 'PAI' => 'Paie', 'BQ' => 'Banque', 'OD' => 'OD'] as $k => $l)<a href="{{ $u('jnl', ['journal' => $k] + $rech->parametres()) }}" class="{{ request('journal', '') === $k ? 'badge-blue' : 'badge-gray' }} !py-1">{{ $l }}</a>@endforeach</div>
            @if($chef)<button class="btn-primary" x-data @click="$dispatch('open-modal', 'od')"><x-icon name="plus" class="h-4 w-4"/> Saisir une écriture</button>@endif
        </div>
        <div class="space-y-3">
            @forelse($ecritures as $e)
                <div class="card overflow-hidden" ondblclick="if (! event.target.closest('a, button, form')) window.location = {{ \Illuminate\Support\Js::from(route('compta.ecritures.show', [$e, 'retour' => request()->fullUrl()])) }}" title="Double-clic : ouvrir l’écriture">
                    <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 text-sm dark:border-slate-800 dark:bg-slate-800/30">
                        <span class="badge-blue">{{ $e->journal }}</span><span class="font-mono text-xs">{{ dfr($e->date) }}</span><span class="flex-1 font-medium">{{ $e->libelle }}</span>
                        <span class="text-xs text-slate-400">{{ $e->validePar ? 'validée par '.$e->validePar->name : '' }}</span>
                        <a href="{{ route('compta.ecritures.show', [$e, 'retour' => request()->fullUrl()]) }}" class="btn-ghost btn-sm"><x-icon name="eye" class="h-3.5 w-3.5"/> Ouvrir</a>
                        @if(\App\Services\Ecritures::autorise(auth()->user()))<form method="POST" action="{{ route('compta.ecritures.annuler', $e) }}" onsubmit="const m = prompt('Supprimer cette écriture ? Elle sera conservée dans « Pièces supprimées ».\n\nMotif (facultatif) :'); if (m === null) return false; this.motif.value = m">@csrf<input type="hidden" name="motif"><button class="btn-ghost btn-sm !text-rose-600">Supprimer</button></form>@endif
                    </div>
                    <table class="w-full text-sm">@foreach($e->lignes as $l)<tr class="border-b border-slate-50 last:border-0 dark:border-slate-800"><td class="w-24 px-4 py-1.5 font-mono text-xs">{{ $l->compte }}</td><td class="px-2 py-1.5 text-slate-500 {{ $l->credit > 0 ? 'pl-10' : '' }}">{{ \App\Services\Compta::libelle($l->compte) }}</td><td class="num w-36 px-4 py-1.5">{{ n3($l->debit) }}</td><td class="num w-36 px-4 py-1.5">{{ n3($l->credit) }}</td></tr>@endforeach</table>
                </div>
            @empty <div class="card"><x-empty icon="book" title="Aucune écriture sur {{ $annee }}"/></div> @endforelse
        </div>
        <div class="mt-4">{{ $ecritures->links() }}</div>
        <x-modal name="od" title="Écriture manuelle" size="max-w-3xl">
            <form method="POST" action="{{ route('compta.od.store') }}" x-data="{ l: [{compte:'',debit:'',credit:''},{compte:'',debit:'',credit:''}], get d(){return this.l.reduce((s,x)=>s+(+x.debit||0),0)}, get c(){return this.l.reduce((s,x)=>s+(+x.credit||0),0)} }" class="space-y-4">@csrf
                <div class="grid gap-4 md:grid-cols-4">
                    <div><label class="label">Date</label><input type="date" name="date" class="input" value="{{ now()->format('Y-m-d') }}"></div>
                    <div><label class="label">Journal</label><select name="journal" class="input">@foreach(['OD' => 'Opérations diverses', 'VTE' => 'Ventes', 'ACH' => 'Achats', 'BQ' => 'Banque', 'CAI' => 'Caisse', 'AN' => 'À-nouveaux'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="md:col-span-2"><label class="label">Libellé</label><input name="libelle" class="input" required></div>
                </div>
                <datalist id="cpts" data-plan>@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist>
                <template x-for="(x, i) in l" :key="i"><div class="grid grid-cols-12 gap-2">
                    <input class="input col-span-5 font-mono" list="cpts" placeholder="Compte" :name="`lignes[${i}][compte]`" x-model="x.compte" data-compte autocomplete="off" aria-label="Compte">
                    <input type="number" step="0.001" class="input col-span-3" placeholder="Débit" :name="`lignes[${i}][debit]`" x-model="x.debit">
                    <input type="number" step="0.001" class="input col-span-3" placeholder="Crédit" :name="`lignes[${i}][credit]`" x-model="x.credit">
                    <button type="button" class="btn-icon col-span-1" @click="l.splice(i,1)"><x-icon name="trash" class="h-4 w-4"/></button>
                </div></template>
                <div class="flex items-center justify-between">
                    <button type="button" class="btn-secondary btn-sm" @click="l.push({compte:'',debit:'',credit:''})">+ Ligne</button>
                    <div class="text-sm">Débit <b class="font-mono" x-text="tnd(d)"></b> · Crédit <b class="font-mono" x-text="tnd(c)"></b> <span x-show="Math.abs(d-c) > 0.0005" class="badge-red ml-2">Déséquilibrée</span><span x-show="d > 0 && Math.abs(d-c) <= 0.0005" class="badge-green ml-2">Équilibrée</span></div>
                </div>
                <div class="flex justify-end"><button class="btn-primary">Enregistrer</button></div>
            </form>
        </x-modal>

    @elseif($tab === 'bal')
        <div class="mb-4 flex justify-end gap-2">
            <a href="{{ route('compta.balance.csv', ['annee' => $annee]) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel (CSV)</a>
            <a href="{{ route('compta.balance.print', ['annee' => $annee]) }}" target="_blank" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</a>
        </div>
        @include('compta._balance')

    @elseif($tab === 'gl')
        <form class="card mb-4 flex flex-wrap items-end gap-3 p-4"><input type="hidden" name="tab" value="gl"><input type="hidden" name="annee" value="{{ $annee }}">
            <div class="w-72"><label class="label">Compte (ou racine, ex. 6)</label><input name="compte" value="{{ $compte }}" class="input" list="cpts2"><datalist id="cpts2" data-plan>@foreach($plan as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</datalist></div>
            <button class="btn-primary">Afficher</button>
            @if($compte)<p class="text-xs text-slate-500">{{ $exact ? 'Compte '.$compte.' seul' : 'Comptes commençant par '.$compte }} · double-cliquez sur une ligne pour ouvrir l’écriture (consulter, modifier, supprimer).@if($exact) <a href="{{ $u('gl', ['compte' => $compte]) }}" class="underline">Voir aussi ses sous-comptes</a>@endif</p>@endif
        </form>
        @if($compte)
            @php $s = 0; @endphp
            <div class="card overflow-x-auto"><table class="tbl"><thead><tr><th>Date</th><th>Jnl</th><th>Compte</th><th>Libellé</th><th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Solde</th></tr></thead><tbody>
                @forelse($mouvements as $m) @php $s += $m->debit - $m->credit; @endphp
                    @php $fiche = route('compta.ecritures.show', [$m->ecriture_id, 'retour' => request()->fullUrl()]); @endphp
                    <tr class="cursor-pointer hover:bg-brand-50/60 dark:hover:bg-slate-800/40" ondblclick="window.location = {{ \Illuminate\Support\Js::from($fiche) }}" title="Double-clic : ouvrir l’écriture"><td>{{ dfr($m->date) }}</td><td>{{ $m->journal }}</td><td class="font-mono text-xs">{{ $m->compte }}</td><td><a href="{{ $fiche }}" class="hover:underline">{{ $m->libelle }}</a></td><td class="num">{{ n3($m->debit) }}</td><td class="num">{{ n3($m->credit) }}</td><td class="num">{{ tnd($s, false) }}</td></tr>
                @empty <tr><td colspan="7"><x-empty title="Aucun mouvement"/></td></tr> @endforelse
            </tbody>
            @if($mouvements->count())<tfoot><tr><td colspan="4">Totaux</td><td class="num">{{ tnd($mouvements->sum('debit'), false) }}</td><td class="num">{{ tnd($mouvements->sum('credit'), false) }}</td><td class="num">{{ tnd($s, false) }}</td></tr></tfoot>@endif
            </table></div>
        @endif

    @elseif($tab === 'plan')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card lg:col-span-2"><div class="card-h"><h2 class="card-t">Plan comptable</h2><span class="text-xs text-slate-400">Plan comptable tunisien (système comptable des entreprises) · {{ count($plan) }} comptes</span></div>
                <div class="max-h-[70vh] overflow-y-auto"><table class="tbl"><tbody>@foreach($plan as $n => $l)<tr><td class="w-24 font-mono text-xs">{{ $n }}</td><td>{{ $l }}</td></tr>@endforeach</tbody></table></div></div>
            <form method="POST" action="{{ route('compta.comptes.store') }}" class="card card-b space-y-3 self-start">@csrf
                <h2 class="card-t">Ajouter un compte</h2>
                <x-field label="Numéro" name="numero" required placeholder="6011"/>
                <x-field label="Libellé" name="libelle" required/>
                <button class="btn-primary w-full">Ajouter</button>
            </form>
        </div>

    @elseif($tab === 'vte')
        @include('compta._ventes')

    @elseif($tab === 'bq')
        @include('compta._banque')

    @elseif($tab === 'corb')
        @include('compta._supprimees')

    @elseif($tab === 'param')
        @include('compta._parametrage')
    @endif

    @include('compta._import-releve')
</x-layouts.app>
