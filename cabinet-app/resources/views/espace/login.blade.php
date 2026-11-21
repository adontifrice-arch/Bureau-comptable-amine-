<x-layouts.espace title="Connexion">
    <div class="mx-auto mt-6 max-w-sm">
        <div class="card card-b">
            <h1 class="text-xl font-semibold tracking-tight">Connexion à votre espace client</h1>
            <p class="mt-1 text-sm text-slate-500">Envoyez vos pièces au cabinet et retrouvez les documents qu’il met à votre disposition.</p>
            <form method="POST" action="{{ route('espace.login') }}" class="mt-6 space-y-4">@csrf
                <x-field label="Adresse e-mail" name="email" type="email" required autofocus autocomplete="username"/>
                <x-field label="Mot de passe" name="password" type="password" required autocomplete="current-password"/>
                <button class="btn-primary w-full !py-2.5">Se connecter</button>
            </form>
            <p class="mt-5 text-center text-sm"><a href="{{ route('espace.oubli') }}" class="font-medium text-brand-700 hover:underline">Mot de passe oublié ou première connexion ?</a></p>
        </div>
        <p class="mt-4 text-center text-xs text-slate-400">Pas encore d’accès ? Demandez à votre cabinet de vous inviter.</p>
    </div>
</x-layouts.espace>
