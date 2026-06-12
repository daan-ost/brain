<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('pricing.page_description') }}">
    <meta name="keywords" content="pricing, pdf conversion, plans, credits, subscription">
    <title>{{ config('app.name') }} - {{ __('pricing.page_title') }}</title>

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <div class="min-h-screen bg-gray-50">
        <!-- Simple Header with Logo and Return Button -->
        <header class="bg-gradient-to-br from-[#9FD6D2] to-[#53B3AE]">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center py-4">
                    <!-- Logo -->
                    <a href="/" class="hover:opacity-80 transition-opacity">
                        <img src="/favicon.svg" alt="{{ config('app.name') }} Logo"
                            class="h-12 w-auto opacity-90 hover:opacity-100 transition-opacity" />
                    </a>

                    <!-- Return to Homepage Button -->
                    <a href="/" class="inline-flex items-center px-5 py-2.5 bg-white bg-opacity-20 hover:bg-opacity-30 text-white rounded-lg transition-all duration-200 font-medium">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        {{ __('pricing.back_to_homepage') }}
                    </a>
                </div>
            </div>
        </header>

        <!-- Pricing Content -->
        <div class="py-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-12">
                    <h1 class="text-4xl font-bold text-gray-900 mb-4">
                        {{ __('pricing.page_title') }}
                    </h1>
                    <p class="text-lg text-gray-600">
                        {{ __('pricing.page_subtitle') }}
                    </p>
                </div>

                <!-- Livewire Pricing Component -->
                <livewire:pricing-wizard />
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>