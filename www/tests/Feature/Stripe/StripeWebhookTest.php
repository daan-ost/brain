<?php

use App\Enums\LicenseStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\UserLicense;
use App\Models\WebhookEvent;
use App\Services\InvoiceGenerationService;
use App\Services\LicenseCreditResetService;
use App\Services\Payments\StripeWebhookService;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Helper — build a Stripe Event from raw data (no API call)
// ---------------------------------------------------------------------------

function stripeEvent(string $type, array $dataObject, ?string $eventId = null): \Stripe\Event
{
    return \Stripe\Event::constructFrom([
        'id' => $eventId ?? 'evt_'.Str::random(16),
        'object' => 'event',
        'type' => $type,
        'livemode' => false,
        'created' => time(),
        'data' => ['object' => $dataObject],
    ]);
}

function stripeWebhookHeader(string $payload, string $secret): string
{
    $timestamp = time();
    $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$sig}";
}

// ---------------------------------------------------------------------------
// StripeWebhookService — checkout.session.completed
// ---------------------------------------------------------------------------

describe('StripeWebhookService — checkout.session.completed', function () {

    beforeEach(function () {
        $this->mock(InvoiceGenerationService::class, function ($mock) {
            $mock->shouldReceive('generateInvoice')
                ->andReturn(['invoice_number' => 'INV-TEST-001', 'already_exists' => false]);
        });
        $this->mock(LicenseCreditResetService::class, function ($mock) {
            $mock->shouldReceive('processPremiumReset')->andReturn(true);
            $mock->shouldReceive('processOrganizationPremiumReset')->andReturn(true);
        });
    });

    it('marks order paid and fulfills onetime order', function () {
        $user = \App\Models\User::factory()->create(['credits' => 0]);
        $license = \App\Models\License::factory()->onetime()->create(['credits' => 50]);
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'status' => OrderStatus::Initiated,
            'payment_provider' => 'stripe',
            'provider_payment_id' => null,
            'paid_at' => null,
        ]);

        $event = stripeEvent('checkout.session.completed', [
            'id' => 'cs_test_abc123',
            'mode' => 'payment',
            'customer' => 'cus_test123',
            'subscription' => null,
            'payment_intent' => 'pi_test123',
            'metadata' => ['order_id' => $order->id],
        ]);

        app(StripeWebhookService::class)->handle($event);

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Paid);
        expect($order->provider_payment_id)->toBe('cs_test_abc123');
        expect($order->provider_customer_id)->toBe('cus_test123');
        expect($order->paid_at)->not->toBeNull();
    });

    it('activates subscription license on checkout.session.completed with mode=subscription', function () {
        $user = \App\Models\User::factory()->create();
        $license = \App\Models\License::factory()->premium()->create(['payment_provider' => 'stripe']);
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'inactive',
            'payment_provider' => 'stripe',
            'provider_subscription_id' => null,
            'provider_customer_id' => null,
        ]);
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'status' => OrderStatus::Initiated,
            'payment_provider' => 'stripe',
        ]);

        $event = stripeEvent('checkout.session.completed', [
            'id' => 'cs_test_sub456',
            'mode' => 'subscription',
            'customer' => 'cus_sub123',
            'subscription' => 'sub_test456',
            'payment_intent' => null,
            'metadata' => ['order_id' => $order->id],
        ]);

        app(StripeWebhookService::class)->handle($event);

        $userLicense->refresh();
        expect($userLicense->provider_subscription_id)->toBe('sub_test456');
        expect($userLicense->provider_customer_id)->toBe('cus_sub123');
    });

    it('does nothing when order_id is missing in metadata', function () {
        $initialOrderCount = Order::count();

        $event = stripeEvent('checkout.session.completed', [
            'id' => 'cs_no_meta',
            'mode' => 'payment',
            'customer' => 'cus_test',
            'subscription' => null,
            'metadata' => [],
        ]);

        app(StripeWebhookService::class)->handle($event);

        expect(Order::count())->toBe($initialOrderCount);
    });
});

// ---------------------------------------------------------------------------
// StripeWebhookService — invoice.payment_succeeded
// ---------------------------------------------------------------------------

