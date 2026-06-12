<?php

namespace App\Services;

use App\Models\License;
use App\Models\User;

/**
 * Service voor het bepalen van upload limits
 *
 * Business Rule: Gebruik LAAGSTE waarde van license limits vs landing page limits
 *
 * Priority:
 * 1. Per-conversion license limits (meest specifiek)
 * 2. Global license limits
 * 3. Landing page limits (fallback/maximum)
 * 4. Default guest/free limits
 */
class LicenseLimitService
{
    /**
     * Get upload limits voor user op specifieke landing page
     *
     * @param  User|null  $user  Guest = null
     * @param  string  $pageSlug  Landing page slug (e.g., 'excel-to-pdf')
     * @return array ['max_files' => int, 'max_total_size' => int, 'max_pages' => int, 'source' => string]
     */
    public function getLimitsForUser(?User $user, string $pageSlug): array
    {
        // Get license limits (if user exists)
        $licenseLimits = $this->getLicenseLimits($user, $pageSlug);

        // Get landing page limits (fallback/maximum)
        $landingPageLimits = $this->getLandingPageLimits($pageSlug);

        // Use LOWEST value for each limit type
        $finalLimits = $this->mergeLowest($licenseLimits, $landingPageLimits);

        // Add metadata
        $finalLimits['source'] = $this->determineSource($user, $licenseLimits, $landingPageLimits);
        $finalLimits['page_slug'] = $pageSlug;

        return $finalLimits;
    }

    /**
     * Get limits from user's license
     */
    protected function getLicenseLimits(?User $user, string $pageSlug): array
    {
        // Guest users → default guest limits
        if (! $user) {
            return License::getDefaultGuestLimits();
        }

        // Get user's active license
        $license = $user->getCurrentLicense();

        if (! $license) {
            // User without license → free user limits
            return License::getDefaultFreeUserLimits();
        }

        // Get limits from license (checks per-conversion first, then global)
        $limits = $license->getUploadLimits($pageSlug);

        // If license has no limits defined, fall back to free user limits
        if (empty($limits)) {
            return License::getDefaultFreeUserLimits();
        }

        return $limits;
    }

    /**
     * Get limits from landing page config
     */
    protected function getLandingPageLimits(string $pageSlug): array
    {
        // Resolve Dutch slug to English config key
        $nlMapping = config('landing_pages.nl_slug_mapping', []);
        $configSlug = $nlMapping[$pageSlug] ?? $pageSlug;

        // Get landing page config
        $pageConfig = config("landing_pages.{$configSlug}");

        if (! $pageConfig || ! isset($pageConfig['limits'])) {
            // No landing page limits defined → use very high defaults (essentially unlimited)
            return [
                'max_files' => 999,
                'max_total_size' => 10 * 1024 * 1024 * 1024, // 10GB
                'max_pages' => 99999,
            ];
        }

        return $pageConfig['limits'];
    }

    /**
     * Merge two limit arrays, taking LOWEST value for each key
     */
    protected function mergeLowest(array $limits1, array $limits2): array
    {
        $keys = ['max_files', 'max_total_size', 'max_pages', 'max_file_size'];
        $merged = [];

        foreach ($keys as $key) {
            $value1 = $limits1[$key] ?? PHP_INT_MAX;
            $value2 = $limits2[$key] ?? PHP_INT_MAX;

            // Use lowest (most restrictive)
            $merged[$key] = min($value1, $value2);
        }

        return $merged;
    }

    /**
     * Determine source of limits for debugging/logging
     */
    protected function determineSource(?User $user, array $licenseLimits, array $landingPageLimits): string
    {
        if (! $user) {
            return 'guest_default';
        }

        $license = $user->getCurrentLicense();

        if (! $license) {
            return 'free_user_default';
        }

        // If license has any upload limits defined, source is 'license'
        // (even if final limits are restricted by landing page being lower)
        $restrictions = $license->json_restrictions ?? [];

        // Handle case where accessor returns JSON string instead of array
        if (is_string($restrictions)) {
            $restrictions = json_decode($restrictions, true) ?? [];
        }

        $hasLicenseLimits = ! empty($restrictions['upload_limits']);

        return $hasLicenseLimits ? 'license' : 'landing_page';
    }

    /**
     * Validate if files meet limits
     *
     * @param  array  $files  Array of UploadedFile objects
     * @param  array  $limits  From getLimitsForUser()
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateFiles(array $files, array $limits): array
    {
        $fileCount = count($files);
        $totalSize = array_sum(array_map(fn ($file) => $file->getSize(), $files));

        // Check file count
        if ($fileCount > $limits['max_files']) {
            return [
                'valid' => false,
                'error' => "Maximum {$limits['max_files']} file(s) allowed. You selected {$fileCount}.",
            ];
        }

        // Check individual file size (if limit is set)
        if (isset($limits['max_file_size']) && $limits['max_file_size'] < PHP_INT_MAX) {
            foreach ($files as $file) {
                $fileSize = $file->getSize();

                if ($fileSize > $limits['max_file_size']) {
                    $maxSizeMB = round($limits['max_file_size'] / 1024 / 1024, 1);
                    $actualSizeMB = round($fileSize / 1024 / 1024, 1);
                    $fileName = method_exists($file, 'getClientOriginalName')
                        ? $file->getClientOriginalName()
                        : 'file';

                    return [
                        'valid' => false,
                        'error' => "File '{$fileName}' exceeds the maximum file size of {$maxSizeMB}MB (file is {$actualSizeMB}MB).",
                    ];
                }
            }
        }

        // Check total size
        if ($totalSize > $limits['max_total_size']) {
            $maxSizeMB = round($limits['max_total_size'] / 1024 / 1024, 1);
            $actualSizeMB = round($totalSize / 1024 / 1024, 1);

            return [
                'valid' => false,
                'error' => "Total file size exceeds {$maxSizeMB}MB. Your files are {$actualSizeMB}MB.",
            ];
        }

        // TODO: Check max_pages (requires PDF parsing - implement later)
        // For now, page count validation happens during processing

        return ['valid' => true, 'error' => null];
    }

    /**
     * Format limits for user-friendly display
     *
     * @return array ['max_files_text' => string, 'max_size_text' => string, 'max_file_size_text' => string]
     */
    public function formatLimitsForDisplay(array $limits): array
    {
        $maxTotalSizeMB = round($limits['max_total_size'] / 1024 / 1024);
        $maxFileSizeMB = isset($limits['max_file_size']) && $limits['max_file_size'] < PHP_INT_MAX
            ? round($limits['max_file_size'] / 1024 / 1024)
            : null;

        $display = [
            'max_files_text' => $limits['max_files'].' file'.($limits['max_files'] > 1 ? 's' : ''),
            'max_size_text' => $maxTotalSizeMB.'MB',
            'max_pages_text' => number_format($limits['max_pages']).' pages',
        ];

        if ($maxFileSizeMB !== null) {
            $display['max_file_size_text'] = $maxFileSizeMB.'MB per file';
        }

        return $display;
    }
}
