{{-- Import d'un relevé bancaire (bouton de l'en-tête, disponible sur tous les onglets) --}}
<x-modal name="releve" title="Importer un relevé bancaire">
    <form method="POST" action="{{ route('compta.releves.importer') }}" enctype="multipart/form-data" class="space-y-4" x-data="{ envoi: false }" @submit="envoi = true">@csrf
        <p class="text-sm text-slate-500">Dossier <b>{{ $client->nom }}</b> · compte de banque <b>{{ $saisie['banque']['compte'] }}</b> ({{ \App\Services\Compta::libelle((string) $saisie['banque']['compte']) }}).</p>
        <div><label class="label" for="rb-f">Relevé (Excel, CSV, Word ou PDF)</label>
            <input id="rb-f" type="file" name="fichier" required class="input" accept=".xlsx,.xls,.ods,.csv,.txt,.docx,.pdf,.jpg,.jpeg,.png"></div>
        <ul class="list-disc space-y-1 pl-5 text-xs text-slate-500">
            <li><b>Excel / CSV / Word</b> : tableau avec les colonnes Date, Libellé et Débit / Crédit (ou une colonne Montant signée).</li>
            <li><b>PDF</b> : lu en local ; le sens de chaque opération est déduit du solde. @if(config('services.anthropic.key'))Un PDF scanné ou illisible est lu par l’IA.@else Un PDF scanné nécessite la lecture par l’IA (ANTHROPIC_API_KEY non configurée).@endif</li>
            <li>Les opérations déjà importées sont écartées. Rien n’est comptabilisé avant la validation du chef comptable.</li>
        </ul>
        <div class="flex justify-end"><button class="btn-primary" :disabled="envoi"><x-icon name="upload" class="h-4 w-4"/> <span x-text="envoi ? 'Lecture en cours…' : 'Importer'"></span></button></div>
    </form>
</x-modal>
