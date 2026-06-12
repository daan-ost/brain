<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Customer;

class StripeCheckoutService
{
    public function __construct()
    {
        if (empty(config('services.stripe.secret_key'))) {
            throw new RuntimeException('Stripe secret key is not configured. Please set STRIPE_SECRET_KEY in your environment.');
        }
    }

    /**
     * Create a Stripe Checkout Session for a one-time payment.
     */
    public function createOneTimeSession(Order $order, array $billingDetails): array
    {
        $customerId = $this->findOrCreateCustomer($order, $billingDetails);

        $session = Session::create([
            'mode' => 'payment',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        'unit_amount' => $this->toCents($order->gross_amount),
                        // gross_amount is incl. BTW — markeer als inclusive zodat
                        // Stripe-rapportages en eventuele toekomstige automatic_tax-toggle
                        // het bedrag correct interpreteren.
                        'tax_behavior' => 'inclusive',
                        'product_data' => [
                            'name' => $order->license->name ?? 'Credits',
                            'metadata' => [
                                'license_id' => $order->license_id,
                            ],
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'order_id' => $order->id,
                'payer_type' => $order->payer_type,
                'payer_id' => (string) $order->payer_id,
                'license_id' => (string) $order->license_id,
                'type' => 'onetime',
                // Reconciliation hint voor revenue reports
                'net_amount_eur' => (string) $order->net_amount,
                'tax_amount_eur' => (string) $order->tax_amount,
            ],
            'success_url' => route('checkout.return', ['o' => $order->id]).'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.wizard').'?canceled=1',
            'tax_id_collection' => ['enabled' => false],
            'automatic_tax' => ['enabled' => false],
        ]);

        Log::info('Stripe one-time session created', [
            'order_id' => $order->id,
            'session_id' => $session->id,
            'customer_id' => $customerId,
        ]);

        return [
            'checkout_url' => $session->url,
            'provider_payment_id' => $session->id,
            'provider_customer_id' => $customerId,
        ];
    }

    /**
     * Create a Stripe Checkout Session for a subscription.
     * Requires License.stripe_price_id to be set (via stripe:sync-prices).
     */
    public function createSubscriptionSession(Order $order, array $billingDetails): array
    {
        $priceId = $order->license->stripe_price_id ?? null;

        if (empty($priceId)) {
            throw new RuntimeException(
                "License #{$order->license_id} has no stripe_price_id. Run php artisan stripe:sync-prices first."
            );
        }

        $customerId = $this->findOrCreateCustomer($order, $billingDetails);

        $session = Session::create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'subscription_data' => [
                'metadata' => [
                    'order_id' => $order->id,
                    'payer_type' => $order->payer_type,
                    'payer_id' => (string) $order->payer_id,
                    'license_id' => (string) $order->license_id,
                ],
            ],
            'metadata' => [
                'order_id' => $order->id,
                'payer_type' => $order->payer_type,
                'payer_id' => (string) $order->payer_id,
                'license_id' => (string) $order->license_id,
                'type' => 'subscription',
            ],
            'success_url' => route('checkout.return', ['o' => $order->id]).'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.wizard').'?canceled=1',
            'automatic_tax' => ['enabled' => false],
        ]);

        Log::info('Stripe subscription session created', [
            'order_id' => $order->id,
            'session_id' => $session->id,
            'customer_id' => $customerId,
            'price_id' => $priceId,
        ]);

        return [
            'checkout_url' => $session->url,
            'provider_payment_id' => $session->id,
            'provider_customer_id' => $customerId,
        ];
    }

    /**
     * Find an existing Stripe customer for this payer, or create one.
     */
    public function findOrCreateCustomer(Order $order, array $billingDetails): string
    {
        // Reuse existing customer from previous order or license
        $existing = $this->findExistingCustomerId($order);
        if ($existing) {
            return $existing;
        }

        $name = trim(($billingDetails['first_name'] ?? '').' '.($billingDetails['last_name'] ?? ''))
            ?: ($billingDetails['company_name'] ?? 'Customer');

        $customer = Customer::create([
            'email' => $billingDetails['email'] ?? $order->billing_snapshot['email'] ?? null,
            'name' => $name,
            'address' => [
                'line1' => $billingDetails['address'] ?? null,
                'city' => $billingDetails['city'] ?? null,
                'postal_code' => $billingDetails['postal_code'] ?? null,
                'country' => strtoupper($billingDetails['country'] ?? $order->country ?? 'NL'),
            ],
            'metadata' => [
                'payer_type' => $order->payer_type,
                'payer_id' => (string) $order->payer_id,
            ],
        ]);

        Log::info('Stripe customer created', [
            'customer_id' => $customer->id,
            'payer_type' => $order->payer_type,
            'payer_id' => $order->payer_id,
        ]);

        return $customer->id;
    }

    private function findExistingCustomerId(Order $order): ?string
    {
        // Check previous orders for the same payer with a Stripe customer
        $previous = Order::where('payer_type', $order->payer_type)
            ->where('payer_id', $order->payer_id)
            ->where('payment_provider', 'stripe')
            ->whereNotNull('provider_customer_id')
            ->latest()
            ->value('provider_customer_id');

        return $previous;
    }

    /**
     * Convert a decimal euro amount to Stripe's expected integer cents.
     */
    public function toCents(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Retrieve a Checkout Session via the Stripe API. Gebruikt door
     * polling-fallback in CheckoutController als de webhook traag arriveert
     * en de klant op de processing-pagina wacht.
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public function retrieveSession(string $sessionId): array
    {
        try {
            $session = Session::retrieve($sessionId);

            return [
                'success' => true,
                'data' => [
                    'id' => $session->id,
                    'status' => $session->status, // 'open' | 'complete' | 'expired'
                    'payment_status' => $session->payment_status, // 'unpaid' | 'paid' | 'no_payment_required'
                    'payment_intent' => $session->payment_intent,
                    'subscription' => $session->subscription ?? null,
                    'customer' => $session->customer ?? null,
                ],
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe session retrieve failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
