<?php

namespace Tests\Feature\Commands;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\License;
use App\Models\LicenseNotification;
use App\Models\User;
use App\Models\UserLicense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendLicenseNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_command_runs_successfully(): void
    {
        $this->artisan('license:send-notifications')
            ->assertSuccessful()
            ->expectsOutputToContain('Notifications Complete');
    }

    public function test_dry_run_does_not_send_emails(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(5), // Expires in 5 days
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->artisan('license:send-notifications', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run mode');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('license_notifications', [
            'user_license_id' => $license->id,
        ]);
    }

    public function test_sends_7_day_expiry_notification_for_onetime(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100, 'preferred_language' => 'en']);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(5),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->artisan('license:send-notifications')
            ->assertSuccessful();

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);

            return str_contains($property->getValue($job), 'license-expiry-warning');
        });

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
        ]);
    }

    public function test_sends_1_day_expiry_notification(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100, 'preferred_language' => 'nl']);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addHours(20), // Less than 1 day
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->artisan('license:send-notifications')
            ->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_1_DAY,
        ]);
    }

    public function test_does_not_send_duplicate_notification(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(5),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        // Already sent this notification
        LicenseNotification::create([
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
            'sent_at' => now()->subDays(2),
        ]);

        $this->artisan('license:send-notifications')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped (already sent)');

        // Should only have 1 notification (the existing one)
        $this->assertEquals(1, LicenseNotification::where('user_license_id', $userLicense->id)->count());
    }

    public function test_sends_renewal_notification_for_premium(): void
    {
        $license = $this->createPremiumLicense();
        $user = User::factory()->create(['credits' => 50, 'preferred_language' => 'en']);
        $userLicense = $this->createUserLicense($user, $license, [
            'starts_at' => now()->subDays(25), // Renewal in ~5 days
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $this->artisan('license:send-notifications')
            ->assertSuccessful();

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);

            return str_contains($property->getValue($job), 'license-renewal-reminder');
        });
    }

    public function test_sends_expiry_notification_for_canceled_premium(): void
    {
        $license = $this->createPremiumLicense();
        $user = User::factory()->create(['credits' => 50]);
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(5),
            'status' => UserLicense::STATUS_CANCELED,
        ]);

        $this->artisan('license:send-notifications')
            ->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
        ]);
    }

    public function test_sends_low_credits_notification(): void
    {
        // Freeze time to ensure today() matches in both test and command
        $frozenTime = Carbon::create(2025, 1, 15, 10, 0, 0);
        Carbon::setTestNow($frozenTime);

        $license = $this->createPremiumLicense();
        $user = User::factory()->create(['credits' => 5]); // Low credits
        $userLicense = $this->createUserLicense($user, $license, [
            'starts_at' => Carbon::now()->subDays(10), // Renewal in ~20 days
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        // Credits dropped below 10 today - use raw insert to ensure date matches
        \DB::table('credit_ledger')->insert([
            'user_id' => $user->id,
            'delta' => -10,
            'reason' => 'spend',
            'balance_after' => 5,
            'created_at' => $frozenTime->toDateTimeString(),
        ]);

        $this->artisan('license:send-notifications')
            ->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_LOW_CREDITS,
        ]);

        Carbon::setTestNow(); // Reset time
    }

    public function test_does_not_send_low_credits_when_renewal_tomorrow(): void
    {
        // Freeze time for predictable month arithmetic
        $frozenTime = Carbon::create(2025, 1, 30, 10, 0, 0); // Jan 30
        Carbon::setTestNow($frozenTime);

        $license = $this->createPremiumLicense();
        $user = User::factory()->create(['credits' => 5]);

        // Start date is exactly 1 month ago minus 1 day = Dec 31, so renewal is Jan 31 (tomorrow)
        $startsAt = Carbon::create(2024, 12, 31, 10, 0, 0);
        $userLicense = $this->createUserLicense($user, $license, [
            'starts_at' => $startsAt,
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        \DB::table('credit_ledger')->insert([
            'user_id' => $user->id,
            'delta' => -10,
            'reason' => 'spend',
            'balance_after' => 5,
            'created_at' => $frozenTime->toDateTimeString(),
        ]);

        $this->artisan('license:send-notifications')
            ->assertSuccessful();

        // Should NOT have low credits notification (renewal is tomorrow)
        $this->assertDatabaseMissing('license_notifications', [
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_LOW_CREDITS,
        ]);

        Carbon::setTestNow();
    }

    public function test_outputs_statistics_table(): void
    {
        $this->artisan('license:send-notifications')
            ->assertSuccessful()
            ->expectsOutputToContain('Expiry (7 days)')
            ->expectsOutputToContain('Renewal (7 days)')
            ->expectsOutputToContain('Low credits');
    }

    // ==================== IS_CURRENT REGRESSION TESTS ====================

    /**
     * Regression: user bought a new license; old license should NOT trigger expiry mail.
     * This was the reported bug: customer received expiry warning for a superseded license.
     */
    public function test_does_not_send_expiry_notification_for_superseded_license(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 197, 'preferred_language' => 'nl']);

        // Old license expiring soon — no longer current because user bought a new one
        $oldLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(3),
            'status' => UserLicense::STATUS_ACTIVE,
            'is_current' => false, // superseded by new purchase
        ]);

        // New license the user bought — this is current
        $newLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(90),
            'status' => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        // No notification for the old, superseded license
        $this->assertDatabaseMissing('license_notifications', [
            'user_license_id' => $oldLicense->id,
        ]);

        // No notification for the new license either (expires far in the future)
        $this->assertDatabaseMissing('license_notifications', [
            'user_license_id' => $newLicense->id,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_still_sends_expiry_notification_when_is_current_true_and_no_newer_license(): void
    {
        $license = $this->createOnetimeLicense();
        $user = User::factory()->create(['credits' => 50, 'preferred_language' => 'nl']);

        // Only license — is_current and expiring soon
        $userLicense = $this->createUserLicense($user, $license, [
            'ends_at' => now()->addDays(4),
            'status' => UserLicense::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $this->artisan('license:send-notifications')->assertSuccessful();

        $this->assertDatabaseHas('license_notifications', [
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
        ]);
    }

    // ==================== HELPER METHODS ====================

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

    private function createPremiumLicense(): License
    {
        return License::factory()->create([
            'tier' => 'premium',
            'credits' => 200,
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
