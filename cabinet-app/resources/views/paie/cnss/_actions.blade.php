{{-- Barre d'actions de l'aperçu à l'écran (masquée à l'impression et absente du PDF). --}}
@if($impression)
    <div class="actions">
        <button onclick="window.print()">Imprimer</button>
        <button onclick="history.back()">Retour</button>
        @foreach(['pdf' => 'PDF', 'xlsx' => 'Excel', 'docx' => 'Word'] as $f => $l)
            <a href="{{ route('paie.edition', [$etat, $f, 'mois' => $mois]) }}">{{ $l }}</a>
        @endforeach
    </div>
@endif
