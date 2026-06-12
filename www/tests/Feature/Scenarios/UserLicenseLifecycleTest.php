<?php

/**
 * User License Lifecycle Tests
 *
 * Comprehensive tests for the complete user license lifecycle:
 * - New license purchase (onetime, premium)
 * - Credit reset on renewal (premium/free tier)
 * - License expiration
 * - Free tier fallback after onetime exhausted
 * - 24-hour waiting period
 */

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Tests\Helpers\assertCreditLedgerEntryComplete;
use function Tests\Helpers\assertUserHasCredits;
use function Tests\Helpers\assertUserLicenseIsActive;
use function Tests\Helpers\assertUserLicenseIsExpired;

// ==================== SETUP ====================

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
});

afterEach(function () {
    Carbon::setTestNow();
});

// ==================== HELPER FUNCTIONS ====================

function createVerifiedUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
        'credits' => 0,
    ], $attributes));
}

function createFreeTierLicense(): License
{
    return License::factory()->create([
        'tier' => 'free',
        'credits' => 15,
        'credit_reset_interval' => 'daily',
        'active' => true,
        'currency' => 'EUR',
    ]);
}

function createOnetimeUserLicense(int $credits = 200): License
{
    return License::factory()->create([
        'tier' => 'onetime',
        'credits' => $credits,
        'credit_reset_interval' => 'none',
        'period' => 90, // 90 days validity
        'active' => true,
        'currency' => 'EUR',
    ]);
}

function createPremiumUserLicense(int $credits = 200): License
{
    return License::factory()->create([
        'tier' => 'premium',
        'credits' => $credits,
        'credit_reset_interval' => 'monthly',
        'period' => 30,
        'billing_cycle' => 'monthly',
        'active' => true,
        'currency' => 'EUR',
    ]);
}

function createUserLicense(User $user, License $license, array $attributes = []): UserLicense
{
    return UserLicense::create(array_merge([
        'user_id' => $user->id,
        'license_id' => $license->id,
        'status' => UserLicense::STATUS_ACTIVE,
        'starts_at' => now(),
        'ends_at' => null,
        'source' => 'test',
        'external_ref' => 'test-'.uniqid(),
        'is_current' => true,
    ], $attributes));
}

// ==================== GROUP 1: LICENSE PURCHASE ====================

describe('User License Purchase', function () {

    it('assigns onetime license and credits to user', function () {
        $user = createVerifiedUser(['credits' => 0]);
        $license = createOnetimeUserLicense(200);

        // Simulate purchase completion
        $userLicense = createUserLicense($user, $license, [
            'ends_at' => now()->addDays(90),
        ]);

        // Add credits
        $user->increment('credits', $license->credits);
        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => $license->credits,
            'balance_after' => $license->credits,
            'reason' => 'purchase',
        ]);

        // Verify
        assertUserLicenseIsActive($userLicense);
        assertUserHasCredits($user, 200);

        $ledger = CreditLedger::where('user_id', $user->id)->first();
        assertCreditLedgerEntryComplete($ledger);
        expect($ledger->reason)->toBe('purchase');
        expect($ledger->delta)->toBe(200);
    });

    it('assigns premium license with recurring credits', function () {
        $user = createVerifiedUser(['credits' => 0]);
        $license = createPremiumUserLicense(500);

        $userLicense = createUserLicense($user, $license);

        // Add credits
        $user->increment('credits', $license->credits);

        // Verify
        assertUserLicenseIsActive($userLicense);
        assertUserHasCredits($user, 500);
    });

});

// ==================== GROUP 2: CREDIT RESET ====================

