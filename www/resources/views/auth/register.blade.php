@extends('layouts.auth-standalone')

@section('title', __('auth.register'))

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
                <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('auth.create_account') }}</h1>
                <p class="text-gray-600">{{ __('auth.register_subtitle') }}</p>
            </div>

            <!-- Form -->
            <div class="space-y-6">
                @if(isset($invitation))
                    <!-- Invitation Info Banner -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex items-start">
                            <svg class="w-5 h-5 text-blue-400 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-blue-800">
                                    {{ __('auth.joining_organization') }} <strong>{{ $invitation->organization->name }}</strong>
                                </p>
                                <p class="text-xs text-blue-600 mt-1">
                                    {{ __('auth.invited_by', ['name' => $invitation->invitedBy->name, 'role' => \App\Enums\OrganizationRole::from($invitation->role)->label()]) }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('register') }}" class="space-y-4">
                    @csrf
                    @if(request('redirect'))
                        <input type="hidden" name="redirect" value="{{ request('redirect') }}">
                    @endif
                    @if(isset($invitation) && isset($invitationToken))
                        <input type="hidden" name="invitation_token" value="{{ $invitationToken }}">
                    @endif

                    <!-- First Name and Last Name -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label for="first_name" class="block text-sm font-medium text-gray-700">
                                {{ __('auth.first_name') }}
                            </label>
                            <input
                                id="first_name"
                                name="first_name"
                                type="text"
                                placeholder="{{ __('auth.first_name_placeholder') }}"
                                value="{{ old('first_name') }}"
                                required
                                autofocus
                                autocomplete="given-name"
                                class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg"
                            />
                            <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
                        </div>
                        <div class="space-y-2">
                            <label for="last_name" class="block text-sm font-medium text-gray-700">
                                {{ __('auth.last_name') }}
                            </label>
                            <input
                                id="last_name"
                                name="last_name"
                                type="text"
                                placeholder="{{ __('auth.last_name_placeholder') }}"
                                value="{{ old('last_name') }}"
                                required
                                autocomplete="family-name"
                                class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg"
                            />
                            <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
                        </div>
                    </div>

                    <!-- Hidden name field for Laravel compatibility -->
                    <input type="hidden" name="name" id="name" value="{{ old('first_name') }} {{ old('last_name') }}">

                    <!-- Email Address -->
                    <div class="space-y-2">
                        <label for="email" class="block text-sm font-medium text-gray-700">
                            {{ __('auth.email_address') }}
                        </label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            placeholder="{{ __('auth.email_placeholder_register') }}"
                            value="{{ isset($invitation) ? $invitation->email : old('email') }}"
                            {{ isset($invitation) ? 'readonly' : '' }}
                            required
                            autocomplete="username"
                            class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg {{ isset($invitation) ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                        />
                        @if(isset($invitation))
                            <p class="text-xs text-gray-500 mt-1">
                                {{ __('auth.email_locked_invitation') }}
                            </p>
                        @endif
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
                            placeholder="{{ __('auth.password_choose_strong') }}"
                            required
                            autocomplete="new-password"
                            class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg"
                        />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <!-- Confirm Password -->
                    <div class="space-y-2">
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
                            {{ __('auth.confirm_password') }}
                        </label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            placeholder="{{ __('auth.confirm_password_placeholder') }}"
                            required
                            autocomplete="new-password"
                            class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg"
                        />
                        <p id="password_mismatch_error" class="text-red-500 text-sm mt-1 hidden">
                            {{ __('auth.passwords_not_match') }}
                        </p>
                        <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                    </div>

                    <!-- Terms and Privacy -->
                    <div class="flex items-start space-x-2">
                        <input
                            id="terms"
                            type="checkbox"
                            name="terms"
                            required
                            class="w-4 h-4 text-[#2A73E8] border-gray-300 rounded focus:ring-[#2A73E8] mt-1"
                        />
                        <label for="terms" class="text-sm text-gray-600 leading-relaxed">
                            {{ __('auth.terms_agreement') }}
                            <a href="{{ route('terms') }}" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">{{ __('auth.terms_of_service') }}</a>
                            {{ __('auth.terms_and') }}
                            <a href="{{ route('privacy') }}" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">{{ __('auth.privacy_policy') }}</a>
                        </label>
                    </div>
                    <x-input-error :messages="$errors->get('terms')" class="mt-2" />

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                    >
                        {{ __('auth.create_account_button') }}
                    </button>
                </form>

                @include('auth.partials.alternate-login', ['context' => 'register'])

                <!-- Login Link -->
                <div class="text-center">
                    <span class="text-sm text-gray-600">
                        {{ __('auth.already_have_account') }}
                        <a href="{{ route('login') }}" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">{{ __('auth.login') }}</a>
                    </span>
                </div>
            </div>
        </div>

        <!-- Back to Homepage -->
        <div class="text-center mt-8">
            <a href="/" class="text-white/80 hover:text-white text-sm font-medium">
                ← {{ __('auth.back_to_homepage') }}
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-combine first and last name for the hidden name field
            const firstName = document.getElementById('first_name');
            const lastName = document.getElementById('last_name');
            const nameField = document.getElementById('name');

            function updateName() {
                nameField.value = (firstName.value + ' ' + lastName.value).trim();
            }

            firstName.addEventListener('input', updateName);
            lastName.addEventListener('input', updateName);

            // Password match validation
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
</div>
@endsection