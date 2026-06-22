<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Title -->
    <title>{{ $pageTitle ?? config('app.name') }} | {{ config('app.name') }}</title>

    <!-- Meta Description -->
    <meta name="description" content="{{ $pageDescription ?? __('seo.default_description') }}">

    <!-- Robots Meta Tag (Environment-aware) -->
    <meta name="robots" content="{{ app()->environment('production') ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' : 'noindex, nofollow' }}">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Open Graph Tags -->
    <meta property="og:title" content="{{ $pageTitle ?? config('app.name') }}">
    <meta property="og:description" content="{{ $pageDescription ?? __('seo.default_description') }}">
    <meta property="og:image" content="{{ asset('og-images/og-default.svg') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ app()->getLocale() }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">

    <!-- Twitter Card Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@{{ config('app.name') }}">
    <meta name="twitter:title" content="{{ $pageTitle ?? config('app.name') }}">
    <meta name="twitter:description" content="{{ $pageDescription ?? __('seo.default_description') }}">
    <meta name="twitter:image" content="{{ asset('og-images/og-default.svg') }}">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}" />
    <link rel="manifest" href="/site.webmanifest" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <!-- Klaro Consent Manager -->
    <x-klaro-scripts />
</head>

<body class="font-sans text-gray-900 antialiased">
    <!-- Announcement Modal -->
    @if(isset($activeAnnouncement) && $activeAnnouncement)
        <x-announcement-modal :announcement="$activeAnnouncement" />
    @endif

    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
        <div>
            <a href="/">
                <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
            </a>
        </div>

        <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
            {{ $slot }}
        </div>
    </div>

    @livewireScripts
    @stack('scripts')

    {{-- Central Tracking Scripts --}}
    @include('partials.tracking')
</body>

</html>