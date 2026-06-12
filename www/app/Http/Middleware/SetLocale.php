<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip locale detection for admin routes
        $path = $request->path();
        if (str_starts_with($path, 'beheer/') || str_starts_with($path, 'beheer')) {
            App::setLocale('en'); // Default for admin

            return $next($request);
        }

        try {
            $locale = $this->determineLocale($request);

            // Ensure locale is a string and valid
            if ($locale && is_string($locale) && in_array($locale, ['en', 'nl'])) {
                App::setLocale($locale);
            } else {
                // Fallback to default locale if invalid
                App::setLocale(config('app.locale', 'en'));
            }
        } catch (\Throwable $e) {
            // Log error and continue with default locale
            \Log::error('SetLocale middleware error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            App::setLocale('en');
        }

        return $next($request);
    }

    /**
     * Determine the locale based on user preference, session, or auto-detection
     */
    private function determineLocale(Request $request): ?string
    {
        // 0. URL-based locale detection (HIGHEST PRIORITY)
        // If URL starts with /nl/, always use Dutch
        $path = $request->path();
        if (str_starts_with($path, 'nl/') || str_starts_with($path, 'nl')) {
            Session::put('guest_language', 'nl');

            return 'nl';
        }

        // 1. Authenticated user preference
        if (auth()->check() && auth()->user()->preferred_language) {
            $preferredLanguage = auth()->user()->preferred_language;
            // Ensure it's a string and a valid locale
            if (is_string($preferredLanguage) && in_array($preferredLanguage, ['en', 'nl'])) {
                return $preferredLanguage;
            }
        }

        // 2. Guest session preference
        if (Session::has('guest_language')) {
            $guestLanguage = Session::get('guest_language');
            // Ensure it's a string and a valid locale
            if (is_string($guestLanguage) && in_array($guestLanguage, ['en', 'nl'])) {
                return $guestLanguage;
            }
        }

        // 3. Auto-detect language for first-time users (only if they don't have a preference)
        if (auth()->check()) {
            $user = auth()->user();

            // Only auto-detect if user has no language preference AND has existing country data
            if (! $user->preferred_language && ($user->billing_country_code || $user->ipregistry_country_code)) {
                // Use session flag to prevent a DB write on every request
                if (Session::has('lang_detected_'.$user->id)) {
                    return Session::get('lang_detected_'.$user->id);
                }

                $detectedLanguage = $this->detectLanguageFromCountry($user);
                if ($detectedLanguage) {
                    // Save the detected language to user profile (one-time only)
                    $user->update(['preferred_language' => $detectedLanguage]);
                    Session::put('lang_detected_'.$user->id, $detectedLanguage);

                    return $detectedLanguage;
                }
            }
        } else {
            // For guests, use simple Accept-Language header detection (no IP calls)
            $detectedLanguage = $this->detectLanguageForGuest($request);
            if ($detectedLanguage) {
                Session::put('guest_language', $detectedLanguage);

                return $detectedLanguage;
            }
        }

        // 4. Default fallback
        return config('app.locale', 'en');
    }

    /**
     * Detect language from user's country information
     */
    private function detectLanguageFromCountry($user): ?string
    {
        $countryCode = $user->billing_country_code ?? $user->ipregistry_country_code;

        if (! $countryCode) {
            return null;
        }

        // Map countries to languages
        $countryLanguageMap = [
            'NL' => 'nl', // Netherlands
            'BE' => 'nl', // Belgium (could be Dutch or French, defaulting to Dutch)
            // All other countries default to English
        ];

        return $countryLanguageMap[$countryCode] ?? 'en';
    }

    /**
     * Detect language for guest users (browser-based, no IP calls)
     *
     * Note: IP detection happens separately in CountryContextResolver during
     * billing/payment flows, not on every request via middleware.
     */
    private function detectLanguageForGuest(Request $request): ?string
    {
        // Use Accept-Language header (no external API calls)
        $acceptLanguage = $request->header('Accept-Language', '');

        // Simple language detection from browser preferences
        $languages = explode(',', strtolower($acceptLanguage));
        foreach ($languages as $lang) {
            $lang = trim(explode(';', $lang)[0]); // Remove quality factors

            if (str_starts_with($lang, 'nl')) {
                return 'nl';
            }
        }

        // Default to English for guests
        return 'en';
    }
}
