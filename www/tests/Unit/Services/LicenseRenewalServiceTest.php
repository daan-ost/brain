<?php

namespace Tests\Unit\Services;

use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use App\Services\LicenseRenewalService;
use App\Services\MollieSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LicenseRenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    private LicenseRenewalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LicenseRenewalService::class);
    }

    // ==================== findLicenseForUser TESTS ====================

    #[Test]
    public function it_finds_user_license_by_id(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
        ]);

        $result = $this->service->findLicenseForUser($user, $userLicense->id);

        $this->assertNotNull($result['license']);
        $this->assertEquals($userLicense->id, $result['license']->id);
        $this->assertEquals('user', $result['type']);
    }

    #[Test]
    public function it_finds_organization_license_for_admin(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $license = License::factory()->create();
        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
        ]);

        $result = $this->service->findLicenseForUser($user, $orgLicense->id);

        $this->assertNotNull($result['license']);
        $this->assertEquals($orgLicense->id, $result['license']->id);
        $this->assertEquals('organization', $result['type']);
    }

    #[Test]
    public function it_denies_organization_license_for_member(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create();
        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
        ]);

        $result = $this->service->findLicenseForUser($user, $orgLicense->id);

        $this->assertNull($result['license']);
        $this->assertEquals('organization', $result['type']);
        $this->assertEquals('not_admin', $result['error']);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_license(): void
    {
        $user = User::factory()->create();

        $result = $this->service->findLicenseForUser($user, 99999);

        $this->assertNull($result['license']);
        $this->assertEquals('unknown', $result['type']);
    }

    #[Test]
    public function it_does_not_find_other_users_license(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $license = License::factory()->create();

        $otherUserLicense = UserLicense::factory()->create([
            'user_id' => $otherUser->id,
            'license_id' => $license->id,
        ]);

        $result = $this->service->findLicenseForUser($user, $otherUserLicense->id);

        $this->assertNull($result['license']);
    }

    // ==================== validateCancellation TESTS ====================

    #[Test]
    public function it_validates_monthly_subscription_can_be_canceled(): void
    {
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
        ]);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
        ]);

        $result = $this->service->validateCancellation($userLicense);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function it_validates_yearly_subscription_can_be_canceled(): void
    {
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'yearly',
        ]);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
        ]);

        $result = $this->service->validateCancellation($userLicense);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function it_rejects_free_license_cancellation(): void
    {
        $license = License::factory()->create([
            'tier' => 'free',
            'billing_cycle' => 'monthly',
        ]);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
        ]);

        $result = $this->service->validateCancellation($userLicense);

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    #[Test]
    public function it_rejects_onetime_license_cancellation(): void
    {
        $license = License::factory()->create([
            'tier' => 'business',
            'billing_cycle' => 'onetime',
        ]);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
        ]);

        $result = $this->service->validateCancellation($userLicense);

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    // ==================== cancelRenewal TESTS ====================

    #[Test]
    public function it_cancels_user_license_renewal(): void
    {
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
        ]);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'starts_at' => now()->subDays(15),
            'ends_at' => null,
            'status' => 'active',
        ]);

        $result = $this->service->cancelRenewal($userLicense, 'user');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['renewal_date']);
        $this->assertNull($result['error']);

        // License should have status 'canceled' and ends_at set
        // User can continue using credits until ends_at passes
        $userLicense->refresh();
        $this->assertEquals('canceled', $userLicense->status);
        $this->assertNotNull($userLicense->ends_at);
    }

    #[Test]
    public function it_cancels_organization_license_renewal(): void
    {
        $organization = Organization::factory()->create();
        $license = License::factory()->create([
            'tier' => 'business',
            'billing_cycle' => 'yearly',
            'credit_reset_interval' => 'yearly',
        ]);
        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'starts_at' => now()->subMonths(6),
            'ends_at' => null,
            'status' => 'active',
        ]);

        $result = $this->service->cancelRenewal($orgLicense, 'organization');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['renewal_date']);

        // License should have status 'canceled' and ends_at set
        $orgLicense->refresh();
        $this->assertEquals('canceled', $orgLicense->status);
        $this->assertNotNull($orgLicense->ends_at);
    }

    /**
     * Regression test for the 2026-05 incident (PDFengine user 241636).
     *
     * Yearly billing with monthly credit resets is the standard config for
     * "$X/month billed annually" plans. The old cancelRenewal code fell back to
     * credit_reset_interval first, setting ends_at one month after starts_at
     * instead of one year.
     */
    #[Test]
    public function it_uses_billing_cycle_not_credit_reset_interval_for_ends_at(): void
    {
        $startsAt = now()->subDays(15);

        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'yearly',           // payment frequency
            'credit_reset_interval' => 'monthly',  // credits reset monthly
        ]);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'starts_at' => $startsAt,
            'ends_at' => null,
        ]);

        $result = $this->service->cancelRenewal($userLicense, 'user');

        $this->assertTrue($result['success']);
        $userLicense->refresh();

        $diffInDays = $startsAt->diffInDays($userLicense->ends_at);
        $this->assertGreaterThan(
            300,
            $diffInDays,
            "ends_at should be ~1 year after starts_at for yearly billing, got {$diffInDays} days."
        );
    }

    #[Test]
    public function it_cancels_mollie_subscription_when_present(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
        ]);
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'source' => 'mollie',
            'external_ref' => 'sub_12345',
            'starts_at' => now()->subDays(15),
        ]);

        // Create associated order with Mollie subscription
        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'paid',
            'mollie_customer_id' => 'cst_12345',
            'mollie_subscription_id' => 'sub_12345',
        ]);

        // Mock MollieSubscriptionService
        $mockMollie = Mockery::mock(MollieSubscriptionService::class);
        $mockMollie->shouldReceive('cancelSubscription')
            ->once()
            ->with('cst_12345', 'sub_12345')
            ->andReturn(['success' => true]);

        $service = new LicenseRenewalService($mockMollie);
        $result = $service->cancelRenewal($userLicense, 'user');

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function it_handles_mollie_cancellation_failure_gracefully(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
        ]);
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'source' => 'mollie',
            'external_ref' => 'sub_12345',
            'starts_at' => now()->subDays(15),
        ]);

        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'paid',
            'mollie_customer_id' => 'cst_12345',
            'mollie_subscription_id' => 'sub_12345',
        ]);

        // Mock MollieSubscriptionService to fail
        $mockMollie = Mockery::mock(MollieSubscriptionService::class);
        $mockMollie->shouldReceive('cancelSubscription')
            ->once()
            ->andReturn(['success' => false, 'error' => 'API error']);

        $service = new LicenseRenewalService($mockMollie);
        $result = $service->cancelRenewal($userLicense, 'user');

        // Should still succeed - Mollie failure is logged but doesn't block
        $this->assertTrue($result['success']);
    }

    // ==================== getNextRenewalDate TESTS ====================

    #[Test]
    public function it_calculates_next_monthly_renewal_date(): void
    {
        $startsAt = now()->subDays(45);

        $result = $this->service->getNextRenewalDate($startsAt, 'monthly');

        $this->assertNotNull($result);
        $this->assertTrue($result->isFuture());
    }

    #[Test]
    public function it_calculates_next_yearly_renewal_date(): void
    {
        $startsAt = now()->subMonths(14);

        $result = $this->service->getNextRenewalDate($startsAt, 'yearly');

        $this->assertNotNull($result);
        $this->assertTrue($result->isFuture());
    }

    #[Test]
    public function it_returns_null_for_invalid_interval(): void
    {
        $startsAt = now()->subDays(30);

        $result = $this->service->getNextRenewalDate($startsAt, 'invalid');

        $this->assertNull($result);
    }

    #[Test]
    public function it_handles_leap_year_correctly(): void
    {
        // Feb 29, 2024 (leap year) + 1 year should be Feb 28, 2025
        $startsAt = \Carbon\Carbon::create(2024, 2, 29);
        $fromDate = \Carbon\Carbon::create(2025, 2, 1);

        $result = $this->service->getNextRenewalDate($startsAt, 'yearly', $fromDate);

        $this->assertEquals(28, $result->day);
        $this->assertEquals(2, $result->month);
        $this->assertEquals(2025, $result->year);
    }
}
