<?php

namespace App\Livewire\Profile;

use App\Services\LocaleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class LocalizationSettings extends Component
{
    public string $timezone = '';

    public string $currency_preference = '';

    public string $date_format = '';

    public string $time_format = '24h';

    public string $decimal_separator = ',';

    public int $first_day_of_week = 1;

    protected LocaleService $localeService;

    public function boot(LocaleService $localeService): void
    {
        $this->localeService = $localeService;
    }

    public function mount(): void
    {
        $user = Auth::user();

        $this->timezone = $user->timezone ?? '';
        $this->currency_preference = $user->currency_preference ?? '';
        $this->date_format = $user->date_format ?? '';
        $this->time_format = $user->time_format ?? '24h';
        $this->decimal_separator = $user->decimal_separator ?? ',';
        $this->first_day_of_week = $user->first_day_of_week ?? 1;
    }

    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'currency_preference' => ['required', 'string', Rule::in(array_keys(LocaleService::CURRENCIES))],
            'date_format' => ['required', 'string', Rule::in(array_keys(LocaleService::DATE_FORMATS))],
            'time_format' => ['required', 'string', 'in:12h,24h'],
            'decimal_separator' => ['required', 'string', Rule::in(['.', ','])],
            'first_day_of_week' => ['required', 'integer', 'in:0,1'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $user = Auth::user();

        $datetimeFormat = $this->date_format . ' ' . ($this->time_format === '12h' ? 'g:i A' : 'H:i');

        $user->update([
            'timezone' => $this->timezone,
            'currency_preference' => $this->currency_preference,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
            'datetime_format' => $datetimeFormat,
            'decimal_separator' => $this->decimal_separator,
            'first_day_of_week' => $this->first_day_of_week,
            'locale_manually_set' => true,
        ]);

        $this->dispatch('localization-saved');

        session()->flash('status', 'localization-updated');
    }

    public function resetToCountryDefaults(): void
    {
        $user = Auth::user();
        $countryCode = $user->billing_country_code;

        if (! $countryCode) {
            $this->addError('country', __('profile.no_country_selected'));

            return;
        }

        $localeService = app(LocaleService::class);
        $defaults = $localeService->getDefaultsForCountry($countryCode);

        $this->timezone = $defaults['timezone'];
        $this->currency_preference = $defaults['currency'];
        $this->date_format = $defaults['date_format'];
        $this->time_format = $defaults['time_format'];
        $this->decimal_separator = $defaults['decimal_separator'];
        $this->first_day_of_week = $defaults['first_day_of_week'];

        $user->update([
            'timezone' => $this->timezone,
            'currency_preference' => $this->currency_preference,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
            'datetime_format' => $defaults['datetime_format'],
            'decimal_separator' => $this->decimal_separator,
            'first_day_of_week' => $this->first_day_of_week,
            'locale_manually_set' => false,
        ]);

        $this->dispatch('localization-reset');

        session()->flash('status', 'localization-reset');
    }

    public function detectFromBrowser(string $browserTimezone, string $browserLocale): void
    {
        // Sanity check on input length to prevent oversized input
        if (strlen($browserTimezone) > 100 || strlen($browserLocale) > 20) {
            return;
        }

        // Don't overwrite manually saved settings with browser detection
        $user = Auth::user();
        if ($user->locale_manually_set) {
            return;
        }

        $localeService = app(LocaleService::class);
        $detected = $localeService->getLocaleFromBrowser($browserTimezone, $browserLocale);

        $this->timezone = $detected['timezone'];
        $this->currency_preference = $detected['currency'];
        $this->date_format = $detected['date_format'];
        $this->time_format = $detected['time_format'];
        $this->decimal_separator = $detected['decimal_separator'];
        $this->first_day_of_week = $detected['first_day_of_week'];

        // Validate detected values before persisting to DB
        $this->validate();

        // Persist detected values so they're used even if user navigates away without saving
        $user->update([
            'timezone' => $detected['timezone'],
            'currency_preference' => $detected['currency'],
            'date_format' => $detected['date_format'],
            'time_format' => $detected['time_format'],
            'datetime_format' => $detected['datetime_format'] ?? ($detected['date_format'] . ' ' . ($detected['time_format'] === '12h' ? 'g:i A' : 'H:i')),
            'decimal_separator' => $detected['decimal_separator'],
            'first_day_of_week' => $detected['first_day_of_week'],
            'locale_manually_set' => false,
        ]);
    }

    public function getTimezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    public function getDateFormats(): array
    {
        return LocaleService::DATE_FORMATS;
    }

    public function getTimeFormats(): array
    {
        return LocaleService::TIME_FORMATS;
    }

    public function getCurrencies(): array
    {
        return LocaleService::CURRENCIES;
    }

    public function render()
    {
        return view('livewire.profile.localization-settings', [
            'timezones' => $this->getTimezones(),
            'dateFormats' => $this->getDateFormats(),
            'timeFormats' => $this->getTimeFormats(),
            'currencies' => $this->getCurrencies(),
        ]);
    }
}
