<?php

namespace Tests\Unit\Services;

use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use App\Services\LicensePriceChangeService;
use App\Services\MollieSubscriptionService;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage voor provider-aware LicensePriceChangeService.
 * Issue 4.1 uit docs/propagation/2026-05-21-stripe-payment-provider-gaps.md.
 *
 * Verifieert dat zowel Mollie- als Stripe-subscriptions geüpdatet kunnen
 * worden bij een prijswijziging. Mockt beide subscription services zodat
 * geen echte API-calls plaatsvinden.
 */
class LicensePriceChangeServiceProviderAwareTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function buildService(
        ?MollieSubscriptionService $mollie = null,
        ?StripeSubscriptionService $stripe = null
    ): LicensePriceChangeService {
        return new LicensePriceChangeService(
            $mollie ?? Mockery::mock(MollieSubscriptionService::class),
            $stripe ?? Mockery::mock(StripeSubscriptionService::class)
        );
    }

    #[Test]
    public function update_subscriptions_routes_mollie_license_to_mollie_service(): void
    {
        $license = License::factory()->create([
            'amount' => 10.00,
            'upcoming_amount' => 12.00,
            'currency' => 'EUR',
        ]);

        $userLicense = UserLicense::factory()->mollie()->create([
            'license_id' => $license->id,
            'status' => 'active',
            'price_at_purchase' => 10.00,
        ]);

        $mollieMock = Mockery::mock(MollieSubscriptionService::class);
        $mollieMock->shouldReceive('updateSubscriptionAmount')
            ->once()
            ->with(
                $userLicense->mollie_customer_id,
                $userLicense->mollie_subscription_id,
                12.00,
                'EUR'
            )
            ->andReturn(['success' => true]);

        $stripeMock = Mockery::mock(StripeSubscriptionService::class);
        $stripeMock->shouldNotReceive('updatePrice');

        $service = $this->buildService($mollieMock, $stripeMock);
        $results = $service->updateSubscriptions($license);

        $this->assertEquals(1, $results['success']);
        $this->assertEquals(12.00, $userLicense->fresh()->price_at_purchase);
    }

    #[Test]
    public function update_subscriptions_routes_stripe_license_to_stripe_service(): void
    {
        $license = License::factory()->create([
            'amount' => 10.00,
            'upcoming_amount' => 15.00,
            'stripe_price_id' => 'price_new_id',
            'currency' => 'EUR',
        ]);

        $userLicense = UserLicense::factory()->stripe()->create([
            'license_id' => $license->id,
            'status' => 'active',
            'price_at_purchase' => 10.00,
        ]);

        $stripeMock = Mockery::mock(StripeSubscriptionService::class);
        $stripeMock->shouldReceive('updatePrice')
            ->once()
            ->with($userLicense->provider_subscription_id, 'price_new_id')
            ->andReturn(['success' => true, 'subscription_id' => $userLicense->provider_subscription_id]);

        $mollieMock = Mockery::mock(MollieSubscriptionService::class);
        $mollieMock->shouldNotReceive('updateSubscriptionAmount');

        $service = $this->buildService($mollieMock, $stripeMock);
        $results = $service->updateSubscriptions($license);

        $this->assertEquals(1, $results['success']);
        $this->assertEquals(15.00, $userLicense->fresh()->price_at_purchase);
    }

    #[Test]
    public function update_subscriptions_skips_stripe_license_without_stripe_price_id(): void
    {
        $license = License::factory()->create([
            'amount' => 10.00,
            'upcoming_amount' => 15.00,
            'stripe_price_id' => null,
        ]);

        UserLicense::factory()->stripe()->create([
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $stripeMock = Mockery::mock(StripeSubscriptionService::class);
        $stripeMock->shouldNotReceive('updatePrice');

        $mollieMock = Mockery::mock(MollieSubscriptionService::class);
        $mollieMock->shouldNotReceive('updateSubscriptionAmount');

        $service = $this->buildService($mollieMock, $stripeMock);
        $results = $service->updateSubscriptions($license);

        $this->assertEquals(1, $results['skipped']);
        $this->assertEquals(0, $results['success']);
    }

    #[Test]
    public function update_subscriptions_processes_mixed_provider_org_and_user_licenses(): void
    {
        $license = License::factory()->create([
            'amount' => 100.00,
            'upcoming_amount' => 120.00,
            'stripe_price_id' => 'price_xyz',
            'currency' => 'EUR',
        ]);

        $mollieUserLicense = UserLicense::factory()->mollie()->create([
            'license_id' => $license->id,
            'status' => 'active',
        ]);
        $stripeOrgLicense = OrganizationLicense::factory()->stripe()->create([
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $mollieMock = Mockery::mock(MollieSubscriptionService::class);
        $mollieMock->shouldReceive('updateSubscriptionAmount')
            ->once()
            ->andReturn(['success' => true]);

        $stripeMock = Mockery::mock(StripeSubscriptionService::class);
        $stripeMock->shouldReceive('updatePrice')
            ->once()
            ->andReturn(['success' => true, 'subscription_id' => $stripeOrgLicense->provider_subscription_id]);

        $service = $this->buildService($mollieMock, $stripeMock);
        $results = $service->updateSubscriptions($license);

        $this->assertEquals(2, $results['success']);
        $this->assertEquals(0, $results['failed']);
    }

    #[Test]
    public function update_mollie_subscriptions_deprecated_alias_still_works(): void
    {
        $license = License::factory()->create([
            'amount' => 10.00,
            'upcoming_amount' => 12.00,
            'currency' => 'EUR',
        ]);

        UserLicense::factory()->mollie()->create([
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $mollieMock = Mockery::mock(MollieSubscriptionService::class);
        $mollieMock->shouldReceive('updateSubscriptionAmount')
            ->once()
            ->andReturn(['success' => true]);

        $stripeMock = Mockery::mock(StripeSubscriptionService::class);

        $service = $this->buildService($mollieMock, $stripeMock);
        // Deprecated alias gebruikt zoals ProcessPriceChangeNotifications command
        $results = $service->updateMollieSubscriptions($license);

        $this->assertEquals(1, $results['success']);
    }

    #[Test]
    public function get_price_change_impact_includes_stripe_licenses(): void
    {
        $license = License::factory()->create(['billing_cycle' => 'yearly']);

        UserLicense::factory()->mollie()->create([
            'license_id' => $license->id,
            'status' => 'active',
        ]);
        UserLicense::factory()->stripe()->create([
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $service = $this->buildService();
        $impact = $service->getPriceChangeImpact($license);

        // Beide providers moeten meegeteld worden in totaal — vóór de fix
        // werd alleen mollie_subscription_id gefilterd, dus alleen 1 telde.
        $this->assertEquals(2, $impact['total_user_licenses']);
    }
}
