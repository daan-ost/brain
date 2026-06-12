@extends('layouts.auth-standalone')

@section('title', __('auth.set_password'))

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
            @php
                // Check if the name is just derived from email (e.g., "dddd" from "dddd@example.com")
                $emailPrefix = explode('@', $user->email)[0];
                $hasRealName = $user->name && $user->name !== $emailPrefix && $user->name !== 'Guest User';
            @endphp
            <h1 class="text-2xl font-bold text-gray-900 mb-2">
                @if($hasRealName)
                    {{ __('auth.password_setup_intro', ['name' => $user->name]) }}
                @else
                    {{ __('auth.password_setup_intro_no_name') }}
                @endif
            </h1>
        </div>

        <!-- Form -->
        <div class="space-y-6">
            <form method="POST" action="{{ route('password.setup.store', ['user' => $user->id, 'hash' => sha1($user->email)]) }}?expires={{ request('expires') }}&signature={{ request('signature') }}" class="space-y-4">
                @csrf

                <!-- Email Address (readonly, just for display) -->
                <div class="space-y-2">
                    <label for="email" class="block text-sm font-medium text-gray-700">
                        {{ __('Email') }}
                    </label>
                    <input
                        id="email"
                        type="email"
                        value="{{ $user->email }}"
                        readonly
                        class="w-full h-12 px-4 border border-gray-200 bg-gray-50 rounded-lg outline-none"
                    />
                </div>

                <!-- Password -->
                <div class="space-y-2">
                    <label for="password" class="block text-sm font-medium text-gray-700">
                        {{ __('Password') }}
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="{{ __('auth.password_placeholder') }}"
                        required
                        autofocus
                        autocomplete="new-password"
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                    <p class="text-xs text-gray-500">{{ __('auth.password_requirements') }}</p>
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <!-- Confirm Password -->
                <div class="space-y-2">
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
                        {{ __('Confirm Password') }}
                    </label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        placeholder="{{ __('auth.password_confirmation_placeholder') }}"
                        required
                        autocomplete="new-password"
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                >
                    {{ __('auth.set_password') }}
                </button>
            </form>

            <!-- Login Link -->
            <div class="text-center">
                <a href="{{ route('login') }}" class="text-sm text-[#2A73E8] hover:text-[#1557b0] font-medium">
                    {{ __('auth.already_have_password_login') }}
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
@endsection
