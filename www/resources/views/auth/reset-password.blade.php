@extends('layouts.auth-standalone')

@section('title', __('auth.reset_password'))

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
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('auth.set_new_password') }}</h1>
            <p class="text-gray-600">{{ __('auth.reset_password_subtitle') }}</p>
        </div>

        <!-- Form -->
        <div class="space-y-6">
            <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
                @csrf

                <!-- Password Reset Token -->
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <!-- Email Address -->
                <div class="space-y-2">
                    <label for="email" class="block text-sm font-medium text-gray-700">
                        {{ __('auth.email_address') }}
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email', $request->email) }}"
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
                        {{ __('auth.new_password') }}
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="{{ __('auth.new_password_placeholder') }}"
                        required
                        autocomplete="new-password"
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <!-- Confirm Password -->
                <div class="space-y-2">
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
                        {{ __('auth.confirm_new_password') }}
                    </label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        placeholder="{{ __('auth.confirm_new_password_placeholder') }}"
                        required
                        autocomplete="new-password"
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                    <p id="password_mismatch_error" class="text-red-500 text-sm mt-1 hidden">
                        {{ __('auth.passwords_not_match') }}
                    </p>
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                >
                    {{ __('auth.reset_password_button') }}
                </button>
            </form>

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

    <!-- Back to Homepage -->
    <div class="text-center mt-8">
        <a href="{{ url('/') }}" class="text-white/80 hover:text-white text-sm font-medium">
            ← {{ __('auth.back_to_homepage') }}
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const password = document.getElementById('password');
    const passwordConfirmation = document.getElementById('password_confirmation');
    const errorMessage = document.getElementById('password_mismatch_error');
    const submitButton = document.querySelector('button[type="submit"]');

    function validatePasswordMatch() {
        if (passwordConfirmation.value === '') {
            errorMessage.classList.add('hidden');
            passwordConfirmation.classList.remove('border-red-500');
            submitButton.disabled = false;
            return;
        }

        if (password.value !== passwordConfirmation.value) {
            errorMessage.classList.remove('hidden');
            passwordConfirmation.classList.add('border-red-500');
            submitButton.disabled = true;
        } else {
            errorMessage.classList.add('hidden');
            passwordConfirmation.classList.remove('border-red-500');
            submitButton.disabled = false;
        }
    }

    password.addEventListener('input', validatePasswordMatch);
    passwordConfirmation.addEventListener('input', validatePasswordMatch);
});
</script>
@endsection
