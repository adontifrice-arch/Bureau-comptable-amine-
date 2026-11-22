@props(['title' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $cab['nom'] ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&family=IBM+Plex+Serif:wght@400;600&display=swap">
    <script>try{if(localStorage.getItem('theme')==='dark')document.documentElement.classList.add('dark')}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-[#f3f6f7] font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200" x-data="{ nav: false }">
@php
    $u = auth()->user();
    $menu = [
        ['Pilotage', [
            ['dashboard', 'Tableau de bord', 'home', 'dashboard'],
            ['clients.index', 'Clients', 'users', 'clients.*|actes.*'],
            ['echanges.index', 'Échanges clients', 'upload', 'echanges.*', \App\Models\Document::aTraiter()->count()],
            ['echeances.index', 'Échéances', 'calendar', 'echeances.*'],
        ]],
        ['Cabinet', [
            ['factures.index', 'Facturation', 'invoice', 'factures.*|ca.*'],
            ['frais.index', 'Notes de frais', 'receipt', 'frais.*|depenses.*'],
            ['interne.index', 'Interne', 'note', 'interne.*'],
        ]],
        ['Dossiers clients', [
            ['compta.index', 'Comptabilité', 'book', 'compta.*'],
            ['immobilisations.index', 'Immobilisations', 'briefcase', 'immobilisations.*'],
            ['paie.index', 'Paie', 'wallet', 'paie.*'],
            ['liasse.index', 'Liasse fiscale', 'scale', 'liasse.*'],
            ['rne.index', 'RNE', 'list', 'rne.*'],
            ['declarations.index', 'Déclarations', 'file-text', 'declarations.*'],
            ['audit.index', 'Audit & CAC', 'shield', 'audit.*'],
            ['ged.index', 'GED', 'folder', 'ged.*'],
        ]],
    ];
    // Modules autorisés de l'utilisateur (Paramètres → Utilisateurs) ; sections vides retirées
    $menu = collect($menu)->map(fn ($s) => [$s[0], array_values(array_filter($s[1], fn ($i) => $u->peutModule(\App\Support\Droits::moduleDeRoute($i[0]))))])
        ->filter(fn ($s) => $s[1])->values()->all();
@endphp

{{-- Barre latérale --}}
<aside class="no-print fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-brand-950 transition-transform lg:translate-x-0" :class="nav && 'translate-x-0'">
    <div class="flex items-center gap-3 px-5 py-5">
        @if(!empty($cab['logo']))
            <img src="{{ asset('storage/'.$cab['logo']) }}" alt="" class="h-9 w-9 rounded-xl bg-white object-contain p-1">
        @else
            <div class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-sm font-bold text-white shadow-lg shadow-brand-900/40">AB</div>
        @endif
        <div class="min-w-0 leading-tight">
            <div class="truncate text-sm font-semibold text-white">{{ $cab['nom'] }}</div>
            <div class="text-[11px] text-brand-200/70">Expertise comptable</div>
        </div>
    </div>
    <nav class="flex-1 space-y-5 overflow-y-auto px-3 pb-4">
        @foreach($menu as [$section, $items])
            <div>
                <div class="px-3 pb-1.5 text-[10.5px] font-semibold uppercase tracking-wider text-brand-200/40">{{ $section }}</div>
                <div class="space-y-0.5">
                    @foreach($items as $item)
                        @php [$route, $label, $icon, $pattern] = $item; $pastille = $item[4] ?? 0; $active = collect(explode('|', $pattern))->contains(fn($p) => request()->routeIs($p)); @endphp
                        <a href="{{ route($route) }}" class="nav-link {{ $active ? 'nav-link-active' : '' }}">
                            <x-icon :name="$icon" class="h-[18px] w-[18px] {{ $active ? 'text-brand-300' : 'text-brand-200/50' }}"/>
                            {{ $label }}
                            @if($pastille)<span class="ml-auto rounded-full bg-amber-400 px-1.5 text-[11px] font-semibold leading-5 text-amber-950" title="Envois de clients à traiter">{{ $pastille }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>
    @if($u->estChef())
        <div class="border-t border-white/5 p-3">
            <a href="{{ route('parametres.index') }}" class="nav-link {{ request()->routeIs('parametres.*') ? 'nav-link-active' : '' }}"><x-icon name="settings" class="h-[18px] w-[18px] text-brand-200/50"/> Paramètres</a>
        </div>
    @endif
</aside>
<div x-show="nav" x-cloak @click="nav=false" class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"></div>

<div class="lg:pl-64 print:!pl-0">
    {{-- Barre supérieure --}}
    <header class="no-print sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-[#f3f6f7]/80 px-4 backdrop-blur-md sm:px-6 dark:border-slate-800 dark:bg-slate-950/80">
        <button class="btn-icon lg:hidden" @click="nav = true"><x-icon name="menu"/></button>
        <form action="{{ route('clients.index') }}" method="GET" class="relative hidden max-w-md flex-1 {{ $u->peutModule('clients') ? 'sm:block' : '' }}">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
            <input name="q" value="{{ request('q') }}" placeholder="Rechercher un client, un matricule fiscal…" class="input !rounded-full !bg-white/80 pl-9 dark:!bg-slate-900">
        </form>
        <div class="ml-auto flex items-center gap-1.5">
            <button class="btn-icon" onclick="toggleTheme()" title="Thème clair / sombre"><x-icon name="moon" class="h-[18px] w-[18px]"/></button>
            <div class="relative" x-data="{ o: false }">
                <button @click="o = !o" class="flex items-center gap-2.5 rounded-full py-1 pl-1 pr-3 hover:bg-white dark:hover:bg-slate-800">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ $u->initiales() }}</span>
                    <span class="hidden text-left leading-tight sm:block">
                        <span class="block text-sm font-medium text-slate-800 dark:text-slate-100">{{ $u->name }}</span>
                        <span class="block text-[11px] text-slate-500">{{ $u->roleLibelle() }}</span>
                    </span>
                </button>
                <div x-show="o" x-cloak @click.outside="o = false" x-transition class="card absolute right-0 mt-2 w-52 p-1.5">
                    <a href="{{ route('parametres.index', ['tab' => 'compte']) }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"><x-icon name="settings" class="h-4 w-4"/> Mon compte</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10"><x-icon name="logout" class="h-4 w-4"/> Se déconnecter</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-7 sm:px-6 lg:px-8">
        @if(session('ok'))
            <div x-data="{ s: true }" x-show="s" x-init="setTimeout(() => s = false, 5000)" x-transition class="no-print mb-5 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
                <x-icon name="check" class="h-4 w-4"/> {{ session('ok') }}
            </div>
        @endif
        @if(session('err') || $errors->any())
            <div class="no-print mb-5 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
                <div>{{ session('err') ?? 'Veuillez corriger les champs signalés.' }}
                    @if($errors->any() && !session('err'))<ul class="mt-1 list-disc pl-4 text-xs">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
                </div>
            </div>
        @endif
        {{ $slot }}
    </main>
</div>
@stack('scripts')
</body>
</html>
