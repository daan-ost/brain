<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Contact - {{ config('app.name', 'Basewebsite') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
<div class="min-h-screen bg-gradient-to-b from-gray-50 to-white">
    @include('components.header')

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pt-20 pb-16">
        <h1 class="text-3xl font-bold text-gray-900 mb-8">Contact</h1>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <p class="text-gray-600 mb-6">
                {{ __('Heeft u vragen of opmerkingen? Neem gerust contact met ons op.') }}
            </p>

            <div class="space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('E-mail') }}</h3>
                    <a href="mailto:{{ config('mail.from.address') }}" class="text-blue-600 hover:text-blue-800">
                        {{ config('mail.from.address') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    @include('components.footer')
</div>
</body>
</html>
