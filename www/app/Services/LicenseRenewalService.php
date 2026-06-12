<?php

namespace App\Services;

use App\Enums\LicenseStatus;
use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Models\Order;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use App\Services\Payments\StripeSubscriptionService;
use Carbon\Carbon;

class LicenseRenewalService
{
    public function __construct(
        private ?MollieSubscriptionService $mollieService = null,
        private ?StripeSubscriptionService $stripeSubscriptionService = null,
    ) {}

    /**
     * Calculate the next renewal date for a license
     *
     * This method calculates the next future renewal date by repeatedly adding
     * the reset interval until we reach a date in the future.
     *
     * IMPORTANT: Uses NoOverflow methods to handle edge cases like:
     * - Feb 29, 2024 + 1 year = Feb 28, 2025 (not Mar 1)
     * - Jan 31 + 1 month = Feb 28/29 (not Mar 2/3)
     *
     * @param  Carbon  $startsAt  The license start date
     * @param  string  $resetInterval  The interval (e.g., 'monthly', 'yearly', '6month')
     * @param  Carbon|null  $fromDate  Calculate from this date (defaults to now)
     * @return Carbon|null The next renewal date, or null if interval is invalid
     *
     * @example
     * License started: 2025-08-22
     * Reset interval: monthly
     * Today: 2025-10-18
     * Result: 2025-11-22 (not 2025-09-22)
     */
    public function getNextRenewalDate(Carbon $startsAt, string $resetInterval, ?Carbon $fromDate = null): ?Carbon
    {
        $fromDate = $fromDate ?? now();

        // Validate interval
        if (! $this->isValidInterval($resetInterval)) {
            return null;
        }

        // Start with the license start date
        $nextRenewal = $startsAt->copy();

        // Keep adding intervals until we're in the future
        $maxIterations = 1000; // Safety limit to prevent infinite loops
        $iteration = 0;

        while ($nextRenewal <= $fromDate && $iteration < $maxIterations) {
            $nextRenewal = $this->addIntervalNoOverflow($nextRenewal, $resetInterval);
            $iteration++;
        }

        return $nextRenewal;
    }

    /**
     * Check if interval is valid
     */
    private function isValidInterval(string $interval): bool
    {
        return in_array($interval, ['daily', 'weekly', 'monthly', '6month', 'yearly']);
    }

