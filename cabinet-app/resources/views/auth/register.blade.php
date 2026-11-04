<x-layouts.guest title="Inscription">
    <h1 class="text-2xl font-semibold tracking-tight">Créer un compte</h1>
    <p class="mt-1 text-sm text-slate-500">Le premier compte créé devient chef comptable. Les suivants sont validés par un chef comptable.</p>
    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-4">
        @csrf
        <x-field label="Nom et prénom" name="name" required/>
        <x-field label="Adresse e-mail" name="email" type="email" required/>
        <x-field label="Mot de passe (8 caractères minimum)" name="password" type="password" required autocomplete="new-password"/>
        <x-field label="Confirmer le mot de passe" name="password_confirmation" type="password" required/>
        <button class="btn-primary w-full !py-2.5">Créer le compte</button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-500"><a href="{{ route('login') }}" class="font-medium text-brand-700 hover:underline">← Retour à la connexion</a></p>
</x-layouts.guest>
