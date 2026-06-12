<section id="profile-information" class="scroll-mt-32">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('profile.profile_information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('profile.profile_information_description') }}
        </p>
    </header>

    {{-- Pending Email Change Alert --}}
    @if ($user->hasPendingEmailChange())
    <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-md">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-yellow-600 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
            <div class="flex-1">
                <h3 class="text-sm font-medium text-yellow-800">
                    {{ __('profile.pending_email_change') }}
                </h3>
                <p class="mt-1 text-sm text-yellow-700">
                    {{ __('profile.pending_email_change_description', ['email' => $user->pending_email]) }}
                </p>
                <div class="mt-3 flex gap-3">
                    <form method="POST" action="{{ route('email.change.resend') }}">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-yellow-800 hover:text-yellow-900 underline">
                            {{ __('profile.resend_verification') }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('email.change.cancel') }}">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-red-800 hover:text-red-900 underline">
                            {{ __('profile.cancel_email_change') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('profile.name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('profile.email')" />

            @if ($user->hasPendingEmailChange())
                {{-- Show current email as read-only when pending change --}}
                <div class="mt-1 block w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md text-gray-500">
                    {{ $user->email }}
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ __('profile.email_locked_pending_change') }}
                </p>
            @else
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
                <x-input-error class="mt-2" :messages="$errors->get('email')" />
                <p class="mt-1 text-sm text-gray-500">
                    {{ __('profile.email_change_notice') }}
                </p>
            @endif

            @if (!$user->isEmailConfirmed())
                <div>
                    <p class="text-sm mt-2 text-red-600 flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        {{ __('profile.email_not_verified') }}
                    </p>

                    @if ($user->canResendConfirmation())
                        <button form="send-verification" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 mt-1">
                            {{ __('profile.click_to_resend_verification') }}
                        </button>
                    @else
                        <p class="text-sm mt-1 text-gray-500">
                            {{ __('profile.wait_before_resend') }}
                        </p>
                    @endif

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            {{ __('profile.verification_link_sent') }}
                        </p>
                    @endif
                </div>
            @else
                <p class="text-sm mt-2 text-green-600 flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    {{ __('profile.email_verified') }}
                </p>
            @endif
        </div>

        <div>
            <x-input-label for="preferred_language" :value="__('profile.language')" />
            <select id="preferred_language" name="preferred_language" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="en" {{ old('preferred_language', $user->preferred_language) === 'en' ? 'selected' : '' }}>🇬🇧 English</option>
                <option value="nl" {{ old('preferred_language', $user->preferred_language) === 'nl' ? 'selected' : '' }}>🇳🇱 Nederlands</option>
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('preferred_language')" />
            <p class="mt-1 text-sm text-gray-500">{{ __('profile.language_description') }}</p>
        </div>

        <div>
            <x-input-label for="billing_country_code" :value="__('profile.country')" />
            <select id="billing_country_code" name="billing_country_code" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="">{{ __('profile.select_country') }}</option>
                <option value="NL" {{ old('billing_country_code', $user->billing_country_code) === 'NL' ? 'selected' : '' }}>🇳🇱 Netherlands</option>
                <option value="BE" {{ old('billing_country_code', $user->billing_country_code) === 'BE' ? 'selected' : '' }}>🇧🇪 Belgium</option>
                <option value="DE" {{ old('billing_country_code', $user->billing_country_code) === 'DE' ? 'selected' : '' }}>🇩🇪 Germany</option>
                <option value="FR" {{ old('billing_country_code', $user->billing_country_code) === 'FR' ? 'selected' : '' }}>🇫🇷 France</option>
                <option value="GB" {{ old('billing_country_code', $user->billing_country_code) === 'GB' ? 'selected' : '' }}>🇬🇧 United Kingdom</option>
                <option value="US" {{ old('billing_country_code', $user->billing_country_code) === 'US' ? 'selected' : '' }}>🇺🇸 United States</option>
                <option value="CA" {{ old('billing_country_code', $user->billing_country_code) === 'CA' ? 'selected' : '' }}>🇨🇦 Canada</option>
                <option value="AU" {{ old('billing_country_code', $user->billing_country_code) === 'AU' ? 'selected' : '' }}>🇦🇺 Australia</option>
                <option value="AT" {{ old('billing_country_code', $user->billing_country_code) === 'AT' ? 'selected' : '' }}>🇦🇹 Austria</option>
                <option value="CH" {{ old('billing_country_code', $user->billing_country_code) === 'CH' ? 'selected' : '' }}>🇨🇭 Switzerland</option>
                <option value="DK" {{ old('billing_country_code', $user->billing_country_code) === 'DK' ? 'selected' : '' }}>🇩🇰 Denmark</option>
                <option value="ES" {{ old('billing_country_code', $user->billing_country_code) === 'ES' ? 'selected' : '' }}>🇪🇸 Spain</option>
                <option value="FI" {{ old('billing_country_code', $user->billing_country_code) === 'FI' ? 'selected' : '' }}>🇫🇮 Finland</option>
                <option value="IT" {{ old('billing_country_code', $user->billing_country_code) === 'IT' ? 'selected' : '' }}>🇮🇹 Italy</option>
                <option value="NO" {{ old('billing_country_code', $user->billing_country_code) === 'NO' ? 'selected' : '' }}>🇳🇴 Norway</option>
                <option value="PL" {{ old('billing_country_code', $user->billing_country_code) === 'PL' ? 'selected' : '' }}>🇵🇱 Poland</option>
                <option value="PT" {{ old('billing_country_code', $user->billing_country_code) === 'PT' ? 'selected' : '' }}>🇵🇹 Portugal</option>
                <option value="SE" {{ old('billing_country_code', $user->billing_country_code) === 'SE' ? 'selected' : '' }}>🇸🇪 Sweden</option>
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('billing_country_code')" />
            <p class="mt-1 text-sm text-gray-500">{{ __('profile.country_description') }}</p>
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('profile.save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('profile.saved') }}</p>
            @endif

            @if (session('status') === 'email-change-pending')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 5000)"
                    class="text-sm text-yellow-600"
                >{{ __('profile.email_change_pending_message') }}</p>
            @endif

            @if (session('status') === 'email-changed')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 5000)"
                    class="text-sm text-green-600"
                >{{ __('profile.email_changed_message') }}</p>
            @endif

            @if (session('status') === 'email-change-cancelled')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 5000)"
                    class="text-sm text-gray-600"
                >{{ __('profile.email_change_cancelled_message') }}</p>
            @endif

            @if (session('status') === 'verification-resent')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 5000)"
                    class="text-sm text-green-600"
                >{{ __('profile.verification_resent_message') }}</p>
            @endif
        </div>
    </form>
</section>
