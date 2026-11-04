<x-layouts.app :title="$acte->titre">
    <form method="POST" action="{{ route('actes.update', $acte) }}" x-data @submit="$refs.c.value = $refs.ed.innerHTML">@csrf @method('PUT')
        <div class="no-print">
            <x-page-header :title="$acte->titre" :subtitle="$acte->client->nom.' · brouillon à relire avant signature'" :back="route('clients.show', [$acte->client, 'tab' => 'actes'])">
                <button type="button" onclick="window.print()" class="btn-secondary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button>
                <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Enregistrer</button>
            </x-page-header>
            <div class="mb-3 flex flex-wrap items-center gap-1">
                <input name="titre" value="{{ $acte->titre }}" class="input mr-3 w-96">
                @foreach([['bold','Gras'],['italic','Italique'],['underline','Souligné'],['insertUnorderedList','• Liste'],['insertOrderedList','1. Liste'],['justifyLeft','Gauche'],['justifyCenter','Centrer'],['justifyFull','Justifier']] as [$c, $l])
                    <button type="button" class="btn-secondary btn-sm" onmousedown="event.preventDefault(); document.execCommand('{{ $c }}')">{{ $l }}</button>
                @endforeach
            </div>
        </div>
        <input type="hidden" name="contenu" x-ref="c">
        <div x-ref="ed" contenteditable="true" spellcheck="true" class="print-area prose-doc mx-auto min-h-[60vh] max-w-3xl rounded-2xl border border-slate-200 bg-white p-12 text-justify shadow-xl focus:outline-none">{!! $acte->contenu !!}</div>
    </form>
</x-layouts.app>
