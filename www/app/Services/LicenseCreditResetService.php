<?php

namespace App\Services;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LicenseCreditResetService
{
    private LicenseRenewalService $renewalService;

    public function __construct(LicenseRenewalService $renewalService)
    {
        $this->renewalService = $renewalService;
    }

    /**
     * Process free tier reset for a user license
     * Rolling 30 days, only if credits were used
     */
    public function processFreeTierReset(UserLicense $license): bool
    {
        if ($license->license->tier !== 'free') {
            return false;
        }

        if (! $this->shouldResetFreeCredits($license)) {
            return false;
        }

        return DB::transaction(function () use ($license) {
            $user = $license->user;
            $previousBalance = $user->credits ?? 0;
            $newBalance = $license->license->credits;

            // Reset credits
            $user->update([
                'credits' => $newBalance,
                'credits_updated_at' => now(),
            ]);

            // Create ledger entry
            CreditLedger::create([
                'user_id' => $user->id,
                'delta' => $newBalance - $previousBalance,
                'reason' => 'reset_free',
                'balance_after' => $newBalance,
                'meta' => [
                    'license_id' => $license->id,
                    'license_tier' => 'free',
                    'previous_balance' => $previousBalance,
                ],
                'created_at' => now(),
            ]);

            // Update last reset timestamp
            $license->update(['last_credit_reset_at' => now()]);

            Log::info('Free tier credits reset', [
                'user_id' => $user->id,
                'license_id' => $license->id,
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
            ]);

            return true;
        });
    }

    /**
     * Check if free tier credits should be reset
     * Requires: 30 days since last reset AND credits were used
     */
    public function shouldResetFreeCredits(UserLicense $license): bool
    {
        $lastReset = $license->last_credit_reset_at ?? $license->starts_at ?? $license->created_at;
        $nextResetDue = $lastReset->copy()->addDays(30);

        // Not yet time for reset
        if (now()->lt($nextResetDue)) {
            return false;
        }

        // Check if credits were used since last reset
        return CreditLedger::where('user_id', $license->user_id)
            ->where('delta', '<', 0)
            ->where('created_at', '>=', $lastReset)
            ->exists();
    }

    /**
     * Process onetime license expiry
     * Sets credits to 0, status to expired, activates free tier
     */
    public function processOnetimeExpiry(UserLicense $license): bool
    {
        if ($license->license->tier !== 'onetime') {
            return false;
        }

        // Check if license has expired
        if (! $license->ends_at || $license->ends_at->gt(now())) {
            return false;
        }

        // Already expired
        if ($license->status === UserLicense::STATUS_EXPIRED) {
            return false;
        }

        return DB::transaction(function () use ($license) {
            $user = $license->user;
            $previousBalance = $user->credits ?? 0;

            // 1. Expire the license and mark as not current
            $license->update([
                'status' => UserLicense::STATUS_EXPIRED,
                'is_current' => false,
            ]);

            // 2. Set credits to 0
            $user->update([
                'credits' => 0,
                'credits_updated_at' => now(),
            ]);

            // 3. Create ledger entry (only if there were credits)
            if ($previousBalance > 0) {
                CreditLedger::create([
                    'user_id' => $user->id,
                    'delta' => -$previousBalance,
                    'reason' => 'license_expired',
                    'balance_after' => 0,
                    'meta' => [
                        'license_id' => $license->id,
                        'license_tier' => 'onetime',
                        'expired_credits' => $previousBalance,
                    ],
                    'created_at' => now(),
                ]);
            }

            // 4. Activate free tier
            $this->ensureFreeTierLicense($user);

            Log::info('Onetime license expired', [
                'user_id' => $user->id,
                'license_id' => $license->id,
                'expired_credits' => $previousBalance,
            ]);

            return true;
        });
    }