describe('StripeWebhookService — invoice.payment_succeeded', function () {

    beforeEach(function () {
        $this->mock(InvoiceGenerationService::class, function ($mock) {
            $mock->shouldReceive('generateInvoice')
                ->andReturn(['invoice_number' => 'INV-TEST-001', 'already_exists' => false]);
        });
        $this->mock(LicenseCreditResetService::class, function ($mock) {
            $mock->shouldReceive('processPremiumReset')->andReturn(true);
            $mock->shouldReceive('processOrganizationPremiumReset')->andReturn(true);
        });
    });

    it('creates renewal order for subscription_cycle billing_reason', function () {
        $user = \App\Models\User::factory()->create();
        $license = \App\Models\License::factory()->premium()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_renewal_test',
        ]);

        $initialOrderCount = Order::count();

        $event = stripeEvent('invoice.payment_succeeded', [
            'id' => 'in_renewal123',
            'billing_reason' => 'subscription_cycle',
            'subscription' => 'sub_renewal_test',
            'customer' => 'cus_test',
            'payment_intent' => 'pi_renewal123',
            'amount_paid' => 11900,
            'currency' => 'eur',
        ]);

        app(StripeWebhookService::class)->handle($event);

        expect(Order::count())->toBe($initialOrderCount + 1);

        $renewalOrder = Order::latest()->first();
        expect($renewalOrder->type)->toBe('subscription');
        expect($renewalOrder->payment_provider)->toBe('stripe');
        expect($renewalOrder->provider_subscription_id)->toBe('sub_renewal_test');
        expect($renewalOrder->status)->toBe(OrderStatus::Paid);
    });

    it('ignores invoice.payment_succeeded when billing_reason is not subscription_cycle', function () {
        $initialOrderCount = Order::count();

        $event = stripeEvent('invoice.payment_succeeded', [
            'id' => 'in_first_payment',
            'billing_reason' => 'subscription_create',
            'subscription' => 'sub_first',
            'customer' => 'cus_test',
            'payment_intent' => 'pi_first',
            'amount_paid' => 11900,
            'currency' => 'eur',
        ]);

        app(StripeWebhookService::class)->handle($event);

        expect(Order::count())->toBe($initialOrderCount);
    });

    it('resets past_due status to active on successful renewal payment', function () {
        $user = \App\Models\User::factory()->create();
        $license = \App\Models\License::factory()->premium()->create();
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => LicenseStatus::PastDue->value,
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_past_due',
        ]);

        $event = stripeEvent('invoice.payment_succeeded', [
            'id' => 'in_recovery',
            'billing_reason' => 'subscription_cycle',
            'subscription' => 'sub_past_due',
            'customer' => 'cus_test',
            'payment_intent' => 'pi_recovery',
            'amount_paid' => 11900,
            'currency' => 'eur',
        ]);

        app(StripeWebhookService::class)->handle($event);

        $userLicense->refresh();
        expect($userLicense->status)->toBe(LicenseStatus::Active->value);
    });
});

// ---------------------------------------------------------------------------
// StripeWebhookService — invoice.payment_failed
// ---------------------------------------------------------------------------

describe('StripeWebhookService — invoice.payment_failed', function () {

    it('sets license to past_due when invoice payment fails', function () {
        $user = \App\Models\User::factory()->create();
        $license = \App\Models\License::factory()->premium()->create();
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_failing',
        ]);

        $event = stripeEvent('invoice.payment_failed', [
            'id' => 'in_failed123',
            'subscription' => 'sub_failing',
            'customer' => 'cus_test',
            'amount_due' => 11900,
            'currency' => 'eur',
        ]);

        app(StripeWebhookService::class)->handle($event);

        $userLicense->refresh();
        expect($userLicense->status)->toBe(LicenseStatus::PastDue->value);
    });

    it('does nothing when invoice.payment_failed has no subscription', function () {
        $event = stripeEvent('invoice.payment_failed', [
            'id' => 'in_no_sub',
            'subscription' => null,
            'customer' => 'cus_test',
        ]);

        expect(fn () => app(StripeWebhookService::class)->handle($event))->not->toThrow(\Throwable::class);
    });
});

// ---------------------------------------------------------------------------
// StripeWebhookService — customer.subscription.deleted
// ---------------------------------------------------------------------------

