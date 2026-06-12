@extends('layouts.auth-standalone')

@section('title', __('auth.login_code_request_title'))

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
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('auth.login_code_request_title') }}</h1>
            <p id="login-code-request-subtitle" class="text-gray-600">{{ __('auth.login_code_request_subtitle') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form method="POST" action="{{ route('login.code.send') }}" class="space-y-4">
            @csrf

            <div class="space-y-2">
                <label for="email" class="block text-sm font-medium text-gray-700">
                    {{ __('auth.email_address') }}
                </label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    placeholder="{{ __('auth.email_placeholder') }}"
                    required
                    autofocus
                    autocomplete="username"
                    aria-describedby="login-code-request-subtitle{{ $errors->has('email') ? ' email-error' : '' }}"
                    @if ($errors->has('email')) aria-invalid="true" @endif
                    class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-2 focus:ring-[#2A73E8] focus:ring-offset-1 rounded-lg outline-none transition-colors"
                >
                @error('email')
                    <p id="email-error" role="alert" class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full h-12 bg-[#2A73E8] hover:bg-[#1f5fc4] text-white font-semibold rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-[#2A73E8] focus:ring-offset-2"
            >
                {{ __('auth.login_code_send_button') }}
            </button>
        </form>

        <!-- Back to login -->
        <div class="mt-6 text-center text-sm text-gray-600">
            <a href="{{ route('login') }}" class="text-[#2A73E8] underline hover:text-[#1f5fc4] focus:outline-none focus:ring-2 focus:ring-[#2A73E8] focus:ring-offset-2 rounded">
                {{ __('auth.back_to_login') }}
            </a>
        </div>
    </div>
</div>
@endsection