    /**
     * Process premium license reset on anniversary date
     * RESETS premium credits while preserving one-time surplus credits
     *
     * Surplus logic: Credits above the license amount are considered "one-time"
     * purchases and are preserved during reset. This implements LIFO (Last In, First Out)
     * where one-time credits sit "on top" of premium credits.
     */
    public function processPremiumReset(UserLicense $license): bool
    {
        if ($license->license->tier !== 'premium') {
            return false;
        }

        // Only active licenses get reset
        if ($license->status !== UserLicense::STATUS_ACTIVE) {
            return false;
        }

        if (! $this->shouldResetPremiumCredits($license)) {
            return false;
        }

        return DB::transaction(function () use ($license) {
            $user = $license->user;
            $previousBalance = $user->credits ?? 0;
            $licenseCredits = $license->license->credits;

            // Surplus credits (one-time purchases) are preserved
            // Credits above the premium license amount are considered "bonus" credits
            $surplus = max(0, $previousBalance - $licenseCredits);
            $newBalance = $licenseCredits + $surplus;

            // Update credits (reset premium + preserve surplus)
            $user->update([
                'credits' => $newBalance,
                'credits_updated_at' => now(),
            ]);

            // Create ledger entry
            CreditLedger::create([
                'user_id' => $user->id,
                'delta' => $newBalance - $previousBalance,
                'reason' => 'reset_premium',
                'balance_after' => $newBalance,
                'meta' => [
                    'license_id' => $license->id,
                    'license_tier' => 'premium',
                    'previous_balance' => $previousBalance,
                    'reset_to' => $newBalance,
                    'license_credits' => $licenseCredits,
                    'surplus_preserved' => $surplus,
                ],
                'created_at' => now(),
            ]);

            // Update last reset timestamp
            $license->update(['last_credit_reset_at' => now()]);

            Log::info('Premium credits reset', [
                'user_id' => $user->id,
                'license_id' => $license->id,
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'license_credits' => $licenseCredits,
                'surplus_preserved' => $surplus,
            ]);

            return true;
        });
    }

    /**
     * Check if premium credits should be reset
     */
    public function shouldResetPremiumCredits(UserLicense $license): bool
    {
        $resetInterval = $license->license->credit_reset_interval ?? 'monthly';

        // Get next renewal date
        $nextRenewal = $this->renewalService->getNextRenewalDate(
            $license->starts_at,
            $resetInterval
        );

        if (! $nextRenewal) {
            return false;
        }

        // Check if we've passed the renewal date and haven't reset yet
        $lastReset = $license->last_credit_reset_at ?? $license->starts_at;
        $previousRenewal = $this->renewalService->getPreviousRenewalDate(
            $license->starts_at,
            $resetInterval
        );

        // If last reset was before the previous renewal date, we need to reset
        return $lastReset->lt($previousRenewal);
    }

    /**
     * Process canceled premium license expiry
     */
    public function processPremiumCanceledExpiry(UserLicense $license): bool
    {
        if ($license->license->tier !== 'premium') {
            return false;
        }

        if ($license->status !== UserLicense::STATUS_CANCELED) {
            return false;
        }

        // Check if license has expired
        if (! $license->ends_at || $license->ends_at->gt(now())) {
            return false;
        }

        return DB::transaction(function () use ($license) {
            $user = $license->user;
            $previousBalance = $user->credits ?? 0;

            // 1. Mark as expired and not current
            $license->update([
                'status' => UserLicense::STATUS_EXPIRED,
                'is_current' => false,
            ]);

            // 2. Set credits to 0
            $user->update([
                'credits' => 0,
                'credits_updated_at' => now(),
            ]);

            // 3. Create ledger entry
            if ($previousBalance > 0) {
                CreditLedger::create([
                    'user_id' => $user->id,
                    'delta' => -$previousBalance,
                    'reason' => 'license_expired',
                    'balance_after' => 0,
                    'meta' => [
                        'license_id' => $license->id,
                        'license_tier' => 'premium',
                        'status' => 'canceled_expired',
                        'expired_credits' => $previousBalance,
                    ],
                    'created_at' => now(),
                ]);
            }

            // 4. Activate free tier
            $this->ensureFreeTierLicense($user);

            Log::info('Canceled premium license expired', [
                'user_id' => $user->id,
                'license_id' => $license->id,
                'expired_credits' => $previousBalance,
            ]);

            return true;
        });
    }

