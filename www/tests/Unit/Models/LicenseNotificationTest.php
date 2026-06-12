<?php

namespace Tests\Unit\Models;

use App\Models\License;
use App\Models\LicenseNotification;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Tests\Helpers\assertLicenseNotificationComplete;

class LicenseNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_was_recently_sent_returns_true_when_sent_within_days(): void
    {
        $userLicense = $this->createUserLicense();

        LicenseNotification::create([
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
            'sent_at' => now()->subDays(5),
        ]);

        $result = LicenseNotification::wasRecentlySent(
            $userLicense->id,
            null,
            LicenseNotification::TYPE_EXPIRY_7_DAYS,
            7
        );

        $this->assertTrue($result);
    }

    public function test_was_recently_sent_returns_false_when_older_than_days(): void
    {
        $userLicense = $this->createUserLicense();

        LicenseNotification::create([
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
            'sent_at' => now()->subDays(10),
        ]);

        $result = LicenseNotification::wasRecentlySent(
            $userLicense->id,
            null,
            LicenseNotification::TYPE_EXPIRY_7_DAYS,
            7
        );

        $this->assertFalse($result);
    }

    public function test_was_recently_sent_returns_false_when_no_notification(): void
    {
        $userLicense = $this->createUserLicense();

        $result = LicenseNotification::wasRecentlySent(
            $userLicense->id,
            null,
            LicenseNotification::TYPE_EXPIRY_7_DAYS,
            7
        );

        $this->assertFalse($result);
    }

    public function test_was_recently_sent_differentiates_notification_types(): void
    {
        $userLicense = $this->createUserLicense();

        LicenseNotification::create([
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_7_DAYS,
            'sent_at' => now()->subDays(2),
        ]);

        // Should return false for a different notification type
        $result = LicenseNotification::wasRecentlySent(
            $userLicense->id,
            null,
            LicenseNotification::TYPE_LOW_CREDITS,
            7
        );

        $this->assertFalse($result);
    }

    public function test_record_sent_creates_notification(): void
    {
        $userLicense = $this->createUserLicense();

        $notification = LicenseNotification::recordSent(
            $userLicense->id,
            null,
            LicenseNotification::TYPE_RENEWAL_7_DAYS
        );

        assertLicenseNotificationComplete($notification);
        $this->assertEquals($userLicense->id, $notification->user_license_id);
        $this->assertEquals(LicenseNotification::TYPE_RENEWAL_7_DAYS, $notification->notification_type);
    }

    public function test_notification_belongs_to_user_license(): void
    {
        $userLicense = $this->createUserLicense();

        $notification = LicenseNotification::create([
            'user_license_id' => $userLicense->id,
            'notification_type' => LicenseNotification::TYPE_EXPIRY_1_DAY,
            'sent_at' => now(),
        ]);

        $this->assertInstanceOf(UserLicense::class, $notification->userLicense);
        $this->assertEquals($userLicense->id, $notification->userLicense->id);
    }

    public function test_notification_type_constants(): void
    {
        $this->assertEquals('expiry_7_days', LicenseNotification::TYPE_EXPIRY_7_DAYS);
        $this->assertEquals('expiry_1_day', LicenseNotification::TYPE_EXPIRY_1_DAY);
        $this->assertEquals('renewal_7_days', LicenseNotification::TYPE_RENEWAL_7_DAYS);
        $this->assertEquals('low_credits', LicenseNotification::TYPE_LOW_CREDITS);
    }

    private function createUserLicense(): UserLicense
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'onetime',
            'active' => true,
        ]);

        return UserLicense::create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays(90),
            'source' => 'test',
            'external_ref' => 'test-'.uniqid(),
            'is_current' => true,
        ]);
    }
}
