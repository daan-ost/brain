<?php

namespace Tests\Unit\Models;

use App\Models\License;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage voor premature-expiry anomaly detection en duration label op
 * UserLicense. Detecteert bugs zoals 2026-05 incident (PDFengine user 241636):
 * yearly subscription waarbij ends_at = starts_at + 1 month.
 *
 * Zie propagation doc `2026-05-19-admin-user-diagnostics.md`.
 */
class UserLicensePrematureExpiryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function yearly_license_closed_after_one_week_is_flagged_as_premature(): void
    {
        $license = License::factory()->create(['billing_cycle' => 'yearly']);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(7),
            'ends_at' => now(),
        ]);

        $this->assertTrue($userLicense->is_premature_expiry);
    }

    #[Test]
    public function yearly_license_that_ran_almost_full_year_is_not_premature(): void
    {
        $license = License::factory()->create(['billing_cycle' => 'yearly']);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(360),
            'ends_at' => now(),
        ]);

        $this->assertFalse($userLicense->is_premature_expiry);
    }

    #[Test]
    public function monthly_license_closed_after_two_days_is_premature(): void
    {
        $license = License::factory()->create(['billing_cycle' => 'monthly']);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_EXPIRED,
            'starts_at' => now()->subDays(2),
            'ends_at' => now(),
        ]);

        $this->assertTrue($userLicense->is_premature_expiry);
    }

    #[Test]
    public function active_license_is_never_premature_even_with_short_duration(): void
    {
        $license = License::factory()->create(['billing_cycle' => 'yearly']);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_ACTIVE,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(363),
        ]);

        $this->assertFalse($userLicense->is_premature_expiry);
    }

    #[Test]
    public function license_with_unknown_billing_cycle_is_never_premature(): void
    {
        $license = License::factory()->create(['billing_cycle' => 'one_time']);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(1),
            'ends_at' => now(),
        ]);

        $this->assertFalse($userLicense->is_premature_expiry);
    }

    #[Test]
    public function license_without_ends_at_is_not_premature(): void
    {
        $license = License::factory()->create(['billing_cycle' => 'yearly']);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(5),
            'ends_at' => null,
        ]);

        $this->assertFalse($userLicense->is_premature_expiry);
    }

    #[Test]
    public function duration_label_returns_months_for_long_durations(): void
    {
        $userLicense = UserLicense::factory()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now(),
        ]);

        $this->assertSame('2 mnd', $userLicense->duration_label);
    }

    #[Test]
    public function duration_label_returns_days_for_short_durations(): void
    {
        $userLicense = UserLicense::factory()->create([
            'starts_at' => now()->subDays(5),
            'ends_at' => now(),
        ]);

        $this->assertSame('5 dgn', $userLicense->duration_label);
    }

    #[Test]
    public function duration_label_returns_ongoing_for_active_license_without_ends_at(): void
    {
        $userLicense = UserLicense::factory()->create([
            'starts_at' => now()->subDays(30),
            'ends_at' => null,
        ]);

        $this->assertSame('ongoing', $userLicense->duration_label);
    }

    #[Test]
    public function payment_provider_falls_back_to_mollie_from_subscription_id(): void
    {
        $userLicense = UserLicense::factory()->create([
            'payment_provider' => null,
            'mollie_subscription_id' => 'sub_legacy',
        ]);

        $this->assertSame('mollie', $userLicense->payment_provider);
    }

    #[Test]
    public function payment_provider_returns_stripe_when_column_set(): void
    {
        $userLicense = UserLicense::factory()->create([
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_stripe',
        ]);

        $this->assertSame('stripe', $userLicense->payment_provider);
    }

    #[Test]
    public function provider_dashboard_url_builds_stripe_subscription_url(): void
    {
        $userLicense = UserLicense::factory()->create([
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_xyz',
        ]);

        $this->assertSame(
            'https://dashboard.stripe.com/subscriptions/sub_xyz',
            $userLicense->provider_dashboard_url,
        );
    }

    #[Test]
    public function regression_yearly_subscription_with_monthly_credit_reset_premature_after_one_month(): void
    {
        // Specifiek de 2026-05 incident vorm: yearly billing met monthly credit reset,
        // cancel zet ends_at fout (op 1 maand ipv 1 jaar). De anomaly badge moet
        // dit altijd flaggen, ongeacht of de underlying bug ooit terugkomt.
        $license = License::factory()->create([
            'billing_cycle' => 'yearly',
            'credit_reset_interval' => 'monthly',
        ]);
        $userLicense = UserLicense::factory()->create([
            'license_id' => $license->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(30),
            'ends_at' => now(),
        ]);

        $this->assertTrue(
            $userLicense->is_premature_expiry,
            'Yearly subscription dat na 30 dagen sluit moet als anomaly worden gevlagd.'
        );
    }
}
