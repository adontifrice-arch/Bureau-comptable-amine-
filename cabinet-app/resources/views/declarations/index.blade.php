@php $lock = $d->verrouillee(); $st = ['brouillon' => ['Brouillon', 'gray'], 'validee' => ['Validée', 'amber'], 'deposee' => ['Déposée', 'green']][$d->statut];
     $lab = ['men' => 'mensuelle', 'ann' => 'annuelle', 'emp' => 'd’employeur', 'sach' => 'des achats en suspension de TVA', 'sven' => 'des ventes en suspension de TVA'][$type]; $q = ['type' => $type, 'periode' => $periode];
     $susp = isset(\App\Http\Controllers\DeclarationController::SUSPENSION[$type]); $mens = \App\Http\Controllers\DeclarationController::mensuelle($type); @endphp
<x-layouts.app title="Déclarations">
    <x-page-header title="Déclarations fiscales" :subtitle="$client->nom">
        <x-client-picker :clients="$clients" :current="$client" :route="route('declarations.index')" :params="['type' => $type]"/>
    </x-page-header>
    @include('declarations._onglets', ['actif' => $type])

    @php $DC = \App\Http\Controllers\DeclarationController::class; $trim = $DC::trimestrielle($type); $fmt = $trim ? $DC::format($type) : null; @endphp
    <div class="card mb-6 flex flex-wrap items-center gap-4 p-4">
        <form class="flex items-end gap-2"><input type="hidden" name="type" value="{{ $type }}">
            <div><label class="label">{{ $trim ? 'Trimestre' : ($mens ? 'Mois' : 'Année') }}</label>
                @if($trim)
                    <select name="periode" class="input" onchange="this.form.submit()">@for($y = now()->year; $y >= now()->year - 3; $y--)@for($t = 4; $t >= 1; $t--)<option value="{{ $y }}-T{{ $t }}" @selected($periode === "$y-T$t")>{{ $DC::libellePeriode($type, "$y-T$t") }}</option>@endfor @endfor</select>
                @elseif($mens)<input type="month" name="periode" value="{{ $periode }}" class="input" onchange="this.form.submit()">@else<input type="number" name="periode" value="{{ $periode }}" class="input w-28" onchange="this.form.submit()">@endif</div>
        </form>
        <div class="flex-1"><div class="font-semibold">{{ $trim ? $DC::SUSPENSION[$type] : 'Déclaration '.$lab }} · {{ $DC::libellePeriode($type, $periode) }}</div>
            <div class="text-xs text-slate-500">{{ $d->validee_le ? 'Validée le '.dfr($d->validee_le) : '' }} {{ $d->deposee_le ? '· déposée le '.dfr($d->deposee_le) : '' }}</div></div>
        <span class="badge-{{ $st[1] }}">{{ $st[0] }}</span>
        <div class="flex flex-wrap gap-2">
            @unless($lock)<form method="POST" action="{{ route('declarations.prefill') }}">@include('declarations._hidden')<button class="btn-secondary"><x-icon name="sparkles" class="h-4 w-4"/> Pré-remplir</button></form>@endunless
            <form method="POST" action="{{ route('declarations.statut') }}">@include('declarations._hidden')
                @if($d->statut === 'brouillon')<button name="action" value="valider" class="btn-secondary">Valider</button>
                @elseif($d->statut === 'validee')<button name="action" value="deposer" class="btn-success">Marquer déposée</button><button name="action" value="rouvrir" class="btn-ghost">Rouvrir</button>
                @else<button name="action" value="rouvrir" class="btn-ghost">Rouvrir</button>@endif
            </form>
            <a href="{{ route('declarations.csv', $q) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/> Excel</a>
            @if($type === 'emp')
                <a href="{{ route('declarations.fichier', $q) }}" class="btn-secondary" title="DECEMP et ANXEMP au format du cahier des charges DGI, à graver sur CD"><x-icon name="download" class="h-4 w-4"/> Fichiers DGI</a>
            @endif
            @if($trim)
                <a href="{{ route('declarations.fichier', $q) }}" class="btn-secondary" title="Fichier au format du cahier des charges DGI, à graver sur CD"><x-icon name="download" class="h-4 w-4"/> Fichier {{ $fmt::nomFichier($periode) }}</a>
                <a href="{{ route('declarations.bordereau', $q) }}" class="btn-secondary"><x-icon name="printer" class="h-4 w-4"/> Bordereau</a>
                @if($type === 'sven')<a href="{{ route('declarations.liste', $q) }}" class="btn-secondary" title="Modèle papier en arabe, avec le reçu de dépôt"><x-icon name="printer" class="h-4 w-4"/> Liste détaillée</a>@endif
            @endif
            @if(in_array($type, ['men', 'ann'], true))<a href="{{ route('declarations.arabe', $q) }}" class="btn-secondary" title="Modèle officiel DGI en arabe, rempli"><x-icon name="printer" class="h-4 w-4"/> Formulaire arabe</a>@endif
            <a href="{{ route('declarations.print', $q) }}" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> PDF</a>
        </div>
    </div>

    @if($susp)
        @php $tot = $DC::totauxSuspension($d); $tiers = $type === 'sach' ? 'Fournisseur' : 'Client';
             $erreurs = collect($d->annexes['l'] ?? [])->filter(fn ($l) => $fmt::controles($l, $d->champs ?? []))->count(); @endphp
        <form method="POST" action="{{ route('declarations.save') }}" class="card mb-6 grid gap-4 p-4 md:grid-cols-4">@include('declarations._hidden')
            @if($type === 'sach')<x-field label="N° autorisation d’achat en suspension (12 car.)" name="f[aut]" :value="$d->champs['aut'] ?? ''" maxlength="30" :disabled="$lock" hint="Repris sur chaque ligne, sauf numéro propre à la ligne."/>@endif
            <x-field label="Activité du déclarant" name="f[activite]" :value="$d->champs['activite'] ?? ''" maxlength="40" :disabled="$lock"/>
            <x-field label="Adresse du déclarant" name="f[adresse]" :value="$d->champs['adresse'] ?? ''" maxlength="120" :disabled="$lock" :span="$type === 'sven' ? 'md:col-span-2' : null"/>
            <div class="flex items-end justify-between gap-2"><div class="text-xs text-slate-500">MF {{ $client->matricule_fiscal ?: '—' }}@unless($fmt::matricule($client->matricule_fiscal))<div class="text-rose-600">Matricule du dossier à compléter (ex. 1234567/A/B/M/000).</div>@endunless</div>@unless($lock)<button class="btn-secondary">Enregistrer</button>@endunless</div>
        </form>
        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <x-stat label="Factures déclarées" :value="$tot['n']" icon="list"/>
            @if($type === 'sach')<x-stat label="Bons de commandes" :value="$fmt::totaux($d)['bons']" icon="list"/>
            @else<x-stat label="FODEC + droit de consommation" :value="tnd($tot['fodec'] + $tot['dc'])" icon="scale"/>@endif
            <x-stat label="Montant HT en suspension" :value="tnd($tot['ht'])" icon="wallet"/>
            <x-stat label="TVA suspendue" :value="tnd($tot['tva'])" icon="scale" tone="amber"/>
        </div>
        @if($erreurs)<div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10">{{ $erreurs }} ligne{{ $erreurs > 1 ? 's' : '' }} non conforme{{ $erreurs > 1 ? 's' : '' }} au cahier des charges : corrigez-les avant de générer le fichier.</div>@endif
        <div class="card">
            <div class="card-h"><h2 class="card-t">{{ $DC::SUSPENSION[$type] }}</h2>@unless($lock)<button class="btn-primary btn-sm" x-data @click="$dispatch('open-modal', 'susp')"><x-icon name="plus" class="h-3.5 w-3.5"/> Ligne</button>@endunless</div>
            <div class="overflow-x-auto">@include('declarations._suspension')</div>
        </div>
        <div class="card card-b mt-4 text-xs text-slate-500">{{ $type === 'sach'
            ? 'Une ligne par bon de commande et par facture (facture relevant de plusieurs bons : montants ventilés par bon). Ne déclarer que les bons de commandes émis et réglés par des factures.'
            : 'Une ligne par facture émise en suspension : client (matricule fiscal, CIN, carte de séjour ou identifiant de non-résident), autorisation administrative relative à l’opération de vente, prix HT, FODEC, droit de consommation et TVA non facturés.' }}
            Montants exportés en millimes, dates au format JJMMAAAA. « Pré-remplir » reprend les factures {{ $type === 'sach' ? 'd’achat' : 'de vente' }} validées du trimestre cochées « en suspension de TVA » (lignes « auto ») ; les lignes saisies à la main sont conservées.</div>
        <x-modal name="susp" title="Nouvelle ligne">
            @php [$deb] = $DC::bornes($type, $periode); @endphp
            <form method="POST" action="{{ route('declarations.suspension.store') }}" class="grid gap-4 md:grid-cols-2">@include('declarations._hidden')
                @if($type === 'sach')
                    <x-field label="N° bon de commande * (chiffres)" name="att" required inputmode="numeric" maxlength="13"/>
                    <x-field label="Date du bon de commande *" name="att_date" type="date" required/>
                    <x-field label="Fournisseur (raison sociale) *" name="nom" required maxlength="40"/>
                    <x-field label="Matricule fiscal du fournisseur *" name="id" required maxlength="20" hint="13 caractères, ex. 1234567ABM000"/>
                    <x-field label="N° facture *" name="fac" required maxlength="30"/>
                    <x-field label="Date facture *" name="fac_date" type="date" required :value="$deb"/>
                    <x-field label="Prix d’achat HT *" name="ht" type="number" step="0.001" min="0" required/>
                    <x-field label="Taux de TVA (%)" name="taux" type="number" step="0.01" value="19"/>
                    <x-field label="TVA suspendue (si différente du taux)" name="tva" type="number" step="0.001" min="0"/>
                    <x-field label="N° autorisation (si différent)" name="aut" maxlength="12" :hint="'Par défaut : '.(($d->champs['aut'] ?? '') ?: '—')"/>
                    <x-field label="Objet de la facture" name="objet" type="textarea" maxlength="320" span="md:col-span-2"/>
                @else
                    <x-field label="N° facture *" name="fac" required maxlength="20"/>
                    <x-field label="Date facture *" name="fac_date" type="date" required :value="$deb"/>
                    <x-field label="Client (nom / raison sociale) *" name="nom" required maxlength="40" span="md:col-span-2"/>
                    <x-select label="Type d’identifiant *" name="idk" :options="\App\Services\Exports\FacturesSuspension::TYPES_ID"/>
                    <x-field label="Identifiant *" name="id" required maxlength="20" hint="Matricule fiscal (13 car.) ou numéro à 8 chiffres"/>
                    <x-field label="Adresse du client" name="adr" maxlength="120" span="md:col-span-2"/>
                    <x-field label="N° autorisation administrative *" name="att" required maxlength="20"/>
                    <x-field label="Date de l’autorisation *" name="att_date" type="date" required/>
                    <x-field label="Prix de vente HT *" name="ht" type="number" step="0.001" min="0" required span="md:col-span-2"/>
                    <x-field label="Taux FODEC (%)" name="fodec_taux" type="number" step="0.01" value="0"/>
                    <x-field label="Montant FODEC (si différent du taux)" name="fodec" type="number" step="0.001" min="0"/>
                    <x-field label="Taux droit de consommation (%)" name="dc_taux" type="number" step="0.01" value="0"/>
                    <x-field label="Montant droit de consommation (si différent)" name="dc" type="number" step="0.001" min="0"/>
                    <x-field label="Taux de TVA (%)" name="taux" type="number" step="0.01" value="19"/>
                    <x-field label="TVA suspendue (si différente)" name="tva" type="number" step="0.001" min="0" hint="Par défaut : taux × (HT + FODEC + droit de consommation)"/>
                @endif
                <div class="flex items-end justify-end md:col-span-2"><button class="btn-primary">Ajouter</button></div>
            </form>
        </x-modal>
    @elseif($type === 'ann')
        @include('declarations._annuelle')
    @elseif($type === 'men')
        @include('declarations._mensuelle')
    @elseif($type !== 'emp')
        <div class="grid gap-6 lg:grid-cols-3">
            <form method="POST" action="{{ route('declarations.save') }}" class="card overflow-hidden lg:col-span-2">@include('declarations._hidden')
                @include('declarations._champs')
                @unless($lock)<div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-primary">Enregistrer</button></div>@endunless
            </form>
            <div class="space-y-4 self-start">
                @if($type === 'men')
                    <x-stat label="TVA {{ ($calc['tvap'] ?? 0) >= 0 ? 'à payer' : 'crédit à reporter' }}" :value="tnd(abs($calc['tvap'] ?? 0))" icon="scale" :tone="($calc['tvap'] ?? 0) >= 0 ? 'amber' : 'green'"/>
                    <x-stat label="Total à payer" :value="tnd($calc['tot'] ?? 0)" icon="wallet"/>
                @else
                    <x-stat label="Résultat fiscal" :value="tnd($calc['rf'] ?? 0)" icon="trend"/>
                    <x-stat label="Impôt dû" :value="tnd($calc['isd'] ?? 0)" icon="scale" tone="amber"/>
                    <x-stat label="{{ ($calc['sol'] ?? 0) >= 0 ? 'Solde à payer' : 'Crédit d’impôt' }}" :value="tnd(abs($calc['sol'] ?? 0))" icon="wallet" :tone="($calc['sol'] ?? 0) >= 0 ? 'red' : 'green'"/>
                @endif
                <div class="card card-b text-xs text-slate-500">« Pré-remplir » reprend les écritures comptables validées, la paie clôturée et les factures reçues{{ $type === 'ann' ? ', ainsi que le tableau de passage de la liasse fiscale' : '' }}. Vérifiez chaque montant avant le dépôt sur la plateforme de télédéclaration.</div>
            </div>
        </div>
    @else
        @include('declarations._employeur')
    @endif
</x-layouts.app>
