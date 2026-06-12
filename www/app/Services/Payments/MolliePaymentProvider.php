<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Models\Order;
use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use App\Services\MolliePaymentService;
use App\Services\MollieSubscriptionService;
use RuntimeException;

class MolliePaymentProvider implements PaymentProvider
{
    public function __construct(
        private MolliePaymentService $paymentService,
        private MollieSubscriptionService $subscriptionService,
    ) {}

    public function name(): string
    {
        return 'mollie';
    }

    public function createCheckout(Order $order, array $billingDetails, ?string $paymentMethod = null): array
    {
        $result = $this->paymentService->createPayment($order, $billingDetails, $paymentMethod);

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException('Mollie payment creation failed: '.($result['message'] ?? 'unknown error'));
        }

        return [
            'checkout_url' => $result['data']['_links']['checkout']['href'],
            'provider_payment_id' => $result['data']['id'],
        ];
    }

    public function createSubscriptionCheckout(Order $order, array $billingDetails, ?string $paymentMethod = null): array
    {
        $result = $this->paymentService->createFirstPayment($order, $billingDetails, $paymentMethod);

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException('Mollie first payment creation failed: '.($result['message'] ?? 'unknown error'));
        }

        return [
            'checkout_url' => $result['data']['_links']['checkout']['href'],
            'provider_payment_id' => $result['data']['id'],
            'provider_customer_id' => $result['data']['customerId'] ?? null,
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        $license = UserLicense::where('mollie_subscription_id', $subscriptionId)->first()
            ?? OrganizationLicense::where('mollie_subscription_id', $subscriptionId)->first();

        if (! $license?->mollie_customer_id) {
            return false;
        }

        $result = $this->subscriptionService->cancelSubscription($license->mollie_customer_id, $subscriptionId);

        return $result['success'] ?? false;
    }

    public function customerPortalUrl(string $customerId, string $returnUrl): ?string
    {
        // Mollie heeft geen hosted customer portal
        return null;
    }
}
