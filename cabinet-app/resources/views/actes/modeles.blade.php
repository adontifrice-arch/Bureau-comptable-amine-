<x-layouts.app title="Modèles d’actes">
    @php $bal = fn ($b) => '{'.'{'.$b.'}'.'}'; @endphp
    <x-page-header title="Modèles d’actes" subtitle="Statuts, PV et contrats de cession du cabinet, remplis automatiquement depuis la fiche client" :back="url()->previous() !== url()->current() ? url()->previous() : route('clients.index')"/>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <form method="POST" action="{{ route('modeles-actes.store') }}" enctype="multipart/form-data" class="card">@csrf
                <div class="card-h"><h2 class="card-t">Importer un modèle</h2></div>
                <div class="card-b grid gap-4 md:grid-cols-2">
                    <x-select label="Type de document" name="type" :value="old('type')" :options="$types->all()"/>
                    <x-field label="Nom du modèle" name="nom" required placeholder="Statuts SARL — modèle du cabinet"/>
                    <div class="md:col-span-2">
                        <label class="label" for="f-fichier">Fichier Word (.docx) ou texte (.txt), 20 Mo maximum</label>
                        <input id="f-fichier" type="file" name="fichier" accept=".docx,.txt" required class="input">
                        @error('fichier')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-slate-400">Placez les balises dans le texte, par exemple « La société @{{DENOMINATION}} au capital de @{{CAPITAL}} ». Avec un modèle Word, la mise en forme est conservée dans le document Word rempli.</p>
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-100 p-4 dark:border-slate-800"><button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Importer</button></div>
            </form>

            <div class="card">
                <div class="card-h"><h2 class="card-t">Modèles du cabinet</h2><span class="badge-gray">{{ $modeles->count() }}</span></div>
                @if($modeles->count())
                    <div class="overflow-x-auto"><table class="tbl">
                        <thead><tr><th>Modèle</th><th>Type</th><th>Balises</th><th>Importé</th><th></th></tr></thead><tbody>
                        @foreach($modeles as $m)
                            @php $inconnues = array_diff($m->balises ?? [], \App\Services\Actes\Balises::noms()); @endphp
                            <tr>
                                <td><div class="font-medium">{{ $m->nom }}</div><div class="text-xs text-slate-500">{{ $m->fichier_nom }} · {{ strtoupper($m->format) }}</div></td>
                                <td class="text-sm">{{ $types[$m->type] ?? $m->type }}</td>
                                <td class="text-xs">
                                    <span class="badge-blue">{{ count($m->balises ?? []) - count($inconnues) }} reconnue(s)</span>
                                    @if($inconnues)<span class="badge-amber" title="{{ implode(', ', $inconnues) }}">{{ count($inconnues) }} inconnue(s)</span>@endif
                                </td>
                                <td class="text-xs text-slate-500">{{ dfr($m->created_at) }}{{ $m->user ? ' · '.$m->user->name : '' }}<br>{{ $m->actes_count }} document(s) rédigé(s)</td>
                                <td class="whitespace-nowrap text-right">
                                    <a href="{{ route('modeles-actes.download', $m) }}" class="btn-icon" title="Télécharger le fichier d’origine"><x-icon name="download" class="h-4 w-4"/></a>
                                    <x-confirm-delete :action="route('modeles-actes.destroy', $m)" message="Supprimer ce modèle ? Les documents déjà rédigés sont conservés."/>
                                </td>
                            </tr>
                        @endforeach
                    </tbody></table></div>
                @else
                    <x-empty icon="file-text" title="Aucun modèle importé">Sans modèle, les documents sont rédigés avec les textes intégrés à l’application.</x-empty>
                @endif
            </div>
        </div>

        <div class="card self-start">
            <div class="card-h"><h2 class="card-t">Balises disponibles</h2></div>
            <div class="card-b space-y-4 text-sm">
                @foreach(\App\Services\Actes\Balises::LISTE as $groupe => $balises)
                    <div>
                        <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $groupe }}</h3>
                        <dl class="space-y-1">
                            @foreach($balises as $b => $desc)
                                <div class="flex items-baseline justify-between gap-3"><dt><code class="rounded bg-slate-100 px-1 text-xs dark:bg-slate-800">{{ $bal($b) }}</code></dt><dd class="text-right text-xs text-slate-500">{{ $desc }}</dd></div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
                <p class="text-xs text-slate-400">Une information absente de la fiche est remplacée par « ……… », à compléter à la main.</p>
            </div>
        </div>
    </div>
</x-layouts.app>