describe('StripeWebhookService — customer.subscription.deleted', function () {

    it('cancels license when subscription is deleted', function () {
        $user = \App\Models\User::factory()->create();
        $license = \App\Models\License::factory()->premium()->create();
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_to_delete',
            'ends_at' => null,
        ]);

        $periodEnd = now()->addDays(15)->timestamp;

        $event = stripeEvent('customer.subscription.deleted', [
            'id' => 'sub_to_delete',
            'status' => 'canceled',
            'current_period_end' => $periodEnd,
        ]);

        app(StripeWebhookService::class)->handle($event);

        $userLicense->refresh();
        expect($userLicense->status)->toBe(LicenseStatus::Canceled->value);
        expect($userLicense->ends_at)->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// StripeWebhookService — customer.subscription.updated
// ---------------------------------------------------------------------------

describe('StripeWebhookService — customer.subscription.updated', function () {

    it('sets license to past_due when subscription becomes unpaid', function () {
        $user = \App\Models\User::factory()->create();
        $license = \App\Models\License::factory()->premium()->create();
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_going_unpaid',
        ]);

        $event = stripeEvent('customer.subscription.updated', [
            'id' => 'sub_going_unpaid',
            'status' => 'unpaid',
            'cancel_at_period_end' => false,
        ]);

        app(StripeWebhookService::class)->handle($event);

        $userLicense->refresh();
        expect($userLicense->status)->toBe(LicenseStatus::PastDue->value);
    });

    it('does not change license status when subscription is scheduled for cancellation', function () {
        $user = \App\Models\User::factory()->create();
        $license = \App\Models\License::factory()->premium()->create();
        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'payment_provider' => 'stripe',
            'provider_subscription_id' => 'sub_cancel_scheduled',
        ]);

        $event = stripeEvent('customer.subscription.updated', [
            'id' => 'sub_cancel_scheduled',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'cancel_at' => now()->addMonth()->timestamp,
        ]);

        app(StripeWebhookService::class)->handle($event);

        $userLicense->refresh();
        expect($userLicense->status)->toBe('active');
    });
});

// ---------------------------------------------------------------------------
// StripeWebhookService — charge.refunded
// ---------------------------------------------------------------------------

describe('StripeWebhookService — charge.refunded', function () {

    it('marks order as refunded when charge is refunded', function () {
        $order = Order::factory()->create([
            'status' => OrderStatus::Paid,
            'payment_provider' => 'stripe',
            'provider_payment_id' => 'pi_to_refund',
        ]);

        $event = stripeEvent('charge.refunded', [
            'id' => 'ch_test123',
            'payment_intent' => 'pi_to_refund',
            'amount_refunded' => 11900,
            'currency' => 'eur',
        ]);

        app(StripeWebhookService::class)->handle($event);

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Refunded);
    });

    it('does nothing when no order found for payment_intent', function () {
        $event = stripeEvent('charge.refunded', [
            'id' => 'ch_no_order',
            'payment_intent' => 'pi_nonexistent_xyz',
            'amount_refunded' => 5000,
            'currency' => 'eur',
        ]);

        expect(fn () => app(StripeWebhookService::class)->handle($event))->not->toThrow(\Throwable::class);
    });
});

// ---------------------------------------------------------------------------
// HTTP controller — signature verification + idempotency
// ---------------------------------------------------------------------------

describe('StripeWebhookController HTTP', function () {

    beforeEach(function () {
        config([
            'services.stripe.webhook_secret' => 'whsec_test_secret_key',
            'services.stripe.secret_key' => 'sk_test_fake_key_for_tests',
        ]);

        $this->mock(InvoiceGenerationService::class, function ($mock) {
            $mock->shouldReceive('generateInvoice')->andReturn(['invoice_number' => 'INV-TEST', 'already_exists' => false]);
        });
    });

    it('returns 400 for invalid signature', function () {
        $payload = json_encode(['id' => 'evt_bad', 'type' => 'charge.refunded']);

        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=12345,v1=invalidsignature',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(400);
    });

    it('returns 200 and records event for valid signed webhook', function () {
        $eventId = 'evt_valid_'.Str::random(8);

        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'customer.subscription.updated',
            'livemode' => false,
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'sub_http_test',
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                ],
            ],
        ]);

        $secret = config('services.stripe.webhook_secret');
        $header = stripeWebhookHeader($payload, $secret);

        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(200);
        expect(WebhookEvent::where('event_id', $eventId)->exists())->toBeTrue();
    });

    it('returns 200 and skips already processed event (idempotency)', function () {
        $eventId = 'evt_already_processed_'.Str::random(6);

        WebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => $eventId,
            'event_type' => 'charge.refunded',
            'payload' => [],
            'processed_at' => now(),
        ]);

        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'charge.refunded',
            'livemode' => false,
            'created' => time(),
            'data' => ['object' => ['id' => 'ch_test', 'payment_intent' => 'pi_dup']],
        ]);

        $secret = config('services.stripe.webhook_secret');
        $header = stripeWebhookHeader($payload, $secret);

        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(200);
        $response->assertSeeText('Already processed');
    });
});
