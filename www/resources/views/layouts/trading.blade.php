<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'NoBrainersBot' }}</title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=3" />
    <link rel="icon" type="image/png" href="/favicon-96x96.png?v=3" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=3" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-800" x-data="{ open: false }">

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 w-64 flex flex-col bg-gray-900 text-gray-300
                  -translate-x-full lg:translate-x-0 transition-transform"
           :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        <div class="flex h-16 items-center gap-2 px-5 border-b border-white/10">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-white font-bold">N</span>
            <span class="text-white font-semibold tracking-tight">NoBrainersBot</span>
        </div>
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1 text-sm">
            @php($item = fn ($routeName, $active) => [$routeName, $active])
            <a href="{{ route('dashboard') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-white/5 hover:text-white transition">
                <span class="w-2 h-2 rounded-full bg-gray-600"></span> Dashboard
            </a>
            <a href="{{ route('trades.index') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 transition
                      {{ request()->routeIs('trades.index') ? 'bg-emerald-500/10 text-white border-l-2 border-emerald-400' : 'hover:bg-white/5 hover:text-white' }}">
                <span class="w-2 h-2 rounded-full {{ request()->routeIs('trades.index') ? 'bg-emerald-400' : 'bg-gray-600' }}"></span> Trades
            </a>
            <a href="{{ route('coins.ranking') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 transition
                      {{ request()->routeIs('coins.*') ? 'bg-emerald-500/10 text-white border-l-2 border-emerald-400' : 'hover:bg-white/5 hover:text-white' }}">
                <span class="w-2 h-2 rounded-full {{ request()->routeIs('coins.*') ? 'bg-emerald-400' : 'bg-gray-600' }}"></span> Munten
            </a>
            <a href="{{ route('trades.explorer') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 transition
                      {{ request()->routeIs('trades.explorer') ? 'bg-emerald-500/10 text-white border-l-2 border-emerald-400' : 'hover:bg-white/5 hover:text-white' }}">
                <span class="w-2 h-2 rounded-full {{ request()->routeIs('trades.explorer') ? 'bg-emerald-400' : 'bg-gray-600' }}"></span> Coin explorer
            </a>
            <a href="{{ route('trades.labeler') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 transition
                      {{ request()->routeIs('trades.labeler') ? 'bg-emerald-500/10 text-white border-l-2 border-emerald-400' : 'hover:bg-white/5 hover:text-white' }}">
                <span class="w-2 h-2 rounded-full {{ request()->routeIs('trades.labeler') ? 'bg-emerald-400' : 'bg-gray-600' }}"></span> Promising labeler
            </a>
            <a href="{{ route('engine.index') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 transition
                      {{ request()->routeIs('engine.*') ? 'bg-emerald-500/10 text-white border-l-2 border-emerald-400' : 'hover:bg-white/5 hover:text-white' }}">
                <span class="w-2 h-2 rounded-full {{ request()->routeIs('engine.*') ? 'bg-emerald-400' : 'bg-gray-600' }}"></span> Engine
            </a>
            <a href="{{ route('routines.index') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 transition
                      {{ request()->routeIs('routines.*') ? 'bg-emerald-500/10 text-white border-l-2 border-emerald-400' : 'hover:bg-white/5 hover:text-white' }}">
                <span class="w-2 h-2 rounded-full {{ request()->routeIs('routines.*') ? 'bg-emerald-400' : 'bg-gray-600' }}"></span> Routines
            </a>
        </nav>
        <div class="border-t border-white/10 px-3 py-3">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full text-left rounded-lg px-3 py-2 text-sm hover:bg-white/5 hover:text-white transition">
                    Uitloggen ({{ auth()->user()?->email }})
                </button>
            </form>
        </div>
    </aside>

    {{-- Main --}}
    <div class="lg:pl-64">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-gray-200 bg-white px-4 sm:px-6">
            <button @click="open = !open" class="lg:hidden text-gray-500" aria-label="Menu">☰</button>
            <div class="text-sm text-gray-400">{{ $header ?? '' }}</div>
        </header>
        <main class="p-4 sm:p-6 lg:p-8">
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
    @livewireScripts
    @stack('scripts')
</body>
</html>
