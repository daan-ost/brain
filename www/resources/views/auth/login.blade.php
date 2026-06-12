@extends('layouts.auth-standalone')

@section('title', __('auth.login'))

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
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('auth.welcome_back') }}</h1>
            <p class="text-gray-600">{{ __('auth.login_subtitle') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <!-- Form -->
        <div class="space-y-6">
            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                @if(request('redirect'))
                    <input type="hidden" name="redirect" value="{{ request('redirect') }}">
                @endif
                @if(request('invitation'))
                    <input type="hidden" name="invitation_token" value="{{ request('invitation') }}">
                @endif

                <!-- Email Address -->
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
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <!-- Password -->
                <div class="space-y-2">
                    <label for="password" class="block text-sm font-medium text-gray-700">
                        {{ __('auth.password_label') }}
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="{{ __('auth.password_placeholder') }}"
                        required
                        autocomplete="current-password"
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <!-- Remember Me and Forgot Password -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <input
                            id="remember_me"
                            type="checkbox"
                            name="remember"
                            checked
                            class="w-4 h-4 text-[#2A73E8] border-gray-300 rounded focus:ring-[#2A73E8]"
                        />
                        <label for="remember_me" class="text-sm text-gray-600">
                            {{ __('auth.remember_me') }}
                        </label>
                    </div>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-sm text-[#2A73E8] hover:text-[#1557b0] font-medium">
                            {{ __('auth.forgot_password') }}
                        </a>
                    @endif
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                >
                    {{ __('auth.login') }}
                </button>
            </form>

            @include('auth.partials.alternate-login', ['context' => 'login'])

            <!-- Register Link -->
            <div class="text-center">
                <span class="text-sm text-gray-600">
                    {{ __('auth.no_account_yet') }}
                    <a href="{{ route('register') }}" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">{{ __('auth.register') }}</a>
                </span>
            </div>
        </div>
    </div>

    <!-- Back to Homepage -->
    <div class="text-center mt-8">
        <a href="{{ url('/') }}" class="text-white/80 hover:text-white text-sm font-medium">
            ← {{ __('auth.back_to_homepage') }}
        </a>
    </div>
</div>
@endsection
