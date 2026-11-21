<x-layouts.espace title="Mot de passe oublié">
    <div class="mx-auto mt-6 max-w-sm">
        <div class="card card-b">
            <h1 class="text-xl font-semibold tracking-tight">Recevoir un lien de connexion</h1>
            <p class="mt-1 text-sm text-slate-500">Indiquez l’adresse e-mail de votre accès : vous recevrez un lien pour choisir votre mot de passe.</p>
            <form method="POST" action="{{ route('espace.oubli') }}" class="mt-6 space-y-4">@csrf
                <x-field label="Adresse e-mail" name="email" type="email" required autofocus autocomplete="username"/>
                <button class="btn-primary w-full !py-2.5">Envoyer le lien</button>
            </form>
            <p class="mt-5 text-center text-sm"><a href="{{ route('espace.login') }}" class="font-medium text-brand-700 hover:underline">Retour à la connexion</a></p>
        </div>
    </div>
</x-layouts.espace>
