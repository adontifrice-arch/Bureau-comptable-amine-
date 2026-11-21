@extends('mail.espace._layout')
@section('contenu')
    <p>Bonjour {{ $u->name }},</p>
    <p>Voici votre lien pour choisir un nouveau mot de passe d’accès à l’espace client du dossier <strong>{{ $client->nom }}</strong>.</p>
    @include('mail.espace._bouton', ['libelle' => 'Choisir mon mot de passe'])
    <p style="color:#64748b;font-size:12px">Ce lien est personnel et valable {{ $jours }} jours. Si vous n’avez rien demandé, ignorez cet e-mail : votre mot de passe actuel reste valable.</p>
@endsection
