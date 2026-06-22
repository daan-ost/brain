<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Title -->
    <title>{{ __('seo.home_title') }} | {{ config('app.name') }}</title>

    <!-- Meta Description -->
    <meta name="description" content="{{ __('seo.home_description') }}">

    <!-- Robots Meta Tag (Environment-aware) -->
    <meta name="robots" content="{{ app()->environment('production') ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' : 'noindex, nofollow' }}">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Hreflang Tags -->
    <link rel="alternate" hreflang="en" href="{{ url('/en') }}">
    <link rel="alternate" hreflang="nl" href="{{ url('/nl') }}">
    <link rel="alternate" hreflang="x-default" href="{{ url('/en') }}">

    <!-- Open Graph Tags -->
    <meta property="og:title" content="{{ __('seo.home_title') }}">
    <meta property="og:description" content="{{ __('seo.home_description') }}">
    <meta property="og:image" content="{{ asset('og-images/og-home-' . app()->getLocale() . '.svg') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ app()->getLocale() }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">

    <!-- Twitter Card Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@{{ config('app.name') }}">
    <meta name="twitter:title" content="{{ __('seo.home_title') }}">
    <meta name="twitter:description" content="{{ __('seo.home_description') }}">
    <meta name="twitter:image" content="{{ asset('og-images/og-home-' . app()->getLocale() . '.svg') }}">

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
    <style>
        .font-display {
            font-family: 'Mark Pro', 'Inter', ui-sans-serif, system-ui, sans-serif;
        }
    </style>

    <!-- Stack for additional head content -->
    @stack('head')

    <!-- Klaro Consent Manager -->
    <x-klaro-scripts />
</head>
<body class="font-sans text-gray-900 antialiased">
    @yield('content')

    <!-- Announcement Modal -->
    @if(isset($activeAnnouncement) && $activeAnnouncement)
        <x-announcement-modal :announcement="$activeAnnouncement" />
    @endif

    {{-- Central Tracking Scripts --}}
    @include('partials.tracking')
</body>
</html>
