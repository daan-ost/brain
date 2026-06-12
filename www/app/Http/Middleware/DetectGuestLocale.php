<?php

namespace App\Http\Middleware;

use App\Services\LocaleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DetectGuestLocale
{
    public function __construct(
        protected LocaleService $localeService
    ) {}

    /**
     * Handle an incoming request.
     *
     * Detects browser locale settings for guests and stores them in session.
     * For authenticated users, uses their saved preferences.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for API requests
        if ($request->expectsJson() || $request->is('api/*')) {
            return $next($request);
        }

        // For authenticated users, apply their preferences
        if ($user = $request->user()) {
            $this->applyUserLocale($user);

            return $next($request);
        }

        // For guests, detect from browser or use session
        if (! session()->has('guest_locale')) {
            $this->detectAndStoreGuestLocale($request);
        }

        $this->applyGuestLocale();

        return $next($request);
    }

    /**
     * Apply locale settings from authenticated user.
     */
    protected function applyUserLocale($user): void
    {
        // Set timezone for date/time operations
        if ($user->timezone) {
            config(['app.timezone' => $user->timezone]);
            date_default_timezone_set($user->timezone);
        }

        // Store locale settings in session for Blade templates
        session([
            'locale' => [
                'timezone' => $user->timezone ?? LocaleService::DEFAULT_LOCALE['timezone'],
                'currency' => $user->currency_preference ?? LocaleService::DEFAULT_LOCALE['currency'],
                'date_format' => $user->date_format ?? LocaleService::DEFAULT_LOCALE['date_format'],
                'time_format' => $user->time_format ?? LocaleService::DEFAULT_LOCALE['time_format'],
                'datetime_format' => $user->datetime_format ?? LocaleService::DEFAULT_LOCALE['datetime_format'],
                'decimal_separator' => $user->decimal_separator ?? LocaleService::DEFAULT_LOCALE['decimal_separator'],
                'first_day_of_week' => $user->first_day_of_week ?? LocaleService::DEFAULT_LOCALE['first_day_of_week'],
            ],
        ]);
    }

    /**
     * Detect guest locale from browser headers.
     */
    protected function detectAndStoreGuestLocale(Request $request): void
    {
        // Try to get timezone from cookie (set by JavaScript)
        $browserTimezone = $request->cookie('browser_timezone');

        // Get browser locale from Accept-Language header
        $browserLocale = $this->parseAcceptLanguage($request->header('Accept-Language'));

        $locale = $this->localeService->getLocaleFromBrowser($browserTimezone, $browserLocale);

        session(['guest_locale' => $locale]);
    }

    /**
     * Apply guest locale settings.
     */
    protected function applyGuestLocale(): void
    {
        $locale = session('guest_locale', LocaleService::DEFAULT_LOCALE);

        // Set timezone
        if (isset($locale['timezone']) && $this->localeService->isValidTimezone($locale['timezone'])) {
            config(['app.timezone' => $locale['timezone']]);
            date_default_timezone_set($locale['timezone']);
        }

        // Store in session for templates
        session(['locale' => $locale]);
    }

    /**
     * Parse Accept-Language header to get primary locale.
     */
    protected function parseAcceptLanguage(?string $header): ?string
    {
        if (! $header) {
            return null;
        }

        // Parse "nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7"
        $locales = explode(',', $header);
        if (empty($locales)) {
            return null;
        }

        // Get first locale (highest priority)
        $primary = trim(explode(';', $locales[0])[0]);

        return $primary ?: null;
    }
}