    /**
     * Add interval to date without overflow (handles leap years and month-end edge cases)
     *
     * Examples:
     * - Feb 29, 2024 + 1 year = Feb 28, 2025 (not Mar 1, 2025)
     * - Jan 31 + 1 month = Feb 28/29 (not Mar 2/3)
     * - Aug 31 + 6 months = Feb 28/29 (not Mar 2/3)
     */
    private function addIntervalNoOverflow(Carbon $date, string $interval): Carbon
    {
        return match ($interval) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'monthly' => $date->copy()->addMonthNoOverflow(),
            '6month' => $date->copy()->addMonthsNoOverflow(6),
            'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()
        };
    }

    /**
     * Subtract interval from date without overflow
     */
    private function subIntervalNoOverflow(Carbon $date, string $interval): Carbon
    {
        return match ($interval) {
            'daily' => $date->copy()->subDay(),
            'weekly' => $date->copy()->subWeek(),
            'monthly' => $date->copy()->subMonthNoOverflow(),
            '6month' => $date->copy()->subMonthsNoOverflow(6),
            'yearly' => $date->copy()->subYearNoOverflow(),
            default => $date->copy()
        };
    }

    /**
     * Calculate all renewal dates between two dates
     *
     * Useful for generating a history of renewal dates or calculating
     * how many renewal cycles have occurred.
     *
     * @param  Carbon  $startsAt  The license start date
     * @param  string  $resetInterval  The interval (e.g., 'monthly', 'yearly', '6month')
     * @param  Carbon  $fromDate  Start of date range
     * @param  Carbon  $toDate  End of date range
     * @return array Array of Carbon dates
     */
    public function getRenewalDatesBetween(Carbon $startsAt, string $resetInterval, Carbon $fromDate, Carbon $toDate): array
    {
        $renewalDates = [];

        if (! $this->isValidInterval($resetInterval)) {
            return [];
        }

        $currentDate = $startsAt->copy();
        $maxIterations = 1000;
        $iteration = 0;

        // Fast forward to the first date within range
        while ($currentDate < $fromDate && $iteration < $maxIterations) {
            $currentDate = $this->addIntervalNoOverflow($currentDate, $resetInterval);
            $iteration++;
        }

        // Collect all dates within range
        while ($currentDate <= $toDate && $iteration < $maxIterations) {
            $renewalDates[] = $currentDate->copy();
            $currentDate = $this->addIntervalNoOverflow($currentDate, $resetInterval);
            $iteration++;
        }

        return $renewalDates;
    }

    /**
     * Get the number of renewal cycles that have occurred
     *
     * @param  Carbon  $startsAt  The license start date
     * @param  string  $resetInterval  The interval (e.g., 'monthly', 'yearly', '6month')
     * @param  Carbon|null  $toDate  Calculate up to this date (defaults to now)
     * @return int Number of complete renewal cycles
     */
    public function getRenewalCycleCount(Carbon $startsAt, string $resetInterval, ?Carbon $toDate = null): int
    {
        $toDate = $toDate ?? now();

        if ($startsAt >= $toDate) {
            return 0;
        }

        if (! $this->isValidInterval($resetInterval)) {
            return 0;
        }

        $count = 0;
        $currentDate = $startsAt->copy();
        $maxIterations = 1000;

        while ($currentDate <= $toDate && $count < $maxIterations) {
            $currentDate = $this->addIntervalNoOverflow($currentDate, $resetInterval);
            $count++;
        }

        // We counted one too many (the next future renewal)
        return max(0, $count - 1);
    }

    /**
     * Check if today is a renewal date
     *
     * @param  Carbon  $startsAt  The license start date
     * @param  string  $resetInterval  The interval
     * @return bool True if today is a renewal date
     */
    public function isRenewalDateToday(Carbon $startsAt, string $resetInterval): bool
    {
        $nextRenewal = $this->getNextRenewalDate($startsAt, $resetInterval);

        if (! $nextRenewal) {
            return false;
        }

        return $nextRenewal->isToday();
    }

    /**
     * Get the previous renewal date (last time credits were reset)
     *
     * @param  Carbon  $startsAt  The license start date
     * @param  string  $resetInterval  The interval (e.g., 'monthly', 'yearly', '6month')
     * @param  Carbon|null  $fromDate  Calculate from this date (defaults to now)
     * @return Carbon The previous renewal date
     */
    public function getPreviousRenewalDate(Carbon $startsAt, string $resetInterval, ?Carbon $fromDate = null): Carbon
    {
        $fromDate = $fromDate ?? now();

        // If we haven't reached the first renewal yet, return the start date
        if ($startsAt >= $fromDate) {
            return $startsAt;
        }

        $nextRenewal = $this->getNextRenewalDate($startsAt, $resetInterval, $fromDate);

        if (! $nextRenewal) {
            return $startsAt;
        }

        if (! $this->isValidInterval($resetInterval)) {
            return $startsAt;
        }

        // Go back one interval from the next renewal
        return $this->subIntervalNoOverflow($nextRenewal, $resetInterval);
    }

    /**
     * Find a license by ID for a user (checks both user and organization licenses)
     *
     * @return array{license: UserLicense|OrganizationLicense|null, type: string}
     */
    public function findLicenseForUser(User $user, int $licenseId): array
    {
        // Try to find as UserLicense first
        $license = $user->userLicenses()->find($licenseId);
        if ($license) {
            return ['license' => $license, 'type' => 'user'];
        }

        // Check organizational licenses
        foreach ($user->organizations as $organization) {
            $orgLicense = $organization->organizationLicenses()->find($licenseId);
            if ($orgLicense) {
                // Verify user is admin
                if ($organization->pivot->role !== OrganizationRole::Owner) {
                    return ['license' => null, 'type' => 'organization', 'error' => 'not_admin'];
                }

                return ['license' => $orgLicense, 'type' => 'organization'];
            }
        }

        return ['license' => null, 'type' => 'unknown'];
    }

    /**
     * Validate that a license can be canceled
     *
     * @return array{valid: bool, error: string|null}
     */
    public function validateCancellation(UserLicense|OrganizationLicense $license): array
    {
        // Free licenses cannot be canceled
        if ($license->license->tier === 'free') {
            return ['valid' => false, 'error' => __('profile.free_license_cancel_error')];
        }

        // Only recurring subscriptions can be canceled
        if (! in_array($license->license->billing_cycle, ['monthly', 'yearly'])) {
            return ['valid' => false, 'error' => __('profile.not_recurring_error')];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Cancel a license renewal
     *
     * @return array{success: bool, renewal_date: Carbon|null, error: string|null}
     */
    public function cancelRenewal(UserLicense|OrganizationLicense $license, string $licenseType): array
    {
        // Calculate end date based on billing_cycle (payment frequency), NOT credit_reset_interval.
        // Why: yearly Premium plans (€60/jaar) have monthly credit resets — using
        // credit_reset_interval here causes ends_at to be set 1 month after start instead of 1 year,
        // cutting off paid subscription time. See incident 2026-05 / PDFengine user 241636.
        $billingCycle = $license->license->billing_cycle ?? $license->license->credit_reset_interval;
        $renewalDate = $this->getNextRenewalDate($license->starts_at, $billingCycle);

        if (! $renewalDate) {
            return ['success' => false, 'renewal_date' => null, 'error' => __('profile.renewal_date_error')];
        }

        // Cancel subscription via the appropriate provider
        if (($license->payment_provider ?? 'mollie') === 'stripe') {
            $this->cancelStripeSubscription($license);
        } else {
            $this->cancelMollieSubscription($license, $licenseType);
        }

        $license->update([
            'status' => LicenseStatus::Canceled->value,
            'ends_at' => $renewalDate,
        ]);

        return ['success' => true, 'renewal_date' => $renewalDate, 'error' => null];
    }

    /**
     * Cancel the Mollie subscription associated with a license
     */
    private function cancelMollieSubscription(UserLicense|OrganizationLicense $license, string $licenseType): void
    {
        if ($license->source !== 'mollie' || ! $license->external_ref) {
            return;
        }

        $payerId = $licenseType === 'user' ? $license->user_id : $license->organization_id;

        $order = Order::where('payer_type', $licenseType)
            ->where('payer_id', $payerId)
            ->where('license_id', $license->license_id)
            ->where('status', OrderStatus::Paid->value)
            ->whereNotNull('mollie_subscription_id')
            ->latest()
            ->first();

        if (! $order || ! $order->mollie_customer_id || ! $order->mollie_subscription_id) {
            return;
        }

        if (! $this->mollieService) {
            $this->mollieService = app(MollieSubscriptionService::class);
        }

        $result = $this->mollieService->cancelSubscription(
            $order->mollie_customer_id,
            $order->mollie_subscription_id
        );

        if (! $result['success']) {
            \Log::warning('Failed to cancel Mollie subscription', [
                'license_id' => $license->id,
                'customer_id' => $order->mollie_customer_id,
                'subscription_id' => $order->mollie_subscription_id,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
        }
    }

    private function cancelStripeSubscription(UserLicense|OrganizationLicense $license): void
    {
        $subscriptionId = $license->provider_subscription_id ?? null;

        if (! $subscriptionId) {
            return;
        }

        try {
            ($this->stripeSubscriptionService ?? app(StripeSubscriptionService::class))->cancel($subscriptionId);
        } catch (\Throwable $e) {
            \Log::warning('Failed to cancel Stripe subscription', [
                'license_id' => $license->id,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