    /**
     * Ensure user has a free tier license (fallback after expiry)
     */
    public function ensureFreeTierLicense(User $user): UserLicense
    {
        // Check if user already has an active free license
        $existingFreeLicense = $user->userLicenses()
            ->whereHas('license', fn ($q) => $q->where('tier', 'free'))
            ->where('status', UserLicense::STATUS_ACTIVE)
            ->first();

        if ($existingFreeLicense) {
            // Make sure it's marked as current
            if (! $existingFreeLicense->is_current) {
                $existingFreeLicense->update(['is_current' => true]);
            }

            return $existingFreeLicense;
        }

        // Find appropriate free license (prefer EUR, fallback to any)
        $freeLicense = License::where('tier', 'free')
            ->where('active', true)
            ->orderByRaw("CASE WHEN currency = 'EUR' THEN 0 ELSE 1 END")
            ->first();

        if (! $freeLicense) {
            throw new \RuntimeException('No free tier license found in database');
        }

        // Deactivate any other current licenses for this user
        $user->userLicenses()->where('is_current', true)->update(['is_current' => false]);

        // Create new free tier license
        $newLicense = UserLicense::create([
            'user_id' => $user->id,
            'license_id' => $freeLicense->id,
            'status' => UserLicense::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => null, // Free tier never expires
            'source' => 'system',
            'external_ref' => 'fallback_from_expired',
            'is_current' => true,
            'last_credit_reset_at' => now(),
        ]);

        Log::info('Free tier license activated as fallback', [
            'user_id' => $user->id,
            'license_id' => $newLicense->id,
        ]);

        return $newLicense;
    }

    // ==================== ORGANIZATION METHODS ====================

    /**
     * Process organization onetime license expiry
     * Organizations do NOT get free tier fallback
     */
    public function processOrganizationOnetimeExpiry(OrganizationLicense $license): bool
    {
        if ($license->license->tier !== 'onetime') {
            return false;
        }

        if (! $license->ends_at || $license->ends_at->gt(now())) {
            return false;
        }

        if ($license->status === 'expired') {
            return false;
        }

        return DB::transaction(function () use ($license) {
            $organization = $license->organization;
            $creditPool = $organization->creditPool;
            $previousBalance = $creditPool->balance_credits ?? 0;

            // 1. Expire the license
            $license->update([
                'status' => 'expired',
                'is_current' => false,
            ]);

            // 2. Set credits to 0
            if ($creditPool) {
                $creditPool->update(['balance_credits' => 0]);
            }

            // 3. Create ledger entry
            if ($previousBalance > 0) {
                OrganizationCreditLedger::create([
                    'organization_id' => $organization->id,
                    'delta' => -$previousBalance,
                    'reason' => 'license_expired',
                    'balance_after' => 0,
                    'meta' => [
                        'license_id' => $license->id,
                        'license_tier' => 'onetime',
                        'expired_credits' => $previousBalance,
                    ],
                    'created_at' => now(),
                ]);
            }

            // Note: Organizations do NOT get free tier fallback

            Log::info('Organization onetime license expired', [
                'organization_id' => $organization->id,
                'license_id' => $license->id,
                'expired_credits' => $previousBalance,
            ]);

            return true;
        });
    }

