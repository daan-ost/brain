<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Title -->
    <title>{{ $pageTitle ?? __('profile.title') }} | {{ config('app.name') }}</title>

    <!-- Robots Meta Tag (No indexing for profile pages) -->
    <meta name="robots" content="noindex, nofollow">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        /* Alpine.js cloak - hide elements until Alpine is loaded */
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100">
    <!-- Announcement Modal -->
    @if(isset($activeAnnouncement) && $activeAnnouncement)
        <x-announcement-modal :announcement="$activeAnnouncement" />
    @endif

    @include('components.header')

    <!-- Page Content -->
    <main class="min-h-screen bg-gray-100">
        <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar -->
                <div class="lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-lg shadow p-6">
                        <nav class="space-y-2">
                            <!-- Profile Section -->
                            <div class="mb-6">
                                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">{{ __('profile.sidebar_profile') }}</h3>
                                <div class="space-y-1">
                                    <a href="{{ route('profile.account') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.account') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_my_account') }}
                                    </a>
                                    <a href="{{ route('profile.password') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.password') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_password') }}
                                    </a>
                                    <a href="{{ route('profile.email-preferences') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.email-preferences') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_email_preferences') }}
                                    </a>
                                </div>
                            </div>

                            <!-- Organization Section -->
                            @auth
                                <div class="mb-6">
                                    <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">
                                        {{ __('profile.sidebar_organization') }}
                                        @if(auth()->user()->organizations()->wherePivot('role', \App\Enums\OrganizationRole::Owner)->exists())
                                            (admin)
                                        @endif
                                    </h3>
                                    <div class="space-y-1">
                                        <a href="{{ route('profile.organization') }}"
                                           class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.organization') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                            {{ __('profile.sidebar_my_organization') }}
                                        </a>
                                        @if(auth()->user()->organizations()->wherePivot('role', \App\Enums\OrganizationRole::Owner)->exists())
                                            <a href="{{ route('profile.organization.users') }}"
                                               class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.organization.users') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                                {{ __('profile.sidebar_users') }}
                                            </a>
                                            <a href="{{ route('profile.organization.domains') }}"
                                               class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.organization.domains') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                                {{ __('profile.sidebar_domains') }}
                                            </a>
                                            <a href="{{ route('profile.organization.transactions') }}"
                                               class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.organization.transactions') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                                {{ __('profile.sidebar_transactions') }}
                                            </a>
                                            @if(config('features.send_email_functionality'))
                                                <a href="{{ route('profile.organization.sender-email') }}"
                                                   class="block px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.organization.sender-email') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                                    {{ __('profile.sidebar_sender_email') }}
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endauth

                            <!-- Billing Section -->
                            <div class="mb-6">
                                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">{{ __('profile.sidebar_billing') }}</h3>
                                <div class="space-y-1">
                                    <a href="{{ route('profile.credits') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.credits') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_credits') }}
                                    </a>
                                    <a href="{{ route('profile.plans') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.plans') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_plans_packages') }}
                                    </a>
                                    <a href="{{ route('profile.invoice') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.invoice') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_invoice') }}
                                    </a>
                                </div>
                            </div>

                            <!-- Support Section -->
                            <div class="mb-6">
                                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">{{ __('profile.sidebar_support') }}</h3>
                                <div class="space-y-1">
                                    <a href="{{ route('profile.messages') }}"
                                       class="flex items-center justify-between px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.messages*') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        <span>{{ __('profile.sidebar_messages') }}</span>
                                        @php
                                            $unreadMessages = auth()->user()->messageThreads()->where('unread_count_user', '>', 0)->count();
                                        @endphp
                                        @if($unreadMessages > 0)
                                            <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-none text-white bg-red-500 rounded-full">{{ $unreadMessages }}</span>
                                        @endif
                                    </a>
                                </div>
                            </div>

                            <!-- Developer Section -->
                            <div class="mb-6">
                                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">{{ __('profile.sidebar_developer') }}</h3>
                                <div class="space-y-1">
                                    <a href="{{ route('profile.api-tokens') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.api-tokens') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_api_tokens') }}
                                    </a>
                                    <a href="{{ route('profile.webhooks') }}"
                                       class="block px-3 py-3 sm:py-2 text-sm font-medium rounded-md transition-colors duration-200 {{ request()->routeIs('profile.webhooks') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                        {{ __('profile.sidebar_webhooks') }}
                                    </a>
                                </div>
                            </div>
                        </nav>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="flex-1">
                    <div class="bg-white rounded-lg shadow">
                        @yield('content')
                    </div>
                </div>
            </div>
        </div>
    </main>

    @livewireScripts

    @stack('scripts')
</body>
</html>
