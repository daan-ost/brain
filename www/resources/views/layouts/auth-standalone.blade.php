<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} - @yield('title')</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}" />
    <link rel="manifest" href="/site.webmanifest" />

    <!-- Use local Vite assets instead of CDN -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
</head>
<body class="font-sans text-gray-900 antialiased">
    <div class="min-h-screen bg-gradient-to-br from-[#9fd6d2] to-[#53b3ae] flex items-center justify-center p-4">
        @yield('content')
    </div>

    {{-- Central Tracking Scripts --}}
    @include('partials.tracking')
</body>
</html>
