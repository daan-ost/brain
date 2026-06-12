<?php

if (! function_exists('asset_localized')) {
    /**
     * Get a localized version of an asset filename.
     *
     * Looks for a locale-specific version first (e.g., image_nl.webp),
     * falls back to the original filename if not found.
     *
     * @param  string|null  $filename  The original filename (e.g., "image.webp")
     * @param  string|null  $locale  The locale code (e.g., "nl", "en"). Defaults to app locale.
     * @return string The localized filename or original if not found
     *
     * @example
     * // If current locale is 'nl':
     * asset_localized('convert_batch.webp') // Returns 'convert_batch_nl.webp' if exists, else 'convert_batch.webp'
     */
    function asset_localized(?string $filename, ?string $locale = null): string
    {
        if (empty($filename)) {
            return '';
        }

        $locale = $locale ?? app()->getLocale();

        // If already in English or no locale specified, return original
        if ($locale === 'en' || $locale === config('app.fallback_locale')) {
            return $filename;
        }

        // Split filename into name and extension
        $pathInfo = pathinfo($filename);
        $extension = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';
        $basename = $pathInfo['filename'];
        $dirname = $pathInfo['dirname'] !== '.' ? $pathInfo['dirname'].'/' : '';

        // Create localized filename: image.webp → image_nl.webp
        $localizedFilename = "{$dirname}{$basename}_{$locale}{$extension}";

        // Check if localized asset exists in public directory
        $publicPath = public_path($localizedFilename);

        if (file_exists($publicPath)) {
            return $localizedFilename;
        }

        // Fallback to original filename
        return $filename;
    }
}

if (! function_exists('image_localized_url')) {
    /**
     * Get the full URL for a localized image asset.
     *
     * @param  string|null  $filename  The original filename
     * @param  string|null  $locale  The locale code. Defaults to app locale.
     * @return string The full URL to the localized asset
     */
    function image_localized_url(?string $filename, ?string $locale = null): string
    {
        if (empty($filename)) {
            return '';
        }

        $localizedFilename = asset_localized($filename, $locale);

        // Use Laravel asset helper
        return asset($localizedFilename);
    }
}
