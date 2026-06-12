<?php

namespace App\Contracts;

use App\Models\Order;

interface PaymentProvider
{
    public function name(): string;

    /**
     * Create a one-time payment checkout.
     * Returns ['checkout_url' => string, 'provider_payment_id' => string]
     */
    public function createCheckout(Order $order, array $billingDetails, ?string $paymentMethod = null): array;

    /**
     * Create the first payment for a subscription (collects mandate / payment method).
     * Returns ['checkout_url' => string, 'provider_payment_id' => string]
     */
    public function createSubscriptionCheckout(Order $order, array $billingDetails, ?string $paymentMethod = null): array;

    /**
     * Cancel a subscription at end of current period.
     */
    public function cancelSubscription(string $subscriptionId): bool;

    /**
     * Return a URL where the customer can self-manage their subscription.
     * Returns null when the provider does not support a hosted portal.
     */
    public function customerPortalUrl(string $customerId, string $returnUrl): ?string;
}
