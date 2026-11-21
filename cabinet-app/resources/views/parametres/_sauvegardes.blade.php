@use('App\Services\Sauvegardes')
@php
    $s = $sauvegarde;
    $elements = old('elements', $s['elements']);
    $jours = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
    $ko = fn ($n) => $n > 1048576 ? number_format($n / 1048576, 1, ',', ' ').' Mo' : max(1, round($n / 1024)).' Ko';
@endphp
<div class="grid gap-6 lg:grid-cols-5">
    <form method="POST" action="{{ route('parametres.sauvegardes') }}" class="card self-start lg:col-span-3" x-data="{ f: @js(old('frequence', $s['frequence'])), actif: @js((bool) old('actif', $s['actif'])) }">@csrf
        <div class="card-h">
            <h2 class="card-t">Sauvegarde automatique</h2>
            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="actif" value="1" x-model="actif" class="rounded border-slate-300 text-brand-600"> Activée</label>
        </div>
        <div class="card-b space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <div><label class="label" for="f-frequence">Fréquence</label>
                    <select id="f-frequence" name="frequence" class="input" x-model="f">@foreach(Sauvegardes::FREQUENCES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div x-show="f === 'semaine'"><label class="label" for="f-js">Jour</label>
                    <select id="f-js" name="jour_semaine" class="input">@foreach($jours as $k => $v)<option value="{{ $k }}" @selected(old('jour_semaine', $s['jour_semaine']) == $k)>{{ ucfirst($v) }}</option>@endforeach</select></div>
                <div x-show="f === 'mois'"><label class="label" for="f-jm">Jour du mois</label>
                    <input id="f-jm" type="number" min="1" max="31" name="jour_mois" value="{{ old('jour_mois', $s['jour_mois']) }}" class="input">
                    <p class="mt-1 text-xs text-slate-400">29 à 31 : dernier jour pour les mois plus courts.</p></div>
                <div x-show="f === 'annee'"><label class="label" for="f-da">Date (MM-JJ)</label>
                    <input id="f-da" name="date_annee" value="{{ old('date_annee', $s['date_annee']) }}" placeholder="12-31" class="input font-mono"></div>
                <div><label class="label" for="f-heure">Heure</label>
                    <input id="f-heure" type="time" name="heure" value="{{ old('heure', $s['heure']) }}" class="input" required></div>
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between"><span class="label !mb-0">Éléments à sauvegarder</span>
                    <button type="button" class="text-xs font-medium text-brand-700 hover:underline" onclick="this.closest('form').querySelectorAll('input[name=&quot;elements[]&quot;]').forEach(c => c.checked = true)">Tout cocher</button></div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach(Sauvegardes::ELEMENTS as $k => [$lib])
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700"><input type="checkbox" name="elements[]" value="{{ $k }}" @checked(in_array($k, $elements, true)) class="rounded border-slate-300 text-brand-600"> {{ $lib }}</label>
                    @endforeach
                </div>
                @error('elements')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div><label class="label" for="f-conserver">Sauvegardes automatiques conservées</label>
                    <input id="f-conserver" type="number" min="1" max="365" name="conserver" value="{{ old('conserver', $s['conserver']) }}" class="input max-w-[8rem]"></div>
                <div class="flex gap-2">
                    <button class="btn-primary">Enregistrer la programmation</button>
                    <button class="btn-secondary" formaction="{{ route('parametres.sauvegardes.maintenant') }}" title="Sauvegarde immédiate des éléments cochés"><x-icon name="download" class="h-4 w-4"/> Sauvegarder maintenant</button>
                </div>
            </div>

            @if($s['actif'])
                <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
                    Prochaine sauvegarde : <strong>{{ Sauvegardes::prochaine($s, now())->translatedFormat('l j F Y à H:i') }}</strong>.</p>
            @endif
            <p class="text-xs text-slate-500">Les sauvegardes se font à l’heure prévue si le planificateur de tâches tourne sur le serveur (<span class="font-mono">php artisan schedule:run</span> chaque minute) ;
                sinon, une sauvegarde en retard est faite dès qu’un utilisateur ouvre l’application. Dossier : <span class="font-mono">{{ Sauvegardes::dossier(auth()->user()->cabinet_id) }}</span>
                — gardez aussi une copie sur un autre support (clé USB, disque externe, cloud).</p>
        </div>
    </form>

    <div class="card self-start lg:col-span-2">
        <div class="card-h"><h2 class="card-t">Historique</h2><span class="badge-gray">{{ $sauvegardes->count() }}</span></div>
        @forelse($sauvegardes as $x)
            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 dark:border-slate-800">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $x->created_at->format('d/m/Y H:i') }}
                        <span class="{{ $x->declencheur === 'auto' ? 'badge-blue' : 'badge-gray' }}">{{ $x->declencheur === 'auto' ? 'Automatique' : 'Manuelle' }}</span>
                        @if($x->statut !== 'ok')<span class="badge-red">Échec</span>@endif</div>
                    <div class="text-xs text-slate-500">{{ collect($x->elements)->map(fn ($e) => Sauvegardes::ELEMENTS[$e][0] ?? $e)->implode(', ') }}{{ $x->statut === 'ok' ? ' · '.$ko($x->taille) : '' }}{{ $x->user ? ' · '.$x->user->name : '' }}</div>
                    @if($x->message)<div class="text-xs {{ $x->statut === 'ok' ? 'text-amber-700' : 'text-rose-600' }}">{{ $x->message }}</div>@endif
                </div>
                @if($x->statut === 'ok')<a href="{{ route('parametres.sauvegardes.telecharger', $x) }}" class="btn-icon" title="Télécharger"><x-icon name="download" class="h-4 w-4"/></a>@endif
                <x-confirm-delete :action="route('parametres.sauvegardes.destroy', $x)" message="Supprimer cette sauvegarde ?"/>
            </div>
        @empty
            <x-empty icon="folder" title="Aucune sauvegarde">Lancez une première sauvegarde avec « Sauvegarder maintenant ».</x-empty>
        @endforelse
    </div>
</div>
