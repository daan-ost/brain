<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Models\Order;

class StripePaymentProvider implements PaymentProvider
{
    public function __construct(
        private StripeCheckoutService $checkoutService,
        private StripeSubscriptionService $subscriptionService,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckout(Order $order, array $billingDetails, ?string $paymentMethod = null): array
    {
        return $this->checkoutService->createOneTimeSession($order, $billingDetails);
    }

    public function createSubscriptionCheckout(Order $order, array $billingDetails, ?string $paymentMethod = null): array
    {
        return $this->checkoutService->createSubscriptionSession($order, $billingDetails);
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return $this->subscriptionService->cancel($subscriptionId);
    }

    public function customerPortalUrl(string $customerId, string $returnUrl): ?string
    {
        return $this->subscriptionService->createPortalSession($customerId, $returnUrl);
    }
}
