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

class CloseOnetimeUserLicenseTest extends TestCase
{
    use RefreshDatabase;

    private LicenseAdminService $service;

    private User $user;

    private User $admin;

    private License $onetimeLicense;

    private License $businessLicense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LicenseAdminService(new BalanceService);

        $this->user = User::factory()->create();

        $this->admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $this->onetimeLicense = License::factory()->create([
            'tier' => 'onetime',
            'credits' => 50,
        ]);

        $this->businessLicense = License::factory()->create([
            'tier' => 'business',
            'credits' => 100,
        ]);
    }

    #[Test]
    public function it_expires_onetime_license_with_remaining_credits()
    {
        // Arrange: User starts with 100 credits from other purchases
        $this->user->update(['credits' => 100]);

        // Create active license with purchase and spend history
        $userLicense = $this->createActiveOnetimeLicense();
        $this->createLicenseCreditHistory($userLicense->id, purchased: 50, spent: 20);

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
        $this->assertEquals(70, $result->newBalance); // 100 - 30 = 70
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
            'balance_after' => 70,
            'created_at' => $when,
        ]);

        $ledgerEntry = CreditLedger::where('user_id', $this->user->id)
            ->where('reason', 'adjust')
            ->latest()
            ->first();

        $this->assertEquals([
            'action' => 'license_admin_close',
            'user_license_id' => $userLicense->id,
            'origin' => 'admin',
            'admin_id' => $this->admin->id,
        ], $ledgerEntry->meta);

        // Assert user balance updated
        $this->user->refresh();
        $this->assertEquals(70, $this->user->credits);
        $this->assertEquals($when, $this->user->credits_updated_at);

        // Assert analytics event
        $this->assertDatabaseHas('analytics_events', [
            'user_id' => $this->user->id,
            'event' => 'license_expired_admin',
            'created_at' => $when,
        ]);

        $analyticsEvent = AnalyticsEvent::where('user_id', $this->user->id)
            ->where('event', 'license_expired_admin')
            ->first();

        $expectedEventData = [
            'user_license_id' => $userLicense->id,
            'tier' => 'onetime',
            'previous_status' => 'active',
            'new_status' => 'expired',
            'remaining_adjusted' => 30,
            'admin_id' => $this->admin->id,
            'at' => $when->toISOString(),
        ];

        $this->assertEquals($expectedEventData, $analyticsEvent->meta);
    }

    #[Test]
    public function it_cancels_onetime_license_with_remaining_credits()
    {
        // Arrange
        $userLicense = $this->createActiveOnetimeLicense();
        $this->createLicenseCreditHistory($userLicense->id, purchased: 40, spent: 15);

        // Act
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'cancel',
            adminId: $this->admin->id
        );

        // Assert
        $this->assertTrue($result->changed);
        $this->assertEquals('canceled', $result->status);
        $this->assertEquals(25, $result->remainingAdjusted); // 40 - 15 = 25

        // Assert analytics event for cancel
        $this->assertDatabaseHas('analytics_events', [
            'user_id' => $this->user->id,
            'event' => 'license_canceled_admin',
        ]);
    }

    #[Test]
    public function it_handles_idempotency_on_second_call()
    {
        // Arrange
        $userLicense = $this->createActiveOnetimeLicense();
        $this->createLicenseCreditHistory($userLicense->id, purchased: 30, spent: 10);

        // First call
        $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );

        $initialCreditCount = CreditLedger::count();
        $initialAnalyticsCount = AnalyticsEvent::count();

        // Act: Second call
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );

        // Assert: No changes on second call
        $this->assertFalse($result->changed);
        $this->assertEquals('expired', $result->status);
        $this->assertEquals(0, $result->remainingAdjusted);

        // No additional ledger entries or analytics events
        $this->assertEquals($initialCreditCount, CreditLedger::count());
        $this->assertEquals($initialAnalyticsCount, AnalyticsEvent::count());
    }

    #[Test]
    public function it_throws_exception_for_wrong_tier()
    {
        // Arrange: Create business license (not onetime)
        $userLicense = UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $this->businessLicense->id,
            'status' => 'active',
            'is_current' => true,
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
    public function it_handles_already_not_active_license()
    {
        // Arrange: Create inactive license
        $userLicense = UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $this->onetimeLicense->id,
            'status' => 'canceled',
            'is_current' => false,
        ]);

        // Act
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );

        // Assert: No changes
        $this->assertFalse($result->changed);
        $this->assertEquals('canceled', $result->status);
        $this->assertEquals(0, $result->remainingAdjusted);
    }

    #[Test]
    public function it_handles_zero_remaining_credits()
    {
        // Arrange: License with no remaining credits
        $userLicense = $this->createActiveOnetimeLicense();
        $this->createLicenseCreditHistory($userLicense->id, purchased: 30, spent: 30);

        $initialBalance = $this->user->credits;

        // Act
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );

        // Assert
        $this->assertTrue($result->changed);
        $this->assertEquals(0, $result->remainingAdjusted);
        $this->assertEquals($initialBalance, $result->newBalance); // No balance change

        // No adjust entry should be created
        $this->assertDatabaseMissing('credit_ledger', [
            'user_id' => $this->user->id,
            'reason' => 'adjust',
        ]);

        // Analytics should still be logged
        $this->assertDatabaseHas('analytics_events', [
            'user_id' => $this->user->id,
            'event' => 'license_expired_admin',
        ]);
    }

    #[Test]
    public function it_throws_exception_for_invalid_action()
    {
        // Arrange
        $userLicense = $this->createActiveOnetimeLicense();

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

    #[Test]
    public function it_preserves_existing_ends_at_if_earlier_than_when()
    {
        // Arrange: License with ends_at in the past
        $earlierEndDate = Carbon::parse('2023-12-01');
        $userLicense = UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $this->onetimeLicense->id,
            'status' => 'active',
            'is_current' => true,
            'ends_at' => $earlierEndDate,
        ]);

        $when = Carbon::parse('2024-01-15');

        // Act
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id,
            when: $when
        );

        // Assert: Original ends_at preserved
        $this->assertEquals($earlierEndDate, $result->endedAt);
        $userLicense->refresh();
        $this->assertEquals($earlierEndDate, $userLicense->ends_at);
    }

    #[Test]
    public function it_prevents_negative_balance()
    {
        // Arrange: User with low balance
        $this->user->update(['credits' => 5]);
        $userLicense = $this->createActiveOnetimeLicense();
        $this->createLicenseCreditHistory($userLicense->id, purchased: 50, spent: 0);

        // Act
        $result = $this->service->closeOnetimeUserLicense(
            userLicenseId: $userLicense->id,
            action: 'expire',
            adminId: $this->admin->id
        );

        // Assert: Balance clamped to 0
        $this->assertEquals(0, $result->newBalance);
        $this->user->refresh();
        $this->assertEquals(0, $this->user->credits);
    }

    private function createActiveOnetimeLicense(): UserLicense
    {
        return UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $this->onetimeLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);
    }

    private function createLicenseCreditHistory(int $userLicenseId, int $purchased, int $spent): void
    {
        $this->user->refresh();
        $currentBalance = $this->user->credits;

        // Create purchase entries
        if ($purchased > 0) {
            $currentBalance += $purchased;
            CreditLedger::factory()->create([
                'user_id' => $this->user->id,
                'delta' => $purchased,
                'reason' => 'purchase',
                'balance_after' => $currentBalance,
                'meta' => ['license_assignment_id' => $userLicenseId],
            ]);
        }

        // Create spend entries
        if ($spent > 0) {
            $currentBalance -= $spent;
            CreditLedger::factory()->create([
                'user_id' => $this->user->id,
                'delta' => -$spent,
                'reason' => 'spend',
                'balance_after' => $currentBalance,
                'meta' => ['license_assignment_id' => $userLicenseId],
            ]);
        }
    }
}
