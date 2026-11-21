@props(['title' => null, 'u' => null, 'client' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' · ' : '' }}Espace client{{ $u ? ' · '.$cab['nom'] : '' }}</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f3f6f7] font-sans text-slate-800 antialiased">
<header class="bg-brand-950 text-white">
    <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4 sm:px-6">
        @if($u && !empty($cab['logo']))
            <img src="{{ asset('storage/'.$cab['logo']) }}" alt="" class="h-9 w-9 rounded-xl bg-white object-contain p-1">
        @else
            <div class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-sm font-bold"><x-icon name="folder" class="h-4 w-4"/></div>
        @endif
        <div class="min-w-0 leading-tight">
            <div class="truncate text-sm font-semibold">{{ $u ? $cab['nom'] : 'Espace client' }}</div>
            <div class="truncate text-[11px] text-brand-200/70">{{ $client ? 'Espace client · '.$client->nom : 'Échange de documents avec votre cabinet' }}</div>
        </div>
        @if($u)
            <div class="ml-auto flex items-center gap-3">
                <span class="hidden text-right text-sm leading-tight sm:block">{{ $u->name }}<span class="block text-[11px] text-brand-200/70">{{ $u->email }}</span></span>
                <form method="POST" action="{{ route('espace.logout') }}">@csrf
                    <button class="inline-flex items-center gap-1.5 rounded-lg border border-white/15 px-3 py-1.5 text-sm hover:bg-white/10"><x-icon name="logout" class="h-4 w-4"/> Se déconnecter</button>
                </form>
            </div>
        @endif
    </div>
</header>
<main class="mx-auto max-w-5xl px-4 py-7 sm:px-6">
    @if(session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><x-icon name="check" class="h-4 w-4"/> {{ session('ok') }}</div>
    @endif
    @if(session('err') || $errors->any())
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
            <div>{{ session('err') ?? 'Veuillez corriger les champs signalés.' }}
                @if($errors->any() && !session('err'))<ul class="mt-1 list-disc pl-4 text-xs">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
            </div>
        </div>
    @endif
    {{ $slot }}
</main>
</body>
</html>
