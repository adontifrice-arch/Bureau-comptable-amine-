@extends('mail.espace._layout')
@section('contenu')
    <p>Bonjour {{ $u->name }},</p>
    <p>{{ $cab['nom'] }} a mis {{ $docs->count() > 1 ? 'de nouveaux documents' : 'un nouveau document' }} à votre disposition pour le dossier <strong>{{ $client->nom }}</strong> :</p>
    <ul style="padding-left:18px">
        @foreach($docs as $d)<li><strong>{{ $d->nom }}</strong> <span style="color:#64748b">({{ $d->categorie }})</span></li>@endforeach
    </ul>
    @include('mail.espace._bouton', ['libelle' => 'Ouvrir mon espace client'])
@endsection
