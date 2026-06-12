<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToPreferredLocale
{
    /**
     * Handle an incoming request.
     *
     * Redirects users to their preferred locale version of a page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $currentPath = $request->path();

            // Skip API routes, admin routes, assets
            if ($this->shouldSkipRedirect($currentPath)) {
                return $next($request);
            }

            $preferredLocale = $this->getUserPreferredLocale($request);

            // Ensure preferredLocale is a valid string
            if (! is_string($preferredLocale) || ! in_array($preferredLocale, ['en', 'nl'])) {
                return $next($request);
            }

            // Skip if already on correct locale
            if ($this->isOnCorrectLocale($currentPath, $preferredLocale)) {
                return $next($request);
            }

            // User wants NL but is on EN page - redirect to NL version if available
            if ($preferredLocale === 'nl' && ! str_starts_with($currentPath, 'nl/')) {
                $nlPath = $this->findNlPath($currentPath);
                if ($nlPath) {
                    return redirect($nlPath, 302);
                }
            }

            // User wants EN but is on NL page - redirect to EN version
            if ($preferredLocale === 'en' && str_starts_with($currentPath, 'nl/')) {
                $enPath = $this->findEnPath($currentPath);
                if ($enPath) {
                    return redirect('/'.$enPath, 302);
                }
            }

            return $next($request);
        } catch (\Throwable $e) {
            // Log error and continue without redirect
            \Log::error('RedirectToPreferredLocale middleware error: '.$e->getMessage());

            return $next($request);
        }
    }

    /**
     * Determine user's preferred locale
     */
    private function getUserPreferredLocale(Request $request): string
    {
        // 1. Authenticated user preference
        if (auth()->check() && auth()->user()->preferred_language) {
            $preferredLanguage = auth()->user()->preferred_language;
            if (is_string($preferredLanguage) && in_array($preferredLanguage, ['en', 'nl'])) {
                return $preferredLanguage;
            }
        }

        // 2. Guest session preference
        if (session()->has('guest_language')) {
            $guestLanguage = session('guest_language');
            if (is_string($guestLanguage) && in_array($guestLanguage, ['en', 'nl'])) {
                return $guestLanguage;
            }
        }

        // 3. Default fallback
        return 'en';
    }

    /**
     * Check if request is already on the correct locale
     */
    private function isOnCorrectLocale(string $path, string $preferredLocale): bool
    {
        if ($preferredLocale === 'nl' && str_starts_with($path, 'nl/')) {
            return true;
        }

        if ($preferredLocale === 'en' && ! str_starts_with($path, 'nl/')) {
            return true;
        }

        return false;
    }

    /**
     * Check if this route should skip redirect logic
     */
    private function shouldSkipRedirect(string $path): bool
    {
        $skipPrefixes = [
            'api/',
            'admin/',
            'beheer/',
            'livewire/',
            'storage/',
            'css/',
            'js/',
            'images/',
            'fonts/',
            '_debugbar/',
        ];

        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        // Skip routes that have their own locale handling
        $skipExact = [
            'blog',
            'nl/blog',
            'terms',
            'privacy',
            'contact',
            'dashboard',
            'profile',
            'login',
            'register',
        ];

        foreach ($skipExact as $exactPath) {
            if ($path === $exactPath || str_starts_with($path, $exactPath.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the Dutch path for an English path
     */
    private function findNlPath(string $enPath): ?string
    {
        // Static page mappings
        $pageMap = [
            'terms' => 'nl/voorwaarden',
            'privacy' => 'nl/privacybeleid',
            'contact' => 'nl/contact',
            'pricing' => 'nl/prijs',
        ];

        if (isset($pageMap[$enPath])) {
            return $pageMap[$enPath];
        }

        // For blog posts, prefix with nl/
        if (str_starts_with($enPath, 'blog/')) {
            return 'nl/'.$enPath;
        }

        return null;
    }

    /**
     * Find the English path for a Dutch path
     */
    private function findEnPath(string $nlPath): ?string
    {
        // Remove nl/ prefix
        $path = str_starts_with($nlPath, 'nl/') ? substr($nlPath, 3) : $nlPath;

        // Static page mappings (reverse)
        $pageMap = [
            'voorwaarden' => 'terms',
            'privacybeleid' => 'privacy',
            'contact' => 'contact',
            'prijs' => 'pricing',
        ];

        if (isset($pageMap[$path])) {
            return $pageMap[$path];
        }

        // For blog posts, just return the path without nl/
        if (str_starts_with($path, 'blog/')) {
            return $path;
        }

        return null;
    }
}