describe('User Credit Reset on Renewal', function () {

    it('resets premium credits on monthly renewal date', function () {
        $user = createVerifiedUser(['credits' => 50]);
        $license = createPremiumUserLicense(200);

        // License started 35 days ago
        createUserLicense($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should be reset to license amount
        expect($user->fresh()->credits)->toBe(200);
    });

    it('does not reset premium credits if recently reset', function () {
        $user = createVerifiedUser(['credits' => 75]);
        $license = createPremiumUserLicense(200);

        // Last reset only 2 days ago (after previous renewal date ~5 days ago)
        createUserLicense($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(2),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits unchanged
        expect($user->fresh()->credits)->toBe(75);
    });

    it('resets free tier credits when used', function () {
        $license = createFreeTierLicense();
        $user = createVerifiedUser(['credits' => 3]);

        createUserLicense($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        // Simulate usage
        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => -12,
            'reason' => 'spend',
            'balance_after' => 3,
            'created_at' => now()->subDays(15),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits reset to free tier amount
        expect($user->fresh()->credits)->toBe(15);
    });

    it('does not reset free tier when no usage', function () {
        $license = createFreeTierLicense();
        $user = createVerifiedUser(['credits' => 15]);

        createUserLicense($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        // No usage entries

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits unchanged (no reset without usage)
        expect($user->fresh()->credits)->toBe(15);
    });

});

// ==================== GROUP 3: LICENSE EXPIRATION ====================

describe('User License Expiration', function () {

    it('expires onetime license and clears credits', function () {
        createFreeTierLicense(); // For fallback
        $license = createOnetimeUserLicense(200);
        $user = createVerifiedUser(['credits' => 100]);

        $userLicense = createUserLicense($user, $license, [
            'ends_at' => now()->subDay(), // Expired yesterday
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // License expired, credits cleared
        assertUserLicenseIsExpired($userLicense->fresh());
        assertUserHasCredits($user, 0);
    });

    it('expires canceled premium license when ends_at passes', function () {
        createFreeTierLicense(); // For fallback
        $license = createPremiumUserLicense(200);
        $user = createVerifiedUser(['credits' => 75]);

        $userLicense = createUserLicense($user, $license, [
            'status' => UserLicense::STATUS_CANCELED,
            'ends_at' => now()->subDay(), // Expired yesterday
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // License expired, credits cleared
        assertUserLicenseIsExpired($userLicense->fresh());
        assertUserHasCredits($user, 0);
    });

    it('does not expire canceled license before ends_at', function () {
        $license = createPremiumUserLicense(200);
        $user = createVerifiedUser(['credits' => 150]);

        $userLicense = createUserLicense($user, $license, [
            'status' => UserLicense::STATUS_CANCELED,
            'ends_at' => now()->addDays(10), // Still valid
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // License still canceled (not expired), credits unchanged
        expect($userLicense->fresh()->status)->toBe(UserLicense::STATUS_CANCELED);
        expect($user->fresh()->credits)->toBe(150);
    });

    it('does not expire active license with future ends_at', function () {
        $license = createOnetimeUserLicense(200);
        $user = createVerifiedUser(['credits' => 180]);

        $userLicense = createUserLicense($user, $license, [
            'ends_at' => now()->addDays(30), // Still valid
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // No changes
        expect($userLicense->fresh()->status)->toBe(UserLicense::STATUS_ACTIVE);
        expect($user->fresh()->credits)->toBe(180);
    });

});

// ==================== GROUP 4: FREE TIER FALLBACK ====================

describe('Free Tier Fallback', function () {

    it('activates free tier credits after onetime exhausted and 24h waiting period', function () {
        $freeLicense = createFreeTierLicense();
        $onetimeLicense = createOnetimeUserLicense(100);

        // User exhausted credits 25 hours ago
        $user = createVerifiedUser([
            'credits' => 0,
            'credits_exhausted_at' => now()->subHours(25),
        ]);

        // Active onetime license (not expired, just 0 credits)
        createUserLicense($user, $onetimeLicense, [
            'ends_at' => now()->addDays(60),
            'is_current' => true,
        ]);

        // Free tier license available
        createUserLicense($user, $freeLicense, [
            'is_current' => false,
        ]);

        // Get payment source should trigger fallback
        $creditsService = app(\App\Services\CreditsService::class);
        $paymentSource = $creditsService->getPaymentSource($user);

        // Should have free tier credits now
        expect($paymentSource['balance'])->toBe(15);
        expect($user->fresh()->credits)->toBe(15);

        // Ledger entry exists
        $ledger = CreditLedger::where('user_id', $user->id)
            ->where('reason', 'free_tier_fallback')
            ->first();
        expect($ledger)->not->toBeNull();
    });

    it('blocks free tier during 24-hour waiting period', function () {
        $freeLicense = createFreeTierLicense();
        $onetimeLicense = createOnetimeUserLicense(100);

        // User exhausted credits only 12 hours ago
        $user = createVerifiedUser([
            'credits' => 0,
            'credits_exhausted_at' => now()->subHours(12),
        ]);

        createUserLicense($user, $onetimeLicense, [
            'ends_at' => now()->addDays(60),
            'is_current' => true,
        ]);

        createUserLicense($user, $freeLicense, [
            'is_current' => false,
        ]);

        $creditsService = app(\App\Services\CreditsService::class);
        $paymentSource = $creditsService->getPaymentSource($user);

        // Should still have 0 credits (waiting period)
        expect($paymentSource['balance'])->toBe(0);
        expect($user->fresh()->credits)->toBe(0);
    });

    it('does not activate free tier for premium license with 0 credits', function () {
        $freeLicense = createFreeTierLicense();
        $premiumLicense = createPremiumUserLicense(200);

        $user = createVerifiedUser([
            'credits' => 0,
            'credits_exhausted_at' => now()->subHours(25),
        ]);

        // Premium license with 0 credits (not onetime)
        createUserLicense($user, $premiumLicense, [
            'ends_at' => now()->addDays(15),
            'is_current' => true,
        ]);

        createUserLicense($user, $freeLicense, [
            'is_current' => false,
        ]);

        $creditsService = app(\App\Services\CreditsService::class);
        $paymentSource = $creditsService->getPaymentSource($user);

        // Premium users must wait for monthly reset, no fallback
        expect($paymentSource['balance'])->toBe(0);
        expect($user->fresh()->credits)->toBe(0);
    });

});

// ==================== GROUP 5: EDGE CASES ====================

describe('User License Edge Cases', function () {

    it('dry run mode does not change user credits', function () {
        $license = createOnetimeUserLicense(200);
        $user = createVerifiedUser(['credits' => 100]);

        createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
        ]);

        $this->artisan('license:process-credits', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run mode');

        // Credits unchanged
        expect($user->fresh()->credits)->toBe(100);
    });

    it('handles user with multiple licenses correctly', function () {
        $freeLicense = createFreeTierLicense();
        $premiumLicense = createPremiumUserLicense(500);

        $user = createVerifiedUser(['credits' => 50]);

        // Old expired license
        createUserLicense($user, $freeLicense, [
            'status' => UserLicense::STATUS_EXPIRED,
            'is_current' => false,
        ]);

        // Current active premium license
        createUserLicense($user, $premiumLicense, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'is_current' => true,
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits reset for current premium license
        expect($user->fresh()->credits)->toBe(500);
    });

    it('outputs statistics table', function () {
        $this->artisan('license:process-credits')
            ->assertSuccessful()
            ->expectsOutputToContain('Free tier resets')
            ->expectsOutputToContain('Onetime expired')
            ->expectsOutputToContain('Premium resets');
    });

});

// ==================== GROUP 6: PREMIUM + ONE-TIME CREDITS COMBINATION ====================

describe('Premium License with One-Time Credit Purchase', function () {

    it('preserves surplus one-time credits during premium reset', function () {
        $premiumLicense = createPremiumUserLicense(200);

        // User has 300 credits: 200 (premium) + 100 (one-time purchase)
        $user = createVerifiedUser(['credits' => 300]);

        createUserLicense($user, $premiumLicense, [
            'starts_at' => now()->subDays(35), // Started 35 days ago
            'last_credit_reset_at' => now()->subDays(35),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should be 300: 200 (fresh premium) + 100 (preserved surplus)
        expect($user->fresh()->credits)->toBe(300);

        // Verify ledger entry tracks surplus
        $ledger = CreditLedger::where('user_id', $user->id)
            ->where('reason', 'reset_premium')
            ->first();
        expect($ledger)->not->toBeNull();
        expect($ledger->meta['surplus_preserved'])->toBe(100);
    });

    it('resets premium credits while preserving partial surplus after usage', function () {
        $premiumLicense = createPremiumUserLicense(200);

        // User had 300 (200 premium + 100 one-time), used 50, now has 250
        $user = createVerifiedUser(['credits' => 250]);

        createUserLicense($user, $premiumLicense, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should be 250: 200 (fresh premium) + 50 (remaining surplus)
        expect($user->fresh()->credits)->toBe(250);
    });

    it('does not create surplus when user has fewer credits than license amount', function () {
        $premiumLicense = createPremiumUserLicense(200);

        // User used most of their premium credits, has only 50 left
        $user = createVerifiedUser(['credits' => 50]);

        createUserLicense($user, $premiumLicense, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should be reset to 200 (no surplus since 50 < 200)
        expect($user->fresh()->credits)->toBe(200);

        // Verify ledger entry shows 0 surplus
        $ledger = CreditLedger::where('user_id', $user->id)
            ->where('reason', 'reset_premium')
            ->first();
        expect($ledger)->not->toBeNull();
        expect($ledger->meta['surplus_preserved'])->toBe(0);
    });

    it('completely depletes one-time credits before premium reset restores them', function () {
        $premiumLicense = createPremiumUserLicense(200);

        // User bought 100 one-time, used all 300 (200 premium + 100 one-time), now at 0
        $user = createVerifiedUser(['credits' => 0]);

        createUserLicense($user, $premiumLicense, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should be reset to 200 (no surplus since 0 < 200)
        expect($user->fresh()->credits)->toBe(200);
    });

});
