@extends('mail.espace._layout')
@section('contenu')
    <p>Bonjour {{ $u->name }},</p>
    <p>{{ $cab['nom'] }} vous ouvre un espace client pour le dossier <strong>{{ $client->nom }}</strong>. Vous pourrez y envoyer vos pièces au cabinet (factures, relevés bancaires, documents de paie…) et télécharger les documents que le cabinet met à votre disposition.</p>
    <p>Pour activer votre accès, choisissez votre mot de passe :</p>
    @include('mail.espace._bouton', ['libelle' => 'Activer mon espace client'])
    <p style="color:#64748b;font-size:12px">Ce lien est personnel et valable {{ $jours }} jours. Votre identifiant de connexion est {{ $u->email }}.</p>
@endsection
