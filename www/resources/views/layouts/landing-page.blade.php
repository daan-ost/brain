{{--
    Landing Page Layout

    Used by: Pages via @extends('layouts.landing-page')
    Related: layouts/landing.blade.php (used by <x-landing-layout> component)

    IMPORTANT: Keep shared components (modals, scripts) in sync between both layouts!
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Title -->
    <title>@yield('title', __('seo.site_title')) | {{ config('app.name') }}</title>

    <!-- Meta Description -->
    <meta name="description" content="@yield('description', __('seo.default_description'))">

    <!-- Robots Meta Tag (Environment-aware) -->
    <meta name="robots" content="{{ app()->environment('production') ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' : 'noindex, nofollow' }}">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Hreflang Tags -->
    @php
        $currentLocale = app()->getLocale();
        $currentPath = request()->path();

        // Remove locale prefix if present
        $pathWithoutLocale = preg_replace('#^(en|nl)/#', '', $currentPath);

        // Get NL slug mapping
        $nlMapping = config('landing_pages.nl_slug_mapping', []);

        // Determine EN and NL slugs
        $enSlug = array_search($pathWithoutLocale, $nlMapping) ?: $pathWithoutLocale;
        $nlSlug = $nlMapping[$enSlug] ?? $enSlug;
    @endphp
    <link rel="alternate" hreflang="en" href="{{ url('/en/' . $enSlug) }}">
    <link rel="alternate" hreflang="nl" href="{{ url('/nl/' . $nlSlug) }}">
    <link rel="alternate" hreflang="x-default" href="{{ url('/en/' . $enSlug) }}">

    <!-- Open Graph Tags -->
    <meta property="og:title" content="@yield('title', __('seo.site_title'))">
    <meta property="og:description" content="@yield('description', __('seo.default_description'))">
    <meta property="og:image" content="@yield('og_image', asset('og-images/og-default.svg'))">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ app()->getLocale() }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">

    <!-- Twitter Card Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@{{ config('app.name') }}">
    <meta name="twitter:title" content="@yield('title', __('seo.site_title'))">
    <meta name="twitter:description" content="@yield('description', __('seo.default_description'))">
    <meta name="twitter:image" content="@yield('og_image', asset('og-images/og-default.svg'))">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=2" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}" />
    <link rel="manifest" href="/site.webmanifest" />

    <!-- BreadcrumbList Schema -->
    @php
        $breadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => __('ui.nav_home', ['default' => 'Home']),
                    'item' => url('/'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => trim($__env->yieldContent('title', 'Tools')),
                    'item' => url()->current(),
                ],
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>

    <!-- Additional Head Content (for page-specific schemas) -->
    @stack('head')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    <link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&display=swap" rel="stylesheet">
    <link href="https://fonts.cdnfonts.com/css/mark-pro" rel="stylesheet">

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

    <!-- Password Reminder Banner (for verified email without password) -->
    <x-password-reminder-banner />

    <!-- Page Content -->
    @yield('content')

    <!-- QR Code Library (for share modal) -->
    <script src="{{ asset('js/qrcode.min.js') }}"></script>

    <!-- Vanilla Share Modal (for share functionality) -->
    @include('components.vanilla-share-modal')

    @livewireScripts

    @stack('scripts')

    {{-- Central Tracking Scripts --}}
    @include('partials.tracking')
</body>
</html>
