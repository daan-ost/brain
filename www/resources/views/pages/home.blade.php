<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'NoBrainersBot') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-gray-950">
    <div class="min-h-screen flex items-center justify-center">
        <a href="{{ route('login') }}" class="text-white text-2xl font-bold tracking-wide hover:opacity-70 transition-opacity">
            {{ config('app.name') }}
        </a>
    </div>
</body>
</html>
