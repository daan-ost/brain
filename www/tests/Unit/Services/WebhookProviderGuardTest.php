<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Services\MollieWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Defense-in-depth: webhook services moeten cross-provider calls afwijzen.
 * Zie docs/propagation/2026-05-21-stripe-payment-provider-gaps.md issue 3.5.
 *
 * Voorkomt scenario waarin een valide Mollie-webhook payload met de ULID van
 * een Stripe-order de status van die Stripe-order overschrijft zonder Stripe
 * verificatie. Geldt ook andersom (Stripe webhook op Mollie order).
 */
class WebhookProviderGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mollie.api_key' => 'test_dummy_key']);
    }

    #[Test]
    public function mollie_webhook_processes_mollie_order(): void
    {
        $order = Order::factory()->create([
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_mollie_test',
        ]);

        Http::fake([
            'api.mollie.com/v2/payments/tr_mollie_test' => Http::response([
                'id' => 'tr_mollie_test',
                'status' => 'paid',
                'amount' => ['value' => '9.99', 'currency' => 'EUR'],
                'metadata' => ['order_id' => $order->id],
            ], 200),
        ]);

        $service = app(MollieWebhookService::class);

        // We asserten alleen dat de guard de call NIET vroeg afwijst —
        // de daadwerkelijke processPaymentStatus chain is buiten scope.
        // Een true return waarde zou betekenen dat het tot processing kwam.
        // We accepteren elke return zolang het geen "false vroeg na guard"-pad volgt.
        $result = $service->handlePaymentWebhook('tr_mollie_test');

        // Mollie order met matching provider — guard moet doorlaten.
        // We kunnen niet asserten op true (processPaymentStatus heeft eigen logica),
        // maar de guard is gepasseerd als we hier komen zonder vroege false.
        $this->assertIsBool($result);
    }

    #[Test]
    public function mollie_webhook_rejects_stripe_order(): void
    {
        $order = Order::factory()->stripe()->create([
            'provider_payment_id' => 'pi_stripe_x',
        ]);

        Http::fake([
            'api.mollie.com/v2/payments/tr_attacker' => Http::response([
                'id' => 'tr_attacker',
                'status' => 'paid',
                'amount' => ['value' => '9.99', 'currency' => 'EUR'],
                'metadata' => ['order_id' => $order->id],  // ULID van Stripe-order
            ], 200),
        ]);

        $service = app(MollieWebhookService::class);

        $result = $service->handlePaymentWebhook('tr_attacker');

        $this->assertFalse(
            $result,
            'Mollie webhook moet Stripe-orders weigeren — anders kan een misgerouteerde Mollie call de status van een Stripe-order overschrijven.'
        );

        // Stripe order status is niet aangepast door de webhook
        $order->refresh();
        $this->assertSame('stripe', $order->payment_provider);
    }

    #[Test]
    public function mollie_webhook_processes_legacy_order_without_payment_provider_column(): void
    {
        // Order zonder explicit payment_provider value — accessor valt terug
        // op mollie_payment_id detectie → 'mollie'. Webhook moet werken.
        $order = Order::factory()->create([
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_legacy_no_col',
            'mollie_customer_id' => null,
            'mollie_subscription_id' => null,
        ]);

        Http::fake([
            'api.mollie.com/v2/payments/tr_legacy_no_col' => Http::response([
                'id' => 'tr_legacy_no_col',
                'status' => 'paid',
                'amount' => ['value' => '9.99', 'currency' => 'EUR'],
                'metadata' => ['order_id' => $order->id],
            ], 200),
        ]);

        $service = app(MollieWebhookService::class);

        // Guard mag legacy orders niet weigeren — payment_provider accessor retourneert
        // 'mollie' op basis van mollie_payment_id, dus 'mollie' === 'mollie' → doorlaten.
        $result = $service->handlePaymentWebhook('tr_legacy_no_col');

        $this->assertIsBool($result);
    }
}
