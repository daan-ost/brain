<?php

namespace App\Models\Traits;

use App\Enums\LicenseStatus;

trait HasLicenseStatus
{
    /**
     * Check if this license is currently active
     *
     * A license is active when:
     * - Status is 'active' or 'trial'
     * - Current date is within starts_at and ends_at bounds
     */
    public function isActive(): bool
    {
        $activeStatuses = $this->getActiveStatuses();

        if (! in_array($this->status, $activeStatuses)) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
    }

    /**
     * Get the statuses considered "active" for this model
     */
    protected function getActiveStatuses(): array
    {
        return ['active', 'trial'];
    }

    /**
     * Get the LicenseStatus enum for this model
     */
    public function getStatusEnum(): ?LicenseStatus
    {
        return LicenseStatus::tryFrom($this->status);
    }

    /**
     * Get status badge color using enum
     */
    public function getStatusBadgeColor(): string
    {
        return $this->getStatusEnum()?->badgeColor() ?? 'gray';
    }
}
