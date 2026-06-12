<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class LanguageController extends Controller
{
    /**
     * Switch the application language with URL translation
     */
    public function switch(Request $request): RedirectResponse
    {
        $locale = $request->input('locale', 'en');
        $redirect = $request->input('redirect');

        // Validate language
        if (! in_array($locale, ['en', 'nl'])) {
            return back()->with('error', 'Invalid language selected.');
        }

        // Validate redirect URL to prevent open redirect attacks
        $redirect = $this->validateRedirectUrl($redirect);

        // Capture previous locale for analytics
        $previousLocale = app()->getLocale();

        // Set app locale immediately for this request
        App::setLocale($locale);
        Session::put('locale', $locale);

        // For authenticated users, save to profile
        if (auth()->check()) {
            $user = auth()->user();
            $user->update(['preferred_language' => $locale]);
        }

        // For guests, store in session
        Session::put('guest_language', $locale);

        // Log language switch
        AnalyticsService::log('language_switch', [
            'from_locale' => $previousLocale,
            'to_locale' => $locale,
            'user_type' => auth()->check() ? 'authenticated' : 'guest',
            'source_url' => $redirect,
        ]);

        // Convert URL to target language
        $redirectUrl = $this->getLocalizedUrl($redirect, $locale);

        return redirect($redirectUrl)->with('status', 'Language updated successfully.');
    }

    /**
     * Convert URL to localized version
     */
    protected function getLocalizedUrl(?string $url, string $targetLocale): string
    {
        if (! $url) {
            return $targetLocale === 'nl' ? '/nl' : '/';
        }

        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '/';

        // Skip locale prefix for authenticated app routes
        // These routes are behind authentication and don't need language prefixes
        if (preg_match('#^/(profile|workflows|share|next-step|organization)(/|$)#', $path)) {
            return $url; // Return URL unchanged - just update session/user preference
        }

        // Remove current locale prefix (if exists)
        $currentLocale = str_starts_with($path, '/nl/') ? 'nl' : 'en';
        $path = preg_replace('#^/nl(/|$)#', '$1', $path);

        // Extract slug from path
        $slug = trim($path, '/');

        // Translate slug if switching between languages
        $translatedSlug = $this->translateSlug($slug, $currentLocale, $targetLocale);

        // Build new path with locale prefix and translated slug
        if ($targetLocale === 'nl') {
            $path = '/nl/'.$translatedSlug;
        } else {
            $path = '/'.$translatedSlug;
        }

        // Clean up double slashes and trailing slashes
        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/') ?: '/';

        // Rebuild URL
        $scheme = $parsedUrl['scheme'] ?? request()->getScheme();
        $host = $parsedUrl['host'] ?? request()->getHost();
        $port = isset($parsedUrl['port']) ? ':'.$parsedUrl['port'] : (request()->getPort() != 80 && request()->getPort() != 443 ? ':'.request()->getPort() : '');
        $query = isset($parsedUrl['query']) ? '?'.$parsedUrl['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /**
     * Translate slug between languages
     */
    protected function translateSlug(string $slug, string $fromLocale, string $toLocale): string
    {
        // No translation needed if same locale
        if ($fromLocale === $toLocale) {
            return $slug;
        }

        // Static page translations (NL → EN mapping)
        $staticTranslations = [
            'prijs' => 'pricing',
            'checkout' => 'checkout',
            'contact' => 'contact',
            'blog' => 'blog',
        ];

        // EN → NL: Find NL slug
        if ($fromLocale === 'en' && $toLocale === 'nl') {
            $nlSlug = array_search($slug, $staticTranslations);

            return $nlSlug ?: $slug;
        }

        // NL → EN: Find EN slug
        if ($fromLocale === 'nl' && $toLocale === 'en') {
            return $staticTranslations[$slug] ?? $slug;
        }

        return $slug;
    }

    /**
     * Validate redirect URL to prevent open redirect attacks.
     * Only allows relative URLs or URLs on the same host.
     */
    protected function validateRedirectUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        // Parse the URL
        $parsed = parse_url($url);

        // If it's a relative path (no scheme/host), it's safe
        if (! isset($parsed['scheme']) && ! isset($parsed['host'])) {
            // Prevent protocol-relative URLs (//evil.com)
            if (str_starts_with($url, '//')) {
                return null;
            }

            return $url;
        }

        // If it has a host, verify it matches our app's host
        if (isset($parsed['host'])) {
            $appHost = parse_url(config('app.url'), PHP_URL_HOST);
            if ($parsed['host'] !== $appHost) {
                return null;
            }
        }

        return $url;
    }
}
