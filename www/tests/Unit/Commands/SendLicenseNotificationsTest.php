<?php

namespace Tests\Unit\Commands;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\LicenseNotification;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for SendLicenseNotifications command.
 *
 * Focus: which licenses are selected (or excluded) for each notification type.
 * No email dispatch assertions — those belong in the Feature test.
 */
class SendLicenseNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // is_current filtering — expiry notifications
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function expiry_notification_skips_non_current_onetime_license(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->onetimeLicense();

        // Old license — expired-soon but superseded
        $this->userLicense($user, $license, [
            'ends_at'    => now()->addDays(3),
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => false,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseCount('license_notifications', 0);
    }

    #[Test]
    public function expiry_notification_fires_for_current_onetime_license(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->onetimeLicense();

        $ul = $this->userLicense($user, $license, [
            'ends_at'    => now()->addDays(3),
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $ul->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
        ]);
    }

    #[Test]
    public function expiry_notification_skips_non_current_canceled_premium(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->premiumLicense();

        $this->userLicense($user, $license, [
            'ends_at'    => now()->addDays(3),
            'status'     => UserLicense::STATUS_CANCELED,
            'is_current' => false,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseCount('license_notifications', 0);
    }

    #[Test]
    public function expiry_notification_fires_for_current_canceled_premium(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->premiumLicense();

        $ul = $this->userLicense($user, $license, [
            'ends_at'    => now()->addDays(3),
            'status'     => UserLicense::STATUS_CANCELED,
            'is_current' => true,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $ul->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // is_current filtering — renewal notifications
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function renewal_notification_skips_non_current_premium_license(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->premiumLicense();

        $this->userLicense($user, $license, [
            'starts_at'  => now()->subDays(25), // renewal in ~5 days
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => false,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseCount('license_notifications', 0);
    }

    #[Test]
    public function renewal_notification_fires_for_current_premium_license(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->premiumLicense();

        $ul = $this->userLicense($user, $license, [
            'starts_at'  => now()->subDays(25),
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $ul->id,
            'notification_type' => LicenseNotification::TYPE_RENEWAL_7_DAYS,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // is_current filtering — low credit notifications (user)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function low_credit_notification_skips_non_current_premium_license(): void
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 15, 10, 0, 0));

        $user = User::factory()->create(['credits' => 5, 'preferred_language' => 'nl']);
        $license = $this->premiumLicense();

        $this->userLicense($user, $license, [
            'starts_at'  => now()->subDays(10),
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => false,
        ]);

        \DB::table('credit_ledger')->insert([
            'user_id'      => $user->id,
            'delta'        => -10,
            'reason'       => 'spend',
            'balance_after' => 5,
            'created_at'   => now()->toDateTimeString(),
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseCount('license_notifications', 0);

        Carbon::setTestNow();
    }

    #[Test]
    public function low_credit_notification_fires_for_current_premium_license(): void
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 15, 10, 0, 0));

        $user = User::factory()->create(['credits' => 5, 'preferred_language' => 'nl']);
        $license = $this->premiumLicense();

        $ul = $this->userLicense($user, $license, [
            'starts_at'  => now()->subDays(10),
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        \DB::table('credit_ledger')->insert([
            'user_id'      => $user->id,
            'delta'        => -10,
            'reason'       => 'spend',
            'balance_after' => 5,
            'created_at'   => now()->toDateTimeString(),
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $ul->id,
            'notification_type' => LicenseNotification::TYPE_LOW_CREDITS,
        ]);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // is_current filtering — organization low credit notifications
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function org_low_credit_notification_skips_non_current_license(): void
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 15, 10, 0, 0));

        $org     = Organization::factory()->create();
        $admin   = User::factory()->create(['preferred_language' => 'nl']);
        $org->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value]);
        $pool    = $org->creditPool()->create(['balance_credits' => 5]);
        $license = $this->premiumLicense();

        $this->orgLicense($org, $license, [
            'starts_at'  => now()->subDays(10),
            'status'     => 'active',
            'is_current' => false,
        ]);

        \DB::table('organization_credit_ledger')->insert([
            'organization_id' => $org->id,
            'delta'           => -10,
            'reason'          => 'spend',
            'balance_after'   => 5,
            'created_at'      => now()->toDateTimeString(),
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseCount('license_notifications', 0);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Additional edge cases
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function no_notifications_sent_when_license_not_expiring_soon(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->onetimeLicense();

        // Expires in 30 days — well outside the 7-day window
        $this->userLicense($user, $license, [
            'ends_at'    => now()->addDays(30),
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseCount('license_notifications', 0);
    }

    #[Test]
    public function no_notifications_sent_for_already_expired_license(): void
    {
        $user = User::factory()->create(['preferred_language' => 'nl']);
        $license = $this->onetimeLicense();

        // Already expired
        $this->userLicense($user, $license, [
            'ends_at'    => now()->subDay(),
            'status'     => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseCount('license_notifications', 0);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function onetimeLicense(): License
    {
        return License::factory()->create([
            'tier'                 => 'onetime',
            'credits'              => 200,
            'credit_reset_interval' => 'none',
            'period'               => 90,
            'active'               => true,
        ]);
    }

    private function premiumLicense(): License
    {
        return License::factory()->create([
            'tier'                 => 'premium',
            'credits'              => 200,
            'credit_reset_interval' => 'monthly',
            'period'               => 30,
            'active'               => true,
        ]);
    }

    private function userLicense(User $user, License $license, array $attrs = []): UserLicense
    {
        return UserLicense::create(array_merge([
            'user_id'      => $user->id,
            'license_id'   => $license->id,
            'status'       => UserLicense::STATUS_ACTIVE,
            'starts_at'    => now(),
            'ends_at'      => null,
            'source'       => 'test',
            'external_ref' => 'test-'.uniqid(),
            'is_current'   => true,
        ], $attrs));
    }

    private function orgLicense(Organization $org, License $license, array $attrs = []): OrganizationLicense
    {
        return OrganizationLicense::create(array_merge([
            'organization_id' => $org->id,
            'license_id'      => $license->id,
            'status'          => 'active',
            'billing_method'  => 'manual',
            'payment_status'  => 'paid',
            'starts_at'       => now(),
            'ends_at'         => null,
            'source'          => 'test',
            'external_ref'    => 'test-'.uniqid(),
            'is_current'      => true,
        ], $attrs));
    }
}
