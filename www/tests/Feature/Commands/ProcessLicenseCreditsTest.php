<?php

namespace Tests\Feature\Commands;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationCreditPool;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessLicenseCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_successfully(): void
    {
        $this->artisan('license:process-credits')
            ->assertSuccessful()
            ->expectsOutputToContain('Processing Complete');
    }

    public function test_dry_run_does_not_make_changes(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        $this->createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->artisan('license:process-credits', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run mode');

        // Credits should NOT have changed
        $this->assertEquals(100, $user->fresh()->credits);
    }

    public function test_expires_onetime_license(): void
    {
        $this->createFreeLicense(); // For fallback
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        $this->assertEquals(0, $user->fresh()->credits);
        $this->assertEquals(UserLicense::STATUS_EXPIRED, $userLicense->fresh()->status);
    }

    public function test_resets_free_tier_credits_when_used(): void
    {
        $license = $this->createFreeLicense();
        $user = User::factory()->create(['credits' => 3]);
        $userLicense = $this->createUserLicense($user, $license, [
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

        $this->assertEquals($license->credits, $user->fresh()->credits);
    }

    public function test_does_not_reset_free_tier_when_no_usage(): void
    {
        $license = $this->createFreeLicense();
        $user = User::factory()->create(['credits' => 15]);
        $this->createUserLicense($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        // No usage entries

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should remain unchanged (no reset without usage)
        $this->assertEquals(15, $user->fresh()->credits);
    }

    public function test_resets_premium_credits_on_renewal_date(): void
    {
        $license = $this->createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 50]);
        $this->createUserLicense($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        $this->assertEquals(200, $user->fresh()->credits);
    }

    public function test_expires_canceled_premium_license(): void
    {
        $this->createFreeLicense();
        $license = $this->createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 75]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->subDay(),
            'status' => UserLicense::STATUS_CANCELED,
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        $this->assertEquals(0, $user->fresh()->credits);
        $this->assertEquals(UserLicense::STATUS_EXPIRED, $userLicense->fresh()->status);
    }

    public function test_outputs_statistics_table(): void
    {
        $this->artisan('license:process-credits')
            ->assertSuccessful()
            ->expectsOutputToContain('Free tier resets')
            ->expectsOutputToContain('Onetime expired')
            ->expectsOutputToContain('Premium resets');
    }

    // ==================== ORGANIZATION LICENSE TESTS ====================

    public function test_expires_organization_onetime_license(): void
    {
        $license = $this->createOnetimeLicense();
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 500,
        ]);
        $orgLicense = $this->createOrganizationLicense($organization, $license, [
            'ends_at' => now()->subDay(),
            'status' => 'active',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        $this->assertEquals(0, $creditPool->fresh()->balance_credits);
        $this->assertEquals('expired', $orgLicense->fresh()->status);
    }

    public function test_resets_organization_premium_credits_on_renewal_date(): void
    {
        $license = $this->createPremiumLicense(300);
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 50,
        ]);
        $this->createOrganizationLicense($organization, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => 'active',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        $this->assertEquals(300, $creditPool->fresh()->balance_credits);
    }

    public function test_expires_organization_canceled_premium_license(): void
    {
        $license = $this->createPremiumLicense(200);
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 150,
        ]);
        $orgLicense = $this->createOrganizationLicense($organization, $license, [
            'ends_at' => now()->subDay(),
            'status' => 'canceled',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        $this->assertEquals(0, $creditPool->fresh()->balance_credits);
        $this->assertEquals('expired', $orgLicense->fresh()->status);
    }

    public function test_does_not_expire_organization_canceled_license_before_ends_at(): void
    {
        $license = $this->createPremiumLicense(200);
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 150,
        ]);
        $orgLicense = $this->createOrganizationLicense($organization, $license, [
            'ends_at' => now()->addDays(10),
            'status' => 'canceled',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Should remain unchanged
        $this->assertEquals(150, $creditPool->fresh()->balance_credits);
        $this->assertEquals('canceled', $orgLicense->fresh()->status);
    }

    public function test_outputs_organization_statistics(): void
    {
        $this->artisan('license:process-credits')
            ->assertSuccessful()
            ->expectsOutputToContain('Org onetime expired')
            ->expectsOutputToContain('Org premium resets')
            ->expectsOutputToContain('Org premium canceled expired');
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

    private function createOrganizationLicense(Organization $organization, License $license, array $attributes = []): OrganizationLicense
    {
        return OrganizationLicense::create(array_merge([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => 'test',
            'external_ref' => 'test-'.uniqid(),
        ], $attributes));
    }
}
