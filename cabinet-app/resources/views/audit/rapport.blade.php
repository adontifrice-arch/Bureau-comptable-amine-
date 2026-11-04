<x-layouts.app title="Rapport d’audit">
    <div class="no-print"><x-page-header title="Rapport" :subtitle="$m->client->nom.' · '.$m->annee" :back="route('audit.index', ['tab' => 'rap', 'annee' => $m->annee, 'mission' => $m->id])"><button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    <div class="print-area prose-doc mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-12 shadow-xl">{!! $m->rapport['html'] ?? '' !!}</div>
</x-layouts.app>
