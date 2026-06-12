<div id="regional-settings" class="scroll-mt-32">
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('profile.localization_title') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                {{ __('profile.localization_description') }}
            </p>
        </header>

        <form wire:submit="save" class="mt-6 space-y-6">
            <!-- Timezone -->
            <div>
                <x-input-label for="timezone" :value="__('profile.timezone')" />
                <select
                    id="timezone"
                    wire:model="timezone"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                >
                    <option value="">{{ __('profile.select_timezone') }}</option>
                    @foreach($timezones as $tz)
                        <option value="{{ $tz }}">{{ $tz }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
            </div>

            <!-- Currency -->
            <div>
                <x-input-label for="currency_preference" :value="__('profile.currency')" />
                <select
                    id="currency_preference"
                    wire:model="currency_preference"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                >
                    <option value="">{{ __('profile.select_currency') }}</option>
                    @foreach($currencies as $code => $info)
                        <option value="{{ $code }}">{{ $info['symbol'] }} - {{ $info['name'] }} ({{ $code }})</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('currency_preference')" class="mt-2" />
            </div>

            <!-- Date Format -->
            <div>
                <x-input-label for="date_format" :value="__('profile.date_format')" />
                <select
                    id="date_format"
                    wire:model="date_format"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                >
                    <option value="">{{ __('profile.select_date_format') }}</option>
                    @foreach($dateFormats as $format => $example)
                        <option value="{{ $format }}">{{ $example }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('date_format')" class="mt-2" />
            </div>

            <!-- Time Format -->
            <div>
                <x-input-label for="time_format" :value="__('profile.time_format')" />
                <div class="mt-2 space-y-2">
                    <label class="flex items-center">
                        <input
                            type="radio"
                            wire:model="time_format"
                            value="24h"
                            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                        >
                        <span class="ml-3 text-sm text-gray-700">
                            <strong>24h</strong> - {{ $timeFormats['24h'] }}
                        </span>
                    </label>
                    <label class="flex items-center">
                        <input
                            type="radio"
                            wire:model="time_format"
                            value="12h"
                            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                        >
                        <span class="ml-3 text-sm text-gray-700">
                            <strong>12h</strong> - {{ $timeFormats['12h'] }}
                        </span>
                    </label>
                </div>
                <x-input-error :messages="$errors->get('time_format')" class="mt-2" />
            </div>

            <!-- Decimal Separator -->
            <div>
                <x-input-label for="decimal_separator" :value="__('profile.decimal_separator')" />
                <div class="mt-2 space-y-2">
                    <label class="flex items-center">
                        <input
                            type="radio"
                            wire:model="decimal_separator"
                            value=","
                            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                        >
                        <span class="ml-3 text-sm text-gray-700">
                            <strong>{{ __('profile.decimal_comma') }}</strong> - 1.234,56
                        </span>
                    </label>
                    <label class="flex items-center">
                        <input
                            type="radio"
                            wire:model="decimal_separator"
                            value="."
                            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                        >
                        <span class="ml-3 text-sm text-gray-700">
                            <strong>{{ __('profile.decimal_period') }}</strong> - 1,234.56
                        </span>
                    </label>
                </div>
                <x-input-error :messages="$errors->get('decimal_separator')" class="mt-2" />
            </div>

            <!-- First Day of Week -->
            <div>
                <x-input-label for="first_day_of_week" :value="__('profile.first_day_of_week')" />
                <div class="mt-2 space-y-2">
                    <label class="flex items-center">
                        <input
                            type="radio"
                            wire:model="first_day_of_week"
                            value="1"
                            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                        >
                        <span class="ml-3 text-sm text-gray-700">{{ __('profile.monday') }}</span>
                    </label>
                    <label class="flex items-center">
                        <input
                            type="radio"
                            wire:model="first_day_of_week"
                            value="0"
                            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                        >
                        <span class="ml-3 text-sm text-gray-700">{{ __('profile.sunday') }}</span>
                    </label>
                </div>
                <x-input-error :messages="$errors->get('first_day_of_week')" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <x-primary-button>{{ __('profile.save') }}</x-primary-button>

                <button
                    type="button"
                    wire:click="resetToCountryDefaults"
                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                >
                    {{ __('profile.reset_to_defaults') }}
                </button>

                @if (session('status') === 'localization-updated')
                    <p
                        x-data="{ show: true }"
                        x-show="show"
                        x-transition
                        x-init="setTimeout(() => show = false, 2000)"
                        class="text-sm text-gray-600"
                    >{{ __('profile.saved') }}</p>
                @endif

                @if (session('status') === 'localization-reset')
                    <p
                        x-data="{ show: true }"
                        x-show="show"
                        x-transition
                        x-init="setTimeout(() => show = false, 2000)"
                        class="text-sm text-gray-600"
                    >{{ __('profile.reset_complete') }}</p>
                @endif
            </div>

            @error('country')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
        </form>
    </section>

    @push('scripts')
    <script>
        // Auto-detect browser locale on first load if no settings exist
        document.addEventListener('livewire:initialized', () => {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const locale = navigator.language || navigator.userLanguage;

            // Check if we should auto-detect (component will handle logic)
            @this.detectFromBrowser(timezone, locale);
        });
    </script>
    @endpush
</div>