    /**
     * Process organization premium canceled expiry
     * Sets credits to 0, status to expired
     */
    public function processOrganizationPremiumCanceledExpiry(OrganizationLicense $license): bool
    {
        if ($license->license->tier !== 'premium') {
            return false;
        }

        if ($license->status !== 'canceled') {
            return false;
        }

        // Check if license has expired
        if (! $license->ends_at || $license->ends_at->gt(now())) {
            return false;
        }

        return DB::transaction(function () use ($license) {
            $organization = $license->organization;
            $creditPool = $organization->creditPool;
            $previousBalance = $creditPool->balance_credits ?? 0;

            // 1. Mark as expired
            $license->update([
                'status' => 'expired',
                'is_current' => false,
            ]);

            // 2. Set credits to 0
            if ($creditPool) {
                $creditPool->update(['balance_credits' => 0]);
            }

            // 3. Create ledger entry
            if ($previousBalance > 0) {
                OrganizationCreditLedger::create([
                    'organization_id' => $organization->id,
                    'delta' => -$previousBalance,
                    'reason' => 'license_expired',
                    'balance_after' => 0,
                    'meta' => [
                        'license_id' => $license->id,
                        'license_tier' => 'premium',
                        'status' => 'canceled_expired',
                        'expired_credits' => $previousBalance,
                    ],
                    'created_at' => now(),
                ]);
            }

            // Note: Organizations do NOT get free tier fallback

            Log::info('Organization canceled premium license expired', [
                'organization_id' => $organization->id,
                'license_id' => $license->id,
                'expired_credits' => $previousBalance,
            ]);

            return true;
        });
    }

    /**
     * Process organization premium reset
     * RESETS premium credits while preserving one-time surplus credits
     *
     * Surplus logic: Credits above the license amount are considered "one-time"
     * purchases and are preserved during reset. This implements LIFO (Last In, First Out)
     * where one-time credits sit "on top" of premium credits.
     */
    public function processOrganizationPremiumReset(OrganizationLicense $license): bool
    {
        if ($license->license->tier !== 'premium') {
            return false;
        }

        if ($license->status !== 'active') {
            return false;
        }

        $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
        $lastReset = $license->last_credit_reset_at ?? $license->starts_at;
        $previousRenewal = $this->renewalService->getPreviousRenewalDate(
            $license->starts_at,
            $resetInterval
        );

        if ($lastReset->gte($previousRenewal)) {
            return false;
        }

        return DB::transaction(function () use ($license) {
            $organization = $license->organization;
            $creditPool = $organization->creditPool;
            $previousBalance = $creditPool->balance_credits ?? 0;
            $licenseCredits = $license->license->credits;

            // Surplus credits (one-time purchases) are preserved
            // Credits above the premium license amount are considered "bonus" credits
            $surplus = max(0, $previousBalance - $licenseCredits);
            $newBalance = $licenseCredits + $surplus;

            // Update credits (reset premium + preserve surplus)
            $organization->creditPool()->updateOrCreate(
                ['organization_id' => $organization->id],
                ['balance_credits' => $newBalance, 'updated_at' => now()]
            );

            // Create ledger entry
            OrganizationCreditLedger::create([
                'organization_id' => $organization->id,
                'delta' => $newBalance - $previousBalance,
                'reason' => 'reset_premium',
                'balance_after' => $newBalance,
                'meta' => [
                    'license_id' => $license->id,
                    'license_tier' => 'premium',
                    'previous_balance' => $previousBalance,
                    'reset_to' => $newBalance,
                    'license_credits' => $licenseCredits,
                    'surplus_preserved' => $surplus,
                ],
                'created_at' => now(),
            ]);

            $license->update(['last_credit_reset_at' => now()]);

            Log::info('Organization premium credits reset', [
                'organization_id' => $organization->id,
                'license_id' => $license->id,
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'license_credits' => $licenseCredits,
                'surplus_preserved' => $surplus,
            ]);

            return true;
        });
    }

    /**
     * Get credits used in a period
     */
    public function getCreditsUsedInPeriod(int $userId, Carbon $from, Carbon $to): int
    {
        return abs(CreditLedger::where('user_id', $userId)
            ->where('delta', '<', 0)
            ->whereBetween('created_at', [$from, $to])
            ->sum('delta'));
    }
}
