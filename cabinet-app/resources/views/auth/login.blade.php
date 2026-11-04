<x-layouts.guest title="Connexion">
    <h1 class="text-2xl font-semibold tracking-tight">Connexion</h1>
    <p class="mt-1 text-sm text-slate-500">Accédez à l’espace de gestion du cabinet.</p>
    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-4">
        @csrf
        <x-field label="Adresse e-mail" name="email" type="email" required autofocus autocomplete="username"/>
        <x-field label="Mot de passe" name="password" type="password" required autocomplete="current-password"/>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-600"> Rester connecté</label>
        <button class="btn-primary w-full !py-2.5">Se connecter</button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-500">Nouveau collaborateur ? <a href="{{ route('register') }}" class="font-medium text-brand-700 hover:underline">Créer un compte</a></p>
</x-layouts.guest>
