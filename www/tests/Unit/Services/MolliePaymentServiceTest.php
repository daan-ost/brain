<?php

namespace Tests\Unit\Services;

use App\Models\License;
use App\Models\Order;
use App\Services\MolliePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage voor currency/method compatibility in MolliePaymentService.
 *
 * Achtergrond: EUR-only payment methods (iDEAL, Bancontact, Belfius, KBC, EPS,
 * MyBank, Przelewy24) gaven een 422 van Mollie bij een non-EUR order omdat ze
 * niet beschikbaar zijn buiten EUR. De fix in createPayment + createFirstPayment
 * dropt zo'n method silently en laat Mollie's eigen picker een compatibele
 * method kiezen. Zie propagation doc 2026-05-13-mollie-currency-method-mismatch.md.
 */
class MolliePaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mollie.api_key' => 'test_dummy_key']);
        config(['services.mollie.webhook_url' => 'https://example.test/webhook']);
    }

    #[Test]
    public function eur_order_with_ideal_passes_method_to_mollie(): void
    {
        Http::fake([
            'api.mollie.com/v2/payments' => Http::response([
                'id' => 'tr_test',
                'status' => 'open',
                '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout']],
            ], 200),
        ]);

        $order = $this->makeOrder('EUR');
        $service = new MolliePaymentService;

        $result = $service->createPayment($order, ['country' => 'NL'], 'ideal');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($req) => ($req->data()['method'] ?? null) === 'ideal');
    }

    #[Test]
    public function usd_order_with_ideal_drops_the_method_silently(): void
    {
        Http::fake([
            'api.mollie.com/v2/payments' => Http::response([
                'id' => 'tr_test',
                'status' => 'open',
                '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout']],
            ], 200),
        ]);

        $order = $this->makeOrder('USD');
        $service = new MolliePaymentService;

        $result = $service->createPayment($order, ['country' => 'NL'], 'ideal');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($req) => ! array_key_exists('method', $req->data()));
    }

    #[Test]
    public function usd_order_with_creditcard_keeps_the_method(): void
    {
        Http::fake([
            'api.mollie.com/v2/payments' => Http::response([
                'id' => 'tr_test',
                'status' => 'open',
                '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout']],
            ], 200),
        ]);

        $order = $this->makeOrder('USD');
        $service = new MolliePaymentService;

        $result = $service->createPayment($order, ['country' => 'US'], 'creditcard');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($req) => ($req->data()['method'] ?? null) === 'creditcard');
    }

    #[Test]
    public function eur_order_currency_check_is_case_insensitive(): void
    {
        Http::fake([
            'api.mollie.com/v2/payments' => Http::response([
                'id' => 'tr_test',
                'status' => 'open',
                '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout']],
            ], 200),
        ]);

        // Lowercase eur in DB should still be treated as EUR after strtoupper
        $order = $this->makeOrder('eur');
        $service = new MolliePaymentService;

        $result = $service->createPayment($order, ['country' => 'NL'], 'bancontact');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($req) => ($req->data()['method'] ?? null) === 'bancontact');
    }

    #[Test]
    public function all_eur_only_methods_are_dropped_for_non_eur_orders(): void
    {
        $eurOnlyMethods = ['ideal', 'bancontact', 'belfius', 'kbc', 'eps', 'mybank', 'przelewy24'];

        foreach ($eurOnlyMethods as $method) {
            Http::fake([
                'api.mollie.com/v2/payments' => Http::response([
                    'id' => 'tr_'.$method,
                    'status' => 'open',
                    '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout']],
                ], 200),
            ]);

            $order = $this->makeOrder('GBP');
            $service = new MolliePaymentService;
            $result = $service->createPayment($order, ['country' => 'GB'], $method);

            $this->assertTrue($result['success'], "EUR-only method {$method} should not block payment creation for GBP order");
            Http::assertSent(
                fn ($req) => ! array_key_exists('method', $req->data()),
                "EUR-only method {$method} should be dropped from payload for non-EUR order"
            );
        }
    }

    private function makeOrder(string $currency): Order
    {
        $license = License::factory()->create();

        return Order::factory()->create([
            'license_id' => $license->id,
            'currency' => $currency,
            'gross_amount' => 9.99,
        ]);
    }
}
