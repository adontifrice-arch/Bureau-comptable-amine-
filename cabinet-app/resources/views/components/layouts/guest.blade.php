@props(['title' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $cab['nom'] ?? config('app.name') }}</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f3f6f7] font-sans text-slate-800 antialiased">
<div class="grid min-h-screen lg:grid-cols-2">
    <div class="relative hidden overflow-hidden bg-brand-950 lg:block">
        <div class="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-brand-600/30 blur-3xl"></div>
        <div class="absolute -bottom-32 right-0 h-[28rem] w-[28rem] rounded-full bg-brand-400/20 blur-3xl"></div>
        <div class="relative flex h-full flex-col justify-between p-12 text-white">
            <div class="flex items-center gap-3">
                <div class="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 to-brand-600 font-bold shadow-lg">AB</div>
                <div><div class="font-semibold">{{ $cab['nom'] }}</div><div class="text-xs text-brand-200/70">Expertise comptable · Audit · Conseil</div></div>
            </div>
            <div>
                <h2 class="max-w-md text-3xl font-semibold leading-tight">Tous les dossiers du cabinet, au même endroit.</h2>
                <p class="mt-4 max-w-md text-brand-100/70">Clients, échéances fiscales, facturation, comptabilité, paie, liasse, déclarations et missions d’audit.</p>
                <div class="mt-8 grid max-w-md grid-cols-3 gap-3 text-xs">
                    @foreach(['TVA & retenues', 'Paie CNSS', 'États financiers'] as $t)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-brand-100">{{ $t }}</div>
                    @endforeach
                </div>
            </div>
            <div class="text-xs italic text-brand-200/50">« {{ $cab['slogan'] ?? '' }} »</div>
        </div>
    </div>
    <div class="flex items-center justify-center p-6">
        <div class="w-full max-w-sm">
            @if(session('ok'))<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('ok') }}</div>@endif
            @if(session('err'))<div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('err') }}</div>@endif
            {{ $slot }}
        </div>
    </div>
</div>
</body>
</html>
