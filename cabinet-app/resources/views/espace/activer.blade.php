<x-layouts.espace :title="$u->estActive() ? 'Nouveau mot de passe' : 'Activer mon accès'">
    <div class="mx-auto mt-6 max-w-sm">
        <div class="card card-b">
            <h1 class="text-xl font-semibold tracking-tight">{{ $u->estActive() ? 'Choisir un nouveau mot de passe' : 'Activer votre espace client' }}</h1>
            <p class="mt-1 text-sm text-slate-500">Bonjour {{ $u->name }}, choisissez le mot de passe de votre accès <strong>{{ $u->email }}</strong> (8 caractères au moins).</p>
            <form method="POST" action="{{ route('espace.activer', $jeton) }}" class="mt-6 space-y-4">@csrf
                <x-field label="Mot de passe" name="password" type="password" required autofocus autocomplete="new-password"/>
                <x-field label="Confirmation" name="password_confirmation" type="password" required autocomplete="new-password"/>
                <button class="btn-primary w-full !py-2.5">Enregistrer et accéder à mon espace</button>
            </form>
        </div>
    </div>
</x-layouts.espace>
