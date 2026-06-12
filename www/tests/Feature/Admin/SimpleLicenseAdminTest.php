<?php

namespace Tests\Feature\Admin;

use App\Models\AnalyticsEvent;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use App\Services\BalanceService;
use App\Services\Licenses\LicenseAdminService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SimpleLicenseAdminTest extends TestCase
{
    use RefreshDatabase;

    private LicenseAdminService $service;

    private User $user;

    private User $admin;

    private License $onetimeLicense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LicenseAdminService(new BalanceService);
        $this->user = User::factory()->create();
        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->onetimeLicense = License::factory()->create([
            'tier' => 'onetime',
            'credits' => 50,
        ]);
    }

    #[Test]
    public function it_expires_onetime_license_with_remaining_credits()
    {
        // Arrange: User starts with 130 credits from other purchases
        $this->user->update(['credits' => 130]);

        // Create active license
        $userLicense = UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $this->onetimeLicense->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => null,
        ]);

        // Create some credit history for this license
        CreditLedger::factory()->create([
            'user_id' => $this->user->id,
            'delta' => 50,
            'reason' => 'purchase',
            'balance_after' => 150,
            'meta' => ['license_assignment_id' => $userLicense->id],
        ]);

        CreditLedger::factory()->create([
            'user_id' => $this->user->id,
            'delta' => -20,
            'reason' => 'spend',
            'balance_after' => 130,
            'meta' => ['license_assignment_id' => $userLicense->id],
        ]);

        $when = Carbon::parse('2024-01-15 14:30:00');

        // Act
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id,
            when: $when
        );

        // Assert result
        $this->assertTrue($result->changed);
        $this->assertEquals('expired', $result->status);
        $this->assertEquals(30, $result->remainingAdjusted); // 50 - 20 = 30
        $this->assertEquals(100, $result->newBalance); // 130 - 30 = 100
        $this->assertEquals($when, $result->endedAt);

        // Assert license updated
        $userLicense->refresh();
        $this->assertEquals('expired', $userLicense->status);
        $this->assertFalse($userLicense->is_current);
        $this->assertEquals($when, $userLicense->ends_at);

        // Assert credit ledger entry
        $this->assertDatabaseHas('credit_ledger', [
            'user_id' => $this->user->id,
            'delta' => -30,
            'reason' => 'adjust',
            'balance_after' => 100,
            'created_at' => $when,
        ]);

        // Assert analytics event
        $this->assertDatabaseHas('analytics_events', [
            'user_id' => $this->user->id,
            'event' => 'license_expired_admin',
        ]);
    }

    #[Test]
    public function it_handles_idempotency()
    {
        // Arrange: Create active license
        $userLicense = UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $this->onetimeLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        // First call
        $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );

        $initialCreditCount = CreditLedger::count();
        $initialAnalyticsCount = AnalyticsEvent::count();

        // Second call (should be idempotent)
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );

        // Assert no changes
        $this->assertFalse($result->changed);
        $this->assertEquals($initialCreditCount, CreditLedger::count());
        $this->assertEquals($initialAnalyticsCount, AnalyticsEvent::count());
    }

    #[Test]
    public function it_throws_exception_for_wrong_tier()
    {
        // Arrange: Business license (not onetime)
        $businessLicense = License::factory()->create(['tier' => 'business']);
        $userLicense = UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $businessLicense->id,
            'status' => 'active',
        ]);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only onetime licenses are supported by this action. Found tier: business');

        $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );
    }

    #[Test]
    public function it_throws_exception_for_invalid_action()
    {
        // Arrange
        $userLicense = UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $this->onetimeLicense->id,
            'status' => 'active',
        ]);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Action must be 'expire' or 'cancel'");

        $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'invalid',
            adminId: $this->admin->id
        );
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_license()
    {
        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UserLicense not found: 99999');

        $this->service->closeOnetimeUserLicense(
            userLicenseId: 99999,
            action: 'expire',
            adminId: $this->admin->id
        );
    }
}
