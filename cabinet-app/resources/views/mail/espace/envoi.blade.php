@extends('mail.espace._layout')
@section('contenu')
    <p>Bonjour,</p>
    <p><strong>{{ $u->name }}</strong> ({{ $client->nom }}) a envoyé {{ $docs->count() > 1 ? $docs->count().' documents' : 'un document' }} depuis l’espace client :</p>
    <ul style="padding-left:18px">
        @foreach($docs as $d)<li><strong>{{ $d->nom }}</strong> <span style="color:#64748b">({{ $d->categorie }})</span></li>@endforeach
    </ul>
    @if($commentaire)
        <p style="margin:16px 0 4px;color:#64748b;font-size:12px">Message du client :</p>
        <div style="border-left:3px solid #0e7490;padding:8px 12px;background:#f8fafc;white-space:pre-line">{{ $commentaire }}</div>
    @endif
    @include('mail.espace._bouton', ['libelle' => 'Voir les échanges du client'])
@endsection
