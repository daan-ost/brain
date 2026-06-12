<?php

namespace Tests\Unit\Services;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationCreditPool;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use App\Services\LicenseCreditResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Tests\Helpers\assertCreditLedgerEntryComplete;
use function Tests\Helpers\assertOrganizationLicenseIsExpired;
use function Tests\Helpers\assertOrgCreditLedgerEntryComplete;
use function Tests\Helpers\assertUserHasCredits;
use function Tests\Helpers\assertUserLicenseIsActive;
use function Tests\Helpers\assertUserLicenseIsExpired;

class LicenseCreditResetServiceTest extends TestCase
{
    use RefreshDatabase;

    private LicenseCreditResetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LicenseCreditResetService::class);
    }

    // ==================== FREE TIER TESTS ====================

    public function test_free_tier_reset_when_credits_used_and_30_days_passed(): void
    {
        $license = $this->createFreeLicense();
        $user = User::factory()->create(['credits' => 5]);
        $userLicense = $this->createUserLicense($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        // Simulate credit usage
        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => -10,
            'reason' => 'spend',
            'balance_after' => 5,
            'created_at' => now()->subDays(15),
        ]);

        $result = $this->service->processFreeTierReset($userLicense);

        $this->assertTrue($result);
        $this->assertEquals($license->credits, $user->fresh()->credits);
        $this->assertNotNull($userLicense->fresh()->last_credit_reset_at);
    }

    public function test_free_tier_no_reset_when_no_credits_used(): void
    {
        $license = $this->createFreeLicense();
        $user = User::factory()->create(['credits' => 15]);
        $userLicense = $this->createUserLicense($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        // No credit usage - no ledger entries with negative delta

        $result = $this->service->processFreeTierReset($userLicense);

        $this->assertFalse($result);
        $this->assertEquals(15, $user->fresh()->credits);
    }

    public function test_free_tier_no_reset_when_less_than_30_days(): void
    {
        $license = $this->createFreeLicense();
        $user = User::factory()->create(['credits' => 5]);
        $userLicense = $this->createUserLicense($user, $license, [
            'last_credit_reset_at' => now()->subDays(20),
        ]);

        // Simulate credit usage
        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => -10,
            'reason' => 'spend',
            'balance_after' => 5,
            'created_at' => now()->subDays(10),
        ]);

        $result = $this->service->shouldResetFreeCredits($userLicense);

        $this->assertFalse($result);
    }

    // ==================== ONETIME TIER TESTS ====================

    public function test_onetime_expiry_sets_credits_to_zero(): void
    {
        $this->createFreeLicense(); // For fallback
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $result = $this->service->processOnetimeExpiry($userLicense);

        $this->assertTrue($result);
        assertUserHasCredits($user, 0);
        assertUserLicenseIsExpired($userLicense->fresh());
    }

    public function test_onetime_expiry_creates_ledger_entry(): void
    {
        $this->createFreeLicense(); // For fallback
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 50]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->service->processOnetimeExpiry($userLicense);

        $ledger = CreditLedger::where('user_id', $user->id)
            ->where('reason', 'license_expired')
            ->first();
        assertCreditLedgerEntryComplete($ledger);
        $this->assertEquals(-50, $ledger->delta);
        $this->assertEquals(0, $ledger->balance_after);
    }

    public function test_onetime_expiry_activates_free_tier(): void
    {
        $this->createFreeLicense(); // Ensure free license exists
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->service->processOnetimeExpiry($userLicense);

        // Check that a free tier license was created with complete state
        $freeLicense = $user->userLicenses()
            ->whereHas('license', fn ($q) => $q->where('tier', 'free'))
            ->where('status', UserLicense::STATUS_ACTIVE)
            ->first();

        assertUserLicenseIsActive($freeLicense);
    }

    public function test_onetime_no_expiry_when_not_yet_expired(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(30),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $result = $this->service->processOnetimeExpiry($userLicense);

        $this->assertFalse($result);
        $this->assertEquals(100, $user->fresh()->credits);
    }

    // ==================== PREMIUM TIER TESTS ====================

    public function test_premium_reset_resets_credits_to_license_amount(): void
    {
        $license = $this->createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 50]);
        $userLicense = $this->createUserLicense($user, $license, [
            'starts_at' => now()->subDays(35), // Started 35 days ago
            'last_credit_reset_at' => now()->subDays(35), // Last reset at start
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $result = $this->service->processPremiumReset($userLicense);

        $this->assertTrue($result);
        $this->assertEquals(200, $user->fresh()->credits);
    }

    public function test_premium_reset_does_not_accumulate_credits_when_below_license_amount(): void
    {
        $license = $this->createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 150]); // User still has 150 (less than license)
        $userLicense = $this->createUserLicense($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->service->processPremiumReset($userLicense);

        // Should be 200 (reset to license amount, no surplus since 150 < 200)
        $this->assertEquals(200, $user->fresh()->credits);
    }

    public function test_premium_reset_preserves_surplus_credits_from_onetime_purchases(): void
    {
        $license = $this->createPremiumLicense(200);
        // User has 300 credits (200 premium + 100 from one-time purchase)
        $user = User::factory()->create(['credits' => 300]);
        $userLicense = $this->createUserLicense($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->service->processPremiumReset($userLicense);

        // Should be 300: 200 (reset premium) + 100 (preserved surplus)
        $this->assertEquals(300, $user->fresh()->credits);

        // Verify ledger entry tracks the surplus
        $ledger = CreditLedger::where('user_id', $user->id)
            ->where('reason', 'reset_premium')
            ->first();
        $this->assertNotNull($ledger);
        $this->assertEquals(100, $ledger->meta['surplus_preserved']);
        $this->assertEquals(200, $ledger->meta['license_credits']);
    }

    public function test_premium_reset_preserves_partial_surplus_after_usage(): void
    {
        $license = $this->createPremiumLicense(200);
        // User had 300 (200 premium + 100 one-time), used 50, now has 250
        $user = User::factory()->create(['credits' => 250]);
        $userLicense = $this->createUserLicense($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->service->processPremiumReset($userLicense);

        // Should be 250: 200 (reset premium) + 50 (remaining surplus)
        $this->assertEquals(250, $user->fresh()->credits);
    }

    public function test_premium_canceled_expiry_sets_credits_to_zero(): void
    {
        $this->createFreeLicense();
        $license = $this->createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 75]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
            'status' => UserLicense::STATUS_CANCELED,
        ]);

        $result = $this->service->processPremiumCanceledExpiry($userLicense);

        $this->assertTrue($result);
        assertUserHasCredits($user, 0);
        assertUserLicenseIsExpired($userLicense->fresh());
    }

    public function test_premium_canceled_keeps_credits_until_ends_at(): void
    {
        $license = $this->createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 75]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(10), // Still 10 days left
            'status' => UserLicense::STATUS_CANCELED,
        ]);

        $result = $this->service->processPremiumCanceledExpiry($userLicense);

        $this->assertFalse($result);
        $this->assertEquals(75, $user->fresh()->credits); // Credits unchanged
    }

    // ==================== ENSURE FREE TIER TESTS ====================

    public function test_ensure_free_tier_creates_new_license(): void
    {
        $freeLicense = $this->createFreeLicense();
        $user = User::factory()->create();

        $result = $this->service->ensureFreeTierLicense($user);

        $this->assertNotNull($result);
        assertUserLicenseIsActive($result);
        $this->assertEquals($freeLicense->id, $result->license_id);
    }

    public function test_ensure_free_tier_returns_existing_if_present(): void
    {
        $freeLicense = $this->createFreeLicense();
        $user = User::factory()->create();
        $existingUserLicense = $this->createUserLicense($user, $freeLicense, [
            'status' => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $result = $this->service->ensureFreeTierLicense($user);

        $this->assertEquals($existingUserLicense->id, $result->id);
    }

    // ==================== ORGANIZATION PREMIUM CANCELED TESTS ====================

    public function test_org_premium_canceled_expiry_sets_credits_to_zero(): void
    {
        $license = $this->createPremiumLicense(200);
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 150,
        ]);
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'canceled',
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->subDay(),
            'source' => 'test',
            'external_ref' => 'test-'.uniqid(),
        ]);

        $result = $this->service->processOrganizationPremiumCanceledExpiry($orgLicense);

        $this->assertTrue($result);
        $this->assertEquals(0, $creditPool->fresh()->balance_credits);
        assertOrganizationLicenseIsExpired($orgLicense->fresh());
    }

    public function test_org_premium_canceled_creates_ledger_entry(): void
    {
        $license = $this->createPremiumLicense(200);
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 75,
        ]);
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'canceled',
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->subDay(),
            'source' => 'test',
            'external_ref' => 'test-'.uniqid(),
        ]);

        $this->service->processOrganizationPremiumCanceledExpiry($orgLicense);

        $ledger = OrganizationCreditLedger::where('organization_id', $organization->id)
            ->where('reason', 'license_expired')
            ->first();
        assertOrgCreditLedgerEntryComplete($ledger);
        $this->assertEquals(-75, $ledger->delta);
        $this->assertEquals(0, $ledger->balance_after);
    }

    public function test_org_premium_canceled_keeps_credits_until_ends_at(): void
    {
        $license = $this->createPremiumLicense(200);
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'canceled',
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->addDays(10), // Still 10 days left
            'source' => 'test',
            'external_ref' => 'test-'.uniqid(),
        ]);

        $result = $this->service->processOrganizationPremiumCanceledExpiry($orgLicense);

        $this->assertFalse($result);
        $this->assertEquals(100, $creditPool->fresh()->balance_credits);
        $this->assertEquals('canceled', $orgLicense->fresh()->status);
    }

    // ==================== ORGANIZATION PREMIUM RESET SURPLUS TESTS ====================

    public function test_org_premium_reset_preserves_surplus_credits(): void
    {
        $license = $this->createPremiumLicense(200);
        $organization = Organization::factory()->create();
        // Organization has 350 credits (200 premium + 150 from one-time purchase)
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 350,
        ]);
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'source' => 'test',
            'external_ref' => 'test-'.uniqid(),
        ]);

        $result = $this->service->processOrganizationPremiumReset($orgLicense);

        $this->assertTrue($result);
        // Should be 350: 200 (reset premium) + 150 (preserved surplus)
        $this->assertEquals(350, $creditPool->fresh()->balance_credits);

        // Verify ledger entry tracks the surplus
        $ledger = OrganizationCreditLedger::where('organization_id', $organization->id)
            ->where('reason', 'reset_premium')
            ->first();
        $this->assertNotNull($ledger);
        $this->assertEquals(150, $ledger->meta['surplus_preserved']);
        $this->assertEquals(200, $ledger->meta['license_credits']);
    }

    public function test_org_premium_reset_no_surplus_when_below_license_amount(): void
    {
        $license = $this->createPremiumLicense(200);
        $organization = Organization::factory()->create();
        // Organization has only 75 credits left (used most of their premium)
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 75,
        ]);
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'source' => 'test',
            'external_ref' => 'test-'.uniqid(),
        ]);

        $result = $this->service->processOrganizationPremiumReset($orgLicense);

        $this->assertTrue($result);
        // Should be 200 (reset to license amount, no surplus)
        $this->assertEquals(200, $creditPool->fresh()->balance_credits);
    }

    // ==================== HELPER METHODS ====================

    private function createFreeLicense(): License
    {
        return License::factory()->create([
            'tier' => 'free',
            'credits' => 15,
            'credit_reset_interval' => 'daily',
            'active' => true,
            'currency' => 'EUR',
        ]);
    }

    private function createOnetimeLicense(): License
    {
        return License::factory()->create([
            'tier' => 'onetime',
            'credits' => 200,
            'credit_reset_interval' => 'none',
            'period' => 90,
            'active' => true,
        ]);
    }

    private function createPremiumLicense(int $credits = 200): License
    {
        return License::factory()->create([
            'tier' => 'premium',
            'credits' => $credits,
            'credit_reset_interval' => 'monthly',
            'period' => 30,
            'active' => true,
        ]);
    }

    private function createUserLicense(User $user, License $license, array $attributes = []): UserLicense
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
}
