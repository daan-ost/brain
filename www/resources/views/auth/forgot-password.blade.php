@extends('layouts.auth-standalone')

@section('title', __('auth.forgot_password_title'))

@section('content')
<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <a href="{{ url('/') }}" class="inline-block">
            <img src="/favicon.svg" alt="{{ config('app.name') }} Logo" class="h-16 w-auto opacity-90 hover:opacity-100 transition-opacity">
        </a>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        <!-- Header -->
        <div class="text-center pb-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">
                @if(session('status'))
                    {{ __('auth.check_your_email') }}
                @else
                    {{ __('auth.forgot_password_title') }}?
                @endif
            </h1>
            <p class="text-gray-600">
                @if(session('status'))
                    {{ __('auth.reset_link_sent') }}
                @else
                    {{ __('auth.forgot_password_subtitle') }}
                @endif
            </p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <!-- Form -->
        <div class="space-y-6">
            @if(session('status'))
                <div class="text-center space-y-6">
                    <div class="flex justify-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                    </div>

                    <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                        <div class="flex items-center">
                            <svg class="w-4 h-4 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 002 2v10a2 2 0 002 2z"></path>
                            </svg>
                            <span class="text-green-800 text-sm">
                                {{ __('auth.reset_link_sent_to') }} <strong>{{ old('email', request()->email) }}</strong>
                            </span>
                        </div>
                    </div>

                    <div class="text-sm text-gray-600">
                        <p>{{ __('auth.email_not_received') }}</p>
                        <a href="{{ route('password.request') }}" class="text-[#2A73E8] hover:underline font-medium">
                            {{ __('auth.try_again') }}
                        </a>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
                    @csrf

                    <!-- Email Address -->
                    <div class="space-y-2">
                        <label for="email" class="block text-sm font-medium text-gray-700">
                            {{ __('auth.email_address') }}
                        </label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            placeholder="{{ __('auth.email_placeholder') }}"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            class="w-full h-12 px-4 border border-gray-300 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                        />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                    >
                        {{ __('auth.send_reset_link') }}
                    </button>
                </form>
            @endif

            <!-- Back to Login -->
            <div class="text-center pt-4 border-t border-gray-200">
                <a
                    href="{{ route('login') }}"
                    class="inline-flex items-center space-x-2 text-sm text-gray-600 hover:text-[#2A73E8] transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span>{{ __('auth.back_to_login') }}</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Support Contact -->
    <div class="text-center mt-6 text-white/80 text-sm">
        <p>{{ __('auth.need_help') }}</p>
        <a href="mailto:{{ config('mail.from.address') }}" class="text-white hover:underline font-medium">
            {{ config('mail.from.address') }}
        </a>
    </div>

    <!-- Back to Homepage -->
    <div class="text-center mt-8">
        <a href="{{ url('/') }}" class="text-white/80 hover:text-white text-sm font-medium">
            ← {{ __('auth.back_to_homepage') }}
        </a>
    </div>
</div>
@endsection