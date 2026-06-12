<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use App\Models\OrganizationLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage voor de provider-aware order() accessor op OrganizationLicense.
 * Zie docs/propagation/2026-05-21-stripe-payment-provider-gaps.md issue 3.2.
 */
class OrganizationLicenseOrderRelationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function order_accessor_resolves_legacy_mollie_payment_id(): void
    {
        $order = Order::factory()->create([
            'mollie_payment_id' => 'tr_legacy_match',
        ]);

        $orgLicense = OrganizationLicense::factory()->create([
            'external_ref' => 'tr_legacy_match',
        ]);

        $this->assertNotNull($orgLicense->order);
        $this->assertSame($order->id, $orgLicense->order->id);
    }

    #[Test]
    public function order_accessor_resolves_stripe_provider_payment_id(): void
    {
        $order = Order::factory()->stripe()->create([
            'provider_payment_id' => 'pi_stripe_match',
        ]);

        $orgLicense = OrganizationLicense::factory()->stripe()->create([
            'external_ref' => 'pi_stripe_match',
        ]);

        $this->assertNotNull(
            $orgLicense->order,
            'Stripe order moet via provider_payment_id koppelen — anders breekt invoice-/factuur-koppeling voor Stripe org-licenses.'
        );
        $this->assertSame($order->id, $orgLicense->order->id);
    }

    #[Test]
    public function order_accessor_resolves_via_mollie_subscription_id(): void
    {
        $order = Order::factory()->subscription()->create([
            'mollie_subscription_id' => 'sub_legacy_match',
        ]);

        $orgLicense = OrganizationLicense::factory()->subscription()->create([
            'external_ref' => 'sub_legacy_match',
            'mollie_subscription_id' => 'sub_legacy_match',
        ]);

        $this->assertNotNull($orgLicense->order);
        $this->assertSame($order->id, $orgLicense->order->id);
    }

    #[Test]
    public function order_accessor_resolves_via_provider_subscription_id(): void
    {
        $order = Order::factory()->stripe()->subscription()->create([
            'provider_subscription_id' => 'sub_stripe_match',
        ]);

        $orgLicense = OrganizationLicense::factory()->stripe()->create([
            'external_ref' => 'sub_stripe_match',
            'provider_subscription_id' => 'sub_stripe_match',
        ]);

        $this->assertNotNull($orgLicense->order);
        $this->assertSame($order->id, $orgLicense->order->id);
    }

    #[Test]
    public function order_accessor_returns_null_when_no_external_ref(): void
    {
        $orgLicense = OrganizationLicense::factory()->create([
            'external_ref' => null,
        ]);

        $this->assertNull($orgLicense->order);
    }

    #[Test]
    public function order_accessor_returns_null_when_external_ref_does_not_match_any_order(): void
    {
        $orgLicense = OrganizationLicense::factory()->create([
            'external_ref' => 'tr_no_such_order',
        ]);

        $this->assertNull($orgLicense->order);
    }
}
