<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('profile.two_factor_title') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('profile.two_factor_description') }}
        </p>
    </header>

    <div class="mt-6">
        @if ($enabled)
            {{-- 2FA is enabled --}}
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span class="text-green-600 font-medium">{{ __('profile.two_factor_enabled') }}</span>
            </div>

            <div class="flex flex-wrap gap-3">
                <x-secondary-button wire:click="confirmShowRecoveryCodes" type="button">
                    {{ __('profile.two_factor_view_recovery_codes') }}
                </x-secondary-button>

                <x-secondary-button wire:click="confirmRegenerateRecoveryCodes" type="button">
                    {{ __('profile.two_factor_regenerate_recovery_codes') }}
                </x-secondary-button>

                <x-danger-button wire:click="confirmDisableTwoFactor" type="button">
                    {{ __('profile.two_factor_disable') }}
                </x-danger-button>
            </div>

        @elseif ($pending)
            {{-- Setup in progress - show QR code --}}
            @if ($showingQrCode)
                <div class="max-w-xl">
                    <p class="text-sm text-gray-600 mb-4">
                        {{ __('profile.two_factor_setup_instructions') }}
                    </p>

                    <div class="p-4 bg-white border rounded-lg inline-block mb-4">
                        {!! $qrCodeSvg !!}
                    </div>

                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-1">{{ __('profile.two_factor_manual_code') }}</p>
                        <code class="text-sm bg-gray-100 px-2 py-1 rounded select-all">{{ $secretKey ?? '' }}</code>
                    </div>

                    <div class="mb-4">
                        <x-input-label for="code" :value="__('profile.two_factor_verification_code')" />
                        <x-text-input
                            wire:model="code"
                            id="code"
                            type="text"
                            class="mt-1 block w-full max-w-xs"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="6"
                            autocomplete="one-time-code"
                            placeholder="000000"
                        />
                        <x-input-error :messages="$errors->get('code')" class="mt-2" />
                    </div>

                    <div class="flex gap-3">
                        <x-primary-button wire:click="confirmTwoFactor" type="button">
                            {{ __('profile.two_factor_confirm') }}
                        </x-primary-button>

                        <x-secondary-button wire:click="cancelSetup" type="button">
                            {{ __('profile.two_factor_cancel') }}
                        </x-secondary-button>
                    </div>
                </div>
            @endif

        @else
            {{-- 2FA not enabled --}}
            <p class="text-sm text-gray-600 mb-4">
                {{ __('profile.two_factor_not_enabled_description') }}
            </p>

            <x-primary-button wire:click="enableTwoFactor" type="button">
                {{ __('profile.two_factor_enable') }}
            </x-primary-button>
        @endif
    </div>

    {{-- Recovery Codes Modal --}}
    @if ($showingRecoveryCodes)
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50" wire:click.self="hideRecoveryCodes">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    {{ __('profile.two_factor_recovery_codes_title') }}
                </h3>

                <p class="text-sm text-gray-600 mb-4">
                    {{ __('profile.two_factor_recovery_codes_description') }}
                </p>

                <div x-data="{ copied: false }" class="bg-gray-100 rounded-lg p-4 mb-4 font-mono text-sm">
                    @foreach ($recoveryCodes as $code)
                        <div class="py-1">{{ $code }}</div>
                    @endforeach
                </div>

                <div class="flex justify-between items-center">
                    <button
                        type="button"
                        x-data="{ copied: false }"
                        @click="navigator.clipboard.writeText('{{ implode('\n', $recoveryCodes) }}').then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        class="text-sm text-indigo-600 hover:text-indigo-800"
                    >
                        <span x-show="!copied">{{ __('profile.two_factor_copy_all') }}</span>
                        <span x-show="copied" x-cloak>{{ __('profile.two_factor_copied') }}</span>
                    </button>

                    <x-primary-button wire:click="hideRecoveryCodes" type="button">
                        {{ __('profile.two_factor_done') }}
                    </x-primary-button>
                </div>
            </div>
        </div>
    @endif

    {{-- View Recovery Codes Confirmation Modal --}}
    @if ($confirmingShowCodes)
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    {{ __('profile.two_factor_view_codes_title') }}
                </h3>

                <p class="text-sm text-gray-600 mb-4">
                    {{ __('profile.two_factor_view_codes_description') }}
                </p>

                <div class="mb-4">
                    <x-input-label for="show_codes_password" :value="__('profile.two_factor_password')" />
                    <x-text-input
                        wire:model="password"
                        id="show_codes_password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                    />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div class="flex justify-end gap-3">
                    <x-secondary-button wire:click="cancelShowCodes" type="button">
                        {{ __('profile.two_factor_cancel') }}
                    </x-secondary-button>

                    <x-primary-button wire:click="showRecoveryCodes" type="button">
                        {{ __('profile.two_factor_view_codes_button') }}
                    </x-primary-button>
                </div>
            </div>
        </div>
    @endif

    {{-- Disable Confirmation Modal --}}
    @if ($confirmingDisable)
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    {{ __('profile.two_factor_disable_title') }}
                </h3>

                <p class="text-sm text-gray-600 mb-4">
                    {{ __('profile.two_factor_disable_description') }}
                </p>

                <div class="mb-4">
                    <x-input-label for="disable_password" :value="__('profile.two_factor_password')" />
                    <x-text-input
                        wire:model="password"
                        id="disable_password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                    />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div class="flex justify-end gap-3">
                    <x-secondary-button wire:click="cancelDisable" type="button">
                        {{ __('profile.two_factor_cancel') }}
                    </x-secondary-button>

                    <x-danger-button wire:click="disableTwoFactor" type="button">
                        {{ __('profile.two_factor_disable') }}
                    </x-danger-button>
                </div>
            </div>
        </div>
    @endif

    {{-- Regenerate Codes Confirmation Modal --}}
    @if ($confirmingRegenerateCodes)
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    {{ __('profile.two_factor_regenerate_title') }}
                </h3>

                <p class="text-sm text-gray-600 mb-4">
                    {{ __('profile.two_factor_regenerate_description') }}
                </p>

                <div class="mb-4">
                    <x-input-label for="regen_password" :value="__('profile.two_factor_password')" />
                    <x-text-input
                        wire:model="password"
                        id="regen_password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                    />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="regen_code" :value="__('profile.two_factor_current_code')" />
                    <x-text-input
                        wire:model="code"
                        id="regen_code"
                        type="text"
                        class="mt-1 block w-full"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="6"
                        autocomplete="one-time-code"
                        placeholder="000000"
                    />
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                </div>

                <div class="flex justify-end gap-3">
                    <x-secondary-button wire:click="cancelRegenerate" type="button">
                        {{ __('profile.two_factor_cancel') }}
                    </x-secondary-button>

                    <x-primary-button wire:click="regenerateRecoveryCodes" type="button">
                        {{ __('profile.two_factor_regenerate_button') }}
                    </x-primary-button>
                </div>
            </div>
        </div>
    @endif
</section>
