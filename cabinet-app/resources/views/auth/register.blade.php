<x-layouts.guest title="Inscription">
    @if($invitation)
        <h1 class="text-2xl font-semibold tracking-tight">Rejoindre {{ $invitation->nom }}</h1>
        <p class="mt-1 text-sm text-slate-500">Votre compte collaborateur sera actif dès qu’un chef comptable du cabinet l’aura validé.</p>
    @else
        <h1 class="text-2xl font-semibold tracking-tight">Créer votre cabinet</h1>
        <p class="mt-1 text-sm text-slate-500">Vous serez chef comptable du nouveau cabinet. Pour rejoindre un cabinet existant, demandez son lien d’invitation à un chef comptable.</p>
    @endif
    <form method="POST" action="{{ $invitation ? route('register.invitation', $invitation->code_invitation) : route('register') }}" class="mt-8 space-y-4">
        @csrf
        @unless($invitation)<x-field label="Nom du cabinet" name="cabinet" required placeholder="Cabinet …"/>@endunless
        <x-field label="Nom et prénom" name="name" required/>
        <x-field label="Adresse e-mail" name="email" type="email" required/>
        <x-field label="Mot de passe (8 caractères minimum)" name="password" type="password" required autocomplete="new-password"/>
        <x-field label="Confirmer le mot de passe" name="password_confirmation" type="password" required/>
        <button class="btn-primary w-full !py-2.5">{{ $invitation ? 'Demander l’accès' : 'Créer le cabinet' }}</button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-500"><a href="{{ route('login') }}" class="font-medium text-brand-700 hover:underline">← Retour à la connexion</a></p>
</x-layouts.guest>
