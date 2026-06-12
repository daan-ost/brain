<?php

namespace App\Services;

use App\Models\CreditLedger;
use App\Models\User;

class BalanceService
{
    /**
     * Calculate user's current balance from credit ledger
     * Falls back to user.credits column if no ledger entries exist
     */
    public function calculateCurrentBalance(int $userId): int
    {
        $lastEntry = CreditLedger::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($lastEntry) {
            return $lastEntry->balance_after;
        }

        // No ledger entries - fall back to user's credits column
        $user = User::find($userId);

        return $user?->credits ?? 0;
    }

    /**
     * Calculate balance after adding a delta to current balance
     * Ensures balance never goes negative
     */
    public function calculateBalanceAfter(int $userId, int $delta): int
    {
        $currentBalance = $this->calculateCurrentBalance($userId);
        $newBalance = $currentBalance + $delta;

        // Clamp so balance never goes negative
        return max(0, $newBalance);
    }

    /**
     * Update user's cached balance in the users table
     */
    public function updateCachedBalance(User $user, int $newBalance, ?\Carbon\Carbon $when = null): void
    {
        $user->update([
            'credits' => $newBalance,
            'credits_updated_at' => $when ?? now(),
        ]);
    }

    /**
     * Calculate organization's current credit balance from credit pool
     */
    public function calculateOrganizationBalance(int $organizationId): int
    {
        $creditPool = \App\Models\OrganizationCreditPool::where('organization_id', $organizationId)->first();

        return $creditPool?->balance_credits ?? 0;
    }
}
