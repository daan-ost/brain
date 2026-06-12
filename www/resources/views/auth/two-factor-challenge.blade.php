@extends('layouts.auth-standalone')

@section('title', __('Two-Factor Authentication'))

@section('content')
<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <a href="{{ url('/') }}" class="inline-block">
            <img src="{{ config('app.logo', '/logo_transparent_pdfen.png') }}" alt="{{ config('app.name') }}" class="h-16 w-auto opacity-90 hover:opacity-100 transition-opacity">
        </a>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        <!-- Header -->
        <div class="text-center pb-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('Two-Factor Authentication') }}</h1>
            <p class="text-gray-600">{{ __('Please enter the 6-digit code from your authenticator app.') }}</p>
        </div>

        <!-- Form -->
        <div x-data="{ useRecovery: false }">
            <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-4">
                @csrf

                <div x-show="!useRecovery">
                    <!-- TOTP Code -->
                    <div class="space-y-2">
                        <label for="code" class="block text-sm font-medium text-gray-700">
                            {{ __('Authentication Code') }}
                        </label>
                        <input
                            id="code"
                            name="code"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="6"
                            autocomplete="one-time-code"
                            autofocus
                            placeholder="000000"
                            class="w-full h-12 px-4 border rounded-lg outline-none transition-colors text-center text-2xl tracking-widest font-mono {{ $errors->has('code') ? 'border-red-500 focus:border-red-500 focus:ring-red-500 bg-red-50' : 'border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8]' }}"
                        >
                        @error('code')
                            <p class="text-sm font-medium text-red-600 mt-2">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div x-show="useRecovery" x-cloak>
                    <!-- Recovery Code -->
                    <div class="space-y-2">
                        <label for="recovery_code" class="block text-sm font-medium text-gray-700">
                            {{ __('Recovery Code') }}
                        </label>
                        <input
                            id="recovery_code"
                            name="recovery_code"
                            type="text"
                            autocomplete="off"
                            placeholder="XXXX-XXXX"
                            class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors text-center text-lg tracking-wider font-mono uppercase"
                        >
                        @error('recovery_code')
                            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Remember Device -->
                <div class="flex items-center">
                    <input
                        id="remember"
                        name="remember"
                        type="checkbox"
                        value="1"
                        class="h-4 w-4 text-[#2A73E8] border-gray-300 rounded focus:ring-[#2A73E8]"
                    >
                    <label for="remember" class="ml-2 block text-sm text-gray-700">
                        {{ __('Remember this device for 30 days') }}
                    </label>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full h-12 bg-[#2A73E8] hover:bg-[#1E5FC7] text-white font-semibold rounded-lg transition-colors flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                >
                    {{ __('Verify') }}
                </button>
            </form>

            <!-- Toggle Recovery Code -->
            <div class="mt-6 text-center">
                <button
                    type="button"
                    x-show="!useRecovery"
                    @click="useRecovery = true"
                    class="text-sm text-[#2A73E8] hover:text-[#1E5FC7] hover:underline"
                >
                    {{ __('Use a recovery code') }}
                </button>
                <button
                    type="button"
                    x-show="useRecovery"
                    @click="useRecovery = false"
                    class="text-sm text-[#2A73E8] hover:text-[#1E5FC7] hover:underline"
                >
                    {{ __('Use an authentication code') }}
                </button>
            </div>
        </div>
    </div>

    <!-- Back to Login -->
    <div class="mt-6 text-center">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm text-gray-500 hover:text-gray-700 hover:underline focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 rounded">
                {{ __('Log out and try again') }}
            </button>
        </form>
    </div>
</div>
@endsection
