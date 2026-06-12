<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Support\Collection;

class LicensePriorityService
{
    /**
     * Get all active licenses for a user in priority order
     */
    public function getAllActiveLicenses(User $user): Collection
    {
        // Get individual licenses with eager loading
        $individualLicenses = $user->currentLicenses()
            ->with('license')
            ->get()
            ->map(function ($license) {
                $license->license_type = 'individual';
                $license->priority_score = $this->calculatePriorityScore($license, 'individual');

                return $license;
            });

        // Get organizational licenses with eager loading - single query for all organizations
        $organizations = $user->organizations()
            ->with(['currentLicenses.license'])
            ->get();

        $organizationalLicenses = collect();
        foreach ($organizations as $organization) {
            $orgLicenses = $organization->currentLicenses->map(function ($license) use ($organization) {
                $license->license_type = 'organizational';
                $license->source_organization = $organization;
                $license->priority_score = $this->calculatePriorityScore($license, 'organizational');

                return $license;
            });
            $organizationalLicenses = $organizationalLicenses->concat($orgLicenses);
        }

        // Merge and sort by priority score (higher score = higher priority)
        $allLicenses = $individualLicenses->concat($organizationalLicenses);

        // Filter out free licenses when paid licenses exist
        $allLicenses = $this->filterFreeLicensesIfPaidExists($allLicenses, $user);

        return $allLicenses->sortByDesc('priority_score')->values();
    }

    /**
     * Filter out free licenses when paid licenses exist
     * Exception: Show free license if user has a one-time license with 0 credits remaining
     */
    private function filterFreeLicensesIfPaidExists(Collection $licenses, User $user): Collection
    {
        // Separate free and non-free licenses
        $freeLicenses = $licenses->filter(fn ($l) => $l->license->tier === 'free');
        $paidLicenses = $licenses->filter(fn ($l) => $l->license->tier !== 'free');

        // If no paid licenses, return all (including free)
        if ($paidLicenses->isEmpty()) {
            return $licenses;
        }

        // If no free licenses, return all paid licenses
        if ($freeLicenses->isEmpty()) {
            return $paidLicenses;
        }

        // Check exception: one-time license with 0 credits and end date not reached
        $shouldShowFreeLicense = $this->hasExhaustedOneTimeLicense($paidLicenses, $user);

        if ($shouldShowFreeLicense) {
            return $licenses;
        }

        // Default: hide free licenses when paid licenses exist
        return $paidLicenses;
    }

    /**
     * Check if user has an exhausted one-time license (0 credits, end date not reached)
     */
    private function hasExhaustedOneTimeLicense(Collection $paidLicenses, User $user): bool
    {
        $creditsService = app(\App\Services\CreditsService::class);

        foreach ($paidLicenses as $license) {
            // Only check one-time licenses
            if ($license->license->billing_cycle !== 'once') {
                continue;
            }

            // Check if end date not reached (or no end date)
            if ($license->ends_at && $license->ends_at->isPast()) {
                continue;
            }

            // Check credit balance for this license
            if ($license->license_type === 'individual') {
                $balance = $creditsService->getUserCredits($user);
            } else {
                $balance = $creditsService->getOrganizationCredits($license->source_organization);
            }

            // If credits are 0, show the free license
            if ($balance <= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the primary (highest priority) active license for a user
     */
    public function getPrimaryActiveLicense(User $user)
    {
        return $this->getAllActiveLicenses($user)->first();
    }

    /**
     * Get all active organizational licenses for a user (admin access only)
     */
    public function getActiveOrganizationalLicenses(User $user): Collection
    {
        // Eager load organizations with pivot data and their licenses
        $organizations = $user->organizations()
            ->withPivot('role')
            ->with(['currentLicenses.license'])
            ->get();

        $organizationalLicenses = collect();

        foreach ($organizations as $organization) {
            if ($organization->pivot->role === OrganizationRole::Owner) {
                $orgLicenses = $organization->currentLicenses->map(function ($license) use ($organization) {
                    $license->license_type = 'organizational';
                    $license->source_organization = $organization;
                    $license->priority_score = $this->calculatePriorityScore($license, 'organizational');

                    return $license;
                });
                $organizationalLicenses = $organizationalLicenses->concat($orgLicenses);
            }
        }

        return $organizationalLicenses->sortByDesc('priority_score')->values();
    }

    /**
     * Get licenses in order for credit consumption
     * Returns licenses in the order they should be used for credit deduction
     */
    public function getLicensesForCreditConsumption(User $user): Collection
    {
        return $this->getAllActiveLicenses($user);
    }

    /**
     * Calculate priority score for a license
     * Higher score = higher priority
     */
    private function calculatePriorityScore($license, string $type): int
    {
        $score = 0;

        // Organizational licenses always get higher priority
        if ($type === 'organizational') {
            $score += 1000;
        }

        // Priority based on expiration date
        if ($license->ends_at) {
            // Licenses ending sooner get higher priority
            // Use days until expiration (max 365 days for scoring)
            $daysUntilExpiration = min(now()->diffInDays($license->ends_at, false), 365);
            if ($daysUntilExpiration > 0) {
                // Closer to expiration = higher priority (365 - days remaining)
                $score += (365 - $daysUntilExpiration);
            } else {
                // Already expired licenses get very low priority
                $score -= 1000;
            }
        } else {
            // No expiration date gets medium priority (180 days worth)
            $score += 185; // Slightly higher than middle expiration range
        }

        // Use creation date as tiebreaker (older licenses get slightly higher priority)
        if ($license->created_at) {
            $daysOld = min(now()->diffInDays($license->created_at), 365);
            $score += intval($daysOld / 10); // Small bonus for older licenses
        }

        return intval($score);
    }

    /**
     * Check if user has multiple active licenses
     */
    public function hasMultipleActiveLicenses(User $user): bool
    {
        return $this->getAllActiveLicenses($user)->count() > 1;
    }

    /**
     * Get display information for a license (used in views)
     */
    public function getLicenseDisplayInfo($license): array
    {
        return [
            'name' => $license->license->name,
            'type' => $license->license_type,
            'status' => $license->status,
            'starts_at' => $license->starts_at,
            'ends_at' => $license->ends_at,
            'source' => $license->source,
            'organization' => $license->license_type === 'organizational'
                ? $license->source_organization->name
                : null,
            'tier' => $license->license->tier,
            'is_primary' => false, // Will be set by caller
        ];
    }
}
