<?php

namespace Tests\Unit\Services;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Asymmetrische coverage voor Stripe-gaps in PaymentFulfillmentService.
 *
 * Verifieert dat de provider-aware fixes uit
 * docs/propagation/2026-05-21-stripe-payment-provider-gaps.md
 * zowel de Mollie- als Stripe-tak correct afdekken. Mollie-regressie
 * vangen we expliciet via paired tests.
 *
 * Tests reiken via Reflection naar de private isOrderAlreadyFulfilled /
 * determineSource methods — bewust, deze zijn pure helpers die direct
 * over Order/UserLicense state werken zonder dependencies.
 */
class PaymentFulfillmentServiceStripeGapsTest extends TestCase
{
    use RefreshDatabase;

    private \App\Services\PaymentFulfillmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(\App\Services\PaymentFulfillmentService::class);
    }

    private function callPrivate(string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($this->service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->service, $args);
    }

    // --- isOrderAlreadyFulfilled ------------------------------------------------

    #[Test]
    public function mollie_order_with_existing_license_is_detected_as_fulfilled(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_existing',
        ]);

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'source' => 'mollie',
            'external_ref' => 'tr_existing',
        ]);

        $this->assertTrue($this->callPrivate('isOrderAlreadyFulfilled', [$order]));
    }

    #[Test]
    public function stripe_order_with_existing_license_is_detected_as_fulfilled(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $order = Order::factory()->stripe()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'provider_payment_id' => 'pi_stripe_test',
        ]);

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'source' => 'stripe',
            'external_ref' => 'pi_stripe_test',
        ]);

        $this->assertTrue(
            $this->callPrivate('isOrderAlreadyFulfilled', [$order]),
            'Stripe order met matching license moet als fulfilled detected — anders kan webhook retry een dubbele license maken.'
        );
    }

    #[Test]
    public function stripe_order_without_matching_license_is_not_fulfilled(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $order = Order::factory()->stripe()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'provider_payment_id' => 'pi_no_match',
        ]);

        // Andere user heeft wel een license, maar niet voor deze order
        $otherUser = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $otherUser->id,
            'license_id' => $license->id,
            'source' => 'stripe',
            'external_ref' => 'pi_other',
        ]);

        $this->assertFalse($this->callPrivate('isOrderAlreadyFulfilled', [$order]));
    }

    // --- determineSource --------------------------------------------------------

    #[Test]
    public function determine_source_returns_stripe_payment_for_stripe_order(): void
    {
        $order = Order::factory()->stripe()->create();

        $this->assertSame('stripe_payment', $this->callPrivate('determineSource', ['purchase', $order]));
    }

    #[Test]
    public function determine_source_returns_stripe_subscription_for_stripe_subscription_order(): void
    {
        $order = Order::factory()->stripe()->subscription()->create();

        $this->assertSame('stripe_subscription', $this->callPrivate('determineSource', ['subscription', $order]));
    }

    #[Test]
    public function determine_source_returns_mollie_payment_for_mollie_order(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_x',
        ]);

        $this->assertSame('mollie_payment', $this->callPrivate('determineSource', ['purchase', $order]));
    }

    #[Test]
    public function determine_source_falls_back_to_meta_for_legacy_orders_without_payment_provider_column(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => null,
            'mollie_payment_id' => null,
            'meta' => ['payment_provider' => 'invoice'],
        ]);

        $this->assertSame('invoice_payment', $this->callPrivate('determineSource', ['purchase', $order]));
    }

    #[Test]
    public function determine_source_returns_manual_for_null_order(): void
    {
        $this->assertSame('manual', $this->callPrivate('determineSource', ['admin_grant', null]));
    }
}
