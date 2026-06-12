<?php

namespace App\Services\Licenses;

use App\Models\AnalyticsEvent;
use App\Models\CreditLedger;
use App\Models\UserLicense;
use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LicenseAdminService
{
    public function __construct(
        private BalanceService $balanceService
    ) {}

    /**
     * Close (expire or cancel) a one-time user license
     *
     * @param  string  $action  'expire' or 'cancel'
     *
     * @throws InvalidArgumentException
     */
    public function closeOnetimeUserLicense(
        int $userLicenseId,
        string $action,
        int $adminId,
        ?Carbon $when = null
    ): CloseOnetimeResult {
        if (! in_array($action, ['expire', 'cancel'])) {
            throw new InvalidArgumentException("Action must be 'expire' or 'cancel'");
        }

        $when = $when ?? now();

        return DB::transaction(function () use ($userLicenseId, $action, $adminId, $when) {
            // Load the UserLicense row for update
            $userLicense = UserLicense::lockForUpdate()
                ->with(['license', 'user'])
                ->find($userLicenseId);

            if (! $userLicense) {
                throw new InvalidArgumentException("UserLicense not found: {$userLicenseId}");
            }

            // Guard: Check if already not active or not current
            if ($userLicense->status !== 'active' || ! $userLicense->is_current) {
                return new CloseOnetimeResult(
                    changed: false,
                    status: $userLicense->status,
                    remainingAdjusted: 0,
                    newBalance: $this->balanceService->calculateCurrentBalance($userLicense->user_id),
                    endedAt: $userLicense->ends_at
                );
            }

            // Guard: Check if license is onetime
            if (! $userLicense->isOnetime()) {
                throw new InvalidArgumentException(
                    "Only onetime licenses are supported by this action. Found tier: {$userLicense->license->tier}"
                );
            }

            // Close assignment
            $newStatus = $action === 'expire' ? 'expired' : 'canceled';
            $newEndsAt = $when;

            // If ends_at is null or greater than when, set it to when
            if ($userLicense->ends_at === null || $userLicense->ends_at->gt($when)) {
                $userLicense->ends_at = $newEndsAt;
            } else {
                $newEndsAt = $userLicense->ends_at;
            }

            $userLicense->update([
                'status' => $newStatus,
                'is_current' => false,
                'ends_at' => $newEndsAt,
            ]);

            // Compute remaining for this assignment
            $remaining = $this->calculateRemainingCredits($userLicenseId);

            // Get current cached balance from user (not from ledger)
            $userLicense->user->refresh();
            $currentBalance = $userLicense->user->credits;

            // Calculate new balance: current - remaining (clamped to 0)
            $newBalance = max(0, $currentBalance - $remaining);

            // Ledger adjust (if needed)
            if ($remaining > 0) {

                CreditLedger::create([
                    'user_id' => $userLicense->user_id,
                    'delta' => -$remaining,
                    'reason' => 'adjust',
                    'balance_after' => $newBalance,
                    'meta' => [
                        'action' => 'license_admin_close',
                        'user_license_id' => $userLicenseId,
                        'origin' => 'admin',
                        'admin_id' => $adminId,
                    ],
                    'created_at' => $when,
                ]);
            }

            // Update cached user balance
            $this->balanceService->updateCachedBalance($userLicense->user, $newBalance, $when);

            // Analytics
            $eventName = $action === 'expire' ? 'license_expired_admin' : 'license_canceled_admin';

            AnalyticsEvent::create([
                'user_id' => $userLicense->user_id,
                'event' => $eventName,
                'meta' => [
                    'user_license_id' => $userLicenseId,
                    'tier' => 'onetime',
                    'previous_status' => 'active',
                    'new_status' => $newStatus,
                    'remaining_adjusted' => $remaining,
                    'admin_id' => $adminId,
                    'at' => $when->toISOString(),
                ],
                'created_at' => $when,
            ]);

            return new CloseOnetimeResult(
                changed: true,
                status: $newStatus,
                remainingAdjusted: $remaining,
                newBalance: $newBalance,
                endedAt: $newEndsAt
            );
        });
    }

    /**
     * Calculate remaining credits for a user license assignment
     */
    private function calculateRemainingCredits(int $userLicenseId): int
    {
        // Calculate purchased credits (purchase, refund, adjust)
        $purchased = CreditLedger::byLicenseAssignmentId($userLicenseId)
            ->purchaseReasons()
            ->sum('delta');

        // Calculate spent credits (spend reason, using negative delta)
        $spent = CreditLedger::byLicenseAssignmentId($userLicenseId)
            ->spendReason()
            ->sum(DB::raw('-delta')); // Convert negative delta to positive

        $remaining = max($purchased - $spent, 0);

        return $remaining;
    }
}
