<?php

namespace App\Http\Middleware;

use App\Services\AnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogLandingPageView
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only log for GET requests that expect HTML
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            return $next($request);
        }

        // Skip API routes
        if ($this->isApiRequest($request)) {
            return $next($request);
        }

        // Skip asset requests
        if ($this->isAssetRequest($request)) {
            return $next($request);
        }

        // Extract slug from route parameter or path
        $slug = $request->route('slug') ?? $this->extractSlugFromPath($request);

        // Extract locale from route parameter or path (fallback to app locale)
        $locale = $request->route('locale') ?? $this->extractLocaleFromPath($request);

        if ($slug) {
            // Normalize NL slugs to EN for consistent analytics
            $canonicalSlug = $this->normalizeSlugToEnglish($slug, $locale);

            // Replace slashes with underscores for profile pages
            $canonicalSlug = str_replace('/', '_', $canonicalSlug);

            // Determine page type based on slug
            $pageType = $this->determinePageType($slug);

            // Prevent duplicate logging within short timeframe
            // Use both slug AND locale in cache key to allow language switch logging
            $cacheKey = 'landing_page_log_'.($request->session()->getId() ?? 'guest').'_'.$canonicalSlug.'_'.$locale;
            $lastLogged = cache($cacheKey);

            // Skip if exact same page+locale was logged in last 2 seconds
            if ($lastLogged && now()->diffInSeconds($lastLogged) < 2) {
                return $next($request);
            }

            // Log landing page view
            AnalyticsService::log('landing_page_view', [
                'page_slug' => $canonicalSlug,
                'page_type' => $pageType,
                'site' => $locale,
                'referrer' => $request->header('referer'),
                'user_agent' => $request->header('user-agent'),
            ]);

            // Cache this log for 2 seconds
            cache([$cacheKey => now()], now()->addSeconds(2));
        }

        return $next($request);
    }

    /**
     * Normalize slug to English canonical version for consistent analytics
     */
    private function normalizeSlugToEnglish(string $slug, string $locale): string
    {
        // If locale is Dutch, attempt to map to English slug
        if ($locale === 'nl') {
            $mapping = config('landing_pages.nl_slug_mapping', []);

            return $mapping[$slug] ?? $slug;
        }

        return $slug;
    }

    /**
     * Check if the request is for an API endpoint
     */
    private function isApiRequest(Request $request): bool
    {
        $path = $request->path();

        return str_starts_with($path, 'api/');
    }

    /**
     * Check if the request is for an asset (CSS, JS, images, etc.)
     */
    private function isAssetRequest(Request $request): bool
    {
        $path = $request->path();

        $assetExtensions = [
            'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp',
            'woff', 'woff2', 'ttf', 'eot', 'ico', 'map', 'json',
        ];

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), $assetExtensions) ||
               str_starts_with($path, 'build/') ||
               str_starts_with($path, 'assets/');
    }

    /**
     * Extract slug from the request path
     */
    private function extractSlugFromPath(Request $request): ?string
    {
        $path = trim($request->path(), '/');

        // Remove locale prefix if present
        if (str_starts_with($path, 'nl/')) {
            $path = substr($path, 3);
        }

        // Skip system/auth/download/profile paths
        $skipPaths = [
            '', 'home', 'dashboard', 'login', 'register', 'logout',
            'email/verify', 'email/confirm', 'password/reset',
            'password/email', 'password/setup', 'forgot-password', 'reset-password',
            'download', 'batch', 'profile', 'invitations',
        ];

        foreach ($skipPaths as $skipPath) {
            if ($path === $skipPath || str_starts_with($path, $skipPath.'/')) {
                return null;
            }
        }

        // Return slug if not empty and not a system path
        if ($path) {
            return $path;
        }

        return null;
    }

    /**
     * Extract locale from the request path
     */
    private function extractLocaleFromPath(Request $request): string
    {
        $path = trim($request->path(), '/');

        // Check if path starts with a locale
        if (str_starts_with($path, 'nl/') || $path === 'nl') {
            return 'nl';
        }

        // Fallback to Laravel's current locale (set by SetLocale middleware)
        return app()->getLocale();
    }

    /**
     * Determine page type based on slug
     */
    private function determinePageType(string $slug): string
    {
        // Profile pages (except organization - handled by OrganizationController)
        if (str_starts_with($slug, 'profile/') || $slug === 'profile') {
            return 'profile';
        }

        // Default to conversion landing page
        return 'conversion_landing';
    }
}
