<?php

namespace App\Services;

use App\Models\OrganizationDomain;
use App\Models\User;
use Carbon\Carbon;

class RetentionService
{
    /**
     * Get the retention period in days for a user.
     * Uses the maximum retention from all user's organizations, or falls back to default.
     */
    public function getRetentionDaysForUser(?User $user): int
    {
        if (! $user) {
            return $this->getDefaultRetentionDays();
        }

        // Get max retention from all organizations the user belongs to
        $maxOrganizationRetention = $this->getMaxOrganizationRetention($user);

        if ($maxOrganizationRetention !== null) {
            return $maxOrganizationRetention;
        }

        return $this->getDefaultRetentionDays();
    }

    /**
     * Get the maximum retention days from all organizations the user belongs to.
     */
    protected function getMaxOrganizationRetention(User $user): ?int
    {
        $organizationIds = $user->organizations()->pluck('organizations.id');

        if ($organizationIds->isEmpty()) {
            return null;
        }

        $maxRetention = OrganizationDomain::whereIn('organization_id', $organizationIds)
            ->whereNotNull('max_storage_days')
            ->max('max_storage_days');

        return $maxRetention;
    }

    /**
     * Get the default retention days from config.
     */
    public function getDefaultRetentionDays(): int
    {
        return (int) config('cleanup.default_retention_days', 14);
    }

    /**
     * Get the cutoff date for deletion based on retention days.
     */
    public function getCutoffDateForUser(?User $user): Carbon
    {
        $retentionDays = $this->getRetentionDaysForUser($user);

        return now()->subDays($retentionDays);
    }

    /**
     * Get the cutoff date for uploads (always 1 hour).
     */
    public function getUploadCutoffDate(): Carbon
    {
        $minutes = config('cleanup.upload_retention_minutes', 60);

        return now()->subMinutes($minutes);
    }

    /**
     * Get the cutoff date for temp files.
     */
    public function getTempCutoffDate(): Carbon
    {
        $hours = config('cleanup.temp_hours', 1);

        return now()->subHours($hours);
    }

    /**
     * Get the cutoff date for workflow temp files.
     */
    public function getWorkflowTempCutoffDate(): Carbon
    {
        $hours = config('cleanup.workflow_temp_hours', 24);

        return now()->subHours($hours);
    }

    /**
     * Get the cutoff date for failed conversions.
     */
    public function getFailedConversionCutoffDate(): Carbon
    {
        $days = config('cleanup.failed_conversion_days', 7);

        return now()->subDays($days);
    }
}
