{{-- Barre d'actions de l'aperçu à l'écran (masquée à l'impression et absente du PDF). --}}
@if($impression)
    <div class="actions">
        <button onclick="window.print()">Imprimer</button>
        <button onclick="history.back()">Retour</button>
        @foreach(['pdf' => 'PDF', 'xlsx' => 'Excel', 'docx' => 'Word'] as $f => $l)
            <a href="{{ route('paie.edition', [$etat, $f, 'mois' => $mois, 'code' => $f === 'pdf' ? $code : null]) }}">{{ $l }}</a>
        @endforeach
        @if(count($codes) > 1)
            {{-- Une déclaration par code d'exploitation : régime général et contrats aidés (Dignité, Exo 2 ans…) --}}
            <span style="margin-left: 16px">Code d’exploitation :</span>
            @foreach($codes as $k)
                @if($k === $code)<b style="margin-right: 8px">{{ $k }}</b>@else<a href="{{ route('paie.edition', [$etat, 'imprimer', 'mois' => $mois, 'code' => $k]) }}">{{ $k }}</a>@endif
            @endforeach
        @endif
    </div>
@endif
