<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage voor provider-agnostische accessors op Order.
 *
 * Achtergrond: na de Stripe-propagation hebben Orders zowel legacy
 * mollie_*_id kolommen als nieuwe provider_*_id + payment_provider
 * kolommen. De accessors abstraheren daar overheen. Zie de propagation
 * doc `2026-05-19-admin-user-diagnostics.md` voor context.
 */
class OrderPaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function payment_provider_returns_explicit_column_when_set(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => 'stripe',
            'mollie_payment_id' => 'tr_legacy123',
        ]);

        $this->assertSame('stripe', $order->payment_provider);
    }

    #[Test]
    public function payment_provider_falls_back_to_mollie_when_only_legacy_id_present(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_abc',
        ]);

        $this->assertSame('mollie', $order->payment_provider);
    }

    #[Test]
    public function payment_provider_is_null_when_no_provider_data_present(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => null,
            'mollie_payment_id' => null,
            'mollie_customer_id' => null,
            'mollie_subscription_id' => null,
        ]);

        $this->assertNull($order->payment_provider);
    }

    #[Test]
    public function provider_payment_id_prefers_new_column_over_legacy(): void
    {
        $order = Order::factory()->create([
            'provider_payment_id' => 'pi_new',
            'mollie_payment_id' => 'tr_old',
        ]);

        $this->assertSame('pi_new', $order->provider_payment_id);
    }

    #[Test]
    public function provider_payment_id_falls_back_to_mollie_when_only_legacy_set(): void
    {
        $order = Order::factory()->create([
            'provider_payment_id' => null,
            'mollie_payment_id' => 'tr_legacy',
        ]);

        $this->assertSame('tr_legacy', $order->provider_payment_id);
    }

    #[Test]
    public function provider_dashboard_url_builds_mollie_url_for_legacy_order(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => null,
            'provider_payment_id' => null,
            'mollie_payment_id' => 'tr_abc',
        ]);

        $this->assertSame(
            'https://my.mollie.com/dashboard/payments/tr_abc',
            $order->provider_dashboard_url,
        );
    }

    #[Test]
    public function provider_dashboard_url_builds_stripe_url_for_stripe_order(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => 'stripe',
            'provider_payment_id' => 'pi_xyz',
            'mollie_payment_id' => null,
        ]);

        $this->assertSame(
            'https://dashboard.stripe.com/payments/pi_xyz',
            $order->provider_dashboard_url,
        );
    }

    #[Test]
    public function provider_dashboard_url_is_null_when_no_payment_id(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => 'stripe',
            'provider_payment_id' => null,
            'mollie_payment_id' => null,
        ]);

        $this->assertNull($order->provider_dashboard_url);
    }
}
