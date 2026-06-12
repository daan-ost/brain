<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\AnalyticsService;

class MollieWebhookService
{
    private MolliePaymentService $paymentService;

    private MollieSubscriptionService $subscriptionService;

    private PaymentFulfillmentService $fulfillmentService;

    public function __construct(
        MolliePaymentService $paymentService,
        MollieSubscriptionService $subscriptionService,
        PaymentFulfillmentService $fulfillmentService
    ) {
        $this->paymentService = $paymentService;
        $this->subscriptionService = $subscriptionService;
        $this->fulfillmentService = $fulfillmentService;
    }

    /**
     * Handle payment webhook (paid, canceled, expired, failed)
     */
    public function handlePaymentWebhook(string $paymentId): bool
    {
        $paymentResult = $this->paymentService->getPayment($paymentId);

        if (! $paymentResult['success']) {
            Log::error('Failed to retrieve payment from Mollie', [
                'payment_id' => $paymentId,
                'error' => $paymentResult['error'] ?? 'Unknown error',
            ]);

            return false;
        }

        $payment = $paymentResult['data'];
        $orderId = $payment['metadata']['order_id'] ?? null;

        if (! $orderId) {
            Log::error('No order_id in payment metadata', [
                'payment_id' => $paymentId,
                'metadata' => $payment['metadata'] ?? [],
            ]);

            return false;
        }

        $order = Order::find($orderId);
        if (! $order) {
            Log::error('Order not found for payment', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);

            return false;
        }

        // Defense-in-depth: weiger Mollie-webhook calls op orders die expliciet
        // bij een andere provider horen. Voorkomt cross-provider corruption via
        // replay attacks of misgerouteerde test-calls.
        $orderProvider = $order->payment_provider;
        if ($orderProvider && $orderProvider !== 'mollie') {
            Log::warning('Mollie webhook ignored — order belongs to different provider', [
                'payment_id' => $paymentId,
                'order_id' => $order->id,
                'order_provider' => $orderProvider,
            ]);

            return false;
        }

        return $this->processPaymentStatus($order, $payment);
    }

    /**
     * Handle subscription webhook (created, activated, canceled, payment_failed)
     */
    public function handleSubscriptionWebhook(string $subscriptionId): bool
    {
        Log::info('Subscription webhook received', ['subscription_id' => $subscriptionId]);

        // Find license by subscription ID
        $license = $this->findLicenseBySubscriptionId($subscriptionId);

        if (! $license) {
            Log::warning('No license found for subscription webhook', [
                'subscription_id' => $subscriptionId,
            ]);

            return false;
        }

        $customerId = $license->mollie_customer_id;
        if (! $customerId) {
            Log::error('No customer ID found for license', [
                'subscription_id' => $subscriptionId,
                'license_type' => $license instanceof UserLicense ? 'user' : 'organization',
                'license_id' => $license->id,
            ]);

            return false;
        }

        // Get subscription details from Mollie
        $result = $this->subscriptionService->getSubscription($customerId, $subscriptionId);

        if (! $result['success']) {
            Log::error('Failed to retrieve subscription from Mollie', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'error' => $result['error'] ?? 'Unknown error',
            ]);

            return false;
        }

        $subscription = $result['data'];
        $status = $subscription['status'] ?? 'unknown';

        Log::info('Processing subscription webhook', [
            'subscription_id' => $subscriptionId,
            'status' => $status,
            'license_type' => $license instanceof UserLicense ? 'user' : 'organization',
            'license_id' => $license->id,
        ]);

        return match ($status) {
            'active' => $this->handleActiveSubscription($license, $subscription, $customerId),
            'canceled', 'suspended' => $this->handleCanceledSubscription($license, $subscription),
            'completed' => $this->handleCompletedSubscription($license, $subscription),
            default => $this->handleUnknownSubscriptionStatus($license, $subscription),
        };
    }

    /**
     * Find license by Mollie subscription ID
     */
    private function findLicenseBySubscriptionId(string $subscriptionId): UserLicense|OrganizationLicense|null
    {
        // Try user license first
        $userLicense = UserLicense::where('mollie_subscription_id', $subscriptionId)->first();
        if ($userLicense) {
            return $userLicense;
        }

        // Try organization license
        return OrganizationLicense::where('mollie_subscription_id', $subscriptionId)->first();
    }

    /**
     * Handle active subscription (renewal payment received)
     */
    private function handleActiveSubscription(
        UserLicense|OrganizationLicense $license,
        array $subscription,
        string $customerId
    ): bool {
        // Get latest payment from subscription
        $paymentsResult = $this->subscriptionService->getSubscriptionPayments($customerId, $subscription['id'], 1);

        if (! $paymentsResult['success'] || empty($paymentsResult['data']['_embedded']['payments'])) {
            Log::info('No new payments found for subscription', [
                'subscription_id' => $subscription['id'],
            ]);

            return true;
        }

        $latestPayment = $paymentsResult['data']['_embedded']['payments'][0];

        // Check if payment is already processed
        $existingOrder = Order::where('mollie_payment_id', $latestPayment['id'])->first();
        if ($existingOrder) {
            Log::info('Payment already processed', [
                'payment_id' => $latestPayment['id'],
                'order_id' => $existingOrder->id,
            ]);

            return true;
        }

        // Only process paid payments
        if ($latestPayment['status'] !== 'paid') {
            Log::info('Subscription payment not yet paid', [
                'payment_id' => $latestPayment['id'],
                'status' => $latestPayment['status'],
            ]);

            return true;
        }

        return $this->processSubscriptionRenewal($license, $latestPayment, $subscription);
    }

    /**
     * Process subscription renewal payment
     */
    private function processSubscriptionRenewal(
        UserLicense|OrganizationLicense $license,
        array $payment,
        array $subscription
    ): bool {
        return DB::transaction(function () use ($license, $payment, $subscription) {
            $isUserLicense = $license instanceof UserLicense;
            $payerId = $isUserLicense ? $license->user_id : $license->organization_id;
            $payerType = $isUserLicense ? 'user' : 'organization';

            // Create renewal order
            $renewalOrder = Order::create([
                'uuid' => Str::uuid(),
                'payer_type' => $payerType,
                'payer_id' => $payerId,
                'license_id' => $license->license_id,
                'type' => 'subscription_renewal',
                'currency' => strtoupper($payment['amount']['currency']),
                'net_amount' => $this->calculateNetAmount($payment['amount']['value']),
                'tax_amount' => $this->calculateTaxAmount($payment['amount']['value']),
                'gross_amount' => $payment['amount']['value'],
                'country' => 'NL', // Default, could be fetched from original order
                'status' => OrderStatus::Paid,
                'mollie_payment_id' => $payment['id'],
                'mollie_customer_id' => $license->mollie_customer_id,
                'mollie_subscription_id' => $subscription['id'],
                'paid_at' => now(),
                'billing_snapshot' => $this->getOriginalBillingSnapshot($license),
                'meta' => [
                    'type' => 'subscription_renewal',
                    'original_license_id' => $license->id,
                    'subscription_id' => $subscription['id'],
                    'payment_sequence' => $subscription['timesTriggered'] ?? 1,
                    'mollie_payment_data' => $payment,
                ],
            ]);

            // Reset credits
            $this->resetCreditsForRenewal($license);

            // Update license last_credit_reset_at
            $license->update([
                'last_credit_reset_at' => now(),
            ]);

            Log::info('Subscription renewal processed successfully', [
                'license_type' => $license instanceof UserLicense ? 'user' : 'organization',
                'license_id' => $license->id,
                'renewal_order_id' => $renewalOrder->id,
                'payment_id' => $payment['id'],
                'amount' => $payment['amount']['value'],
            ]);

            AnalyticsService::log('license_renewed', [
                'license_type' => $isUserLicense ? 'user' : 'organization',
                'license_id' => $license->id,
                'order_id' => $renewalOrder->id,
                'amount' => $payment['amount']['value'],
                'currency' => $payment['amount']['currency'],
            ]);

            return true;
        });
    }

    /**
     * Handle canceled or suspended subscription
     */
    private function handleCanceledSubscription(UserLicense|OrganizationLicense $license, array $subscription): bool
    {
        // Calculate end date based on billing cycle
        $billingCycle = $license->license->billing_cycle ?? 'yearly';
        $lastReset = $license->last_credit_reset_at ?? $license->starts_at ?? now();

        $endsAt = match ($billingCycle) {
            'monthly' => $lastReset->copy()->addMonth(),
            'yearly' => $lastReset->copy()->addYear(),
            '6month' => $lastReset->copy()->addMonths(6),
            default => $lastReset->copy()->addYear(),
        };

        $license->update([
            'status' => 'canceled',
            'ends_at' => $endsAt,
        ]);

        Log::info('Subscription canceled - license will expire', [
            'license_type' => $license instanceof UserLicense ? 'user' : 'organization',
            'license_id' => $license->id,
            'subscription_id' => $subscription['id'],
            'ends_at' => $endsAt->toISOString(),
        ]);

        return true;
    }

    /**
     * Handle completed subscription (all payments done)
     */
    private function handleCompletedSubscription(UserLicense|OrganizationLicense $license, array $subscription): bool
    {
        Log::info('Subscription completed', [
            'license_type' => $license instanceof UserLicense ? 'user' : 'organization',
            'license_id' => $license->id,
            'subscription_id' => $subscription['id'],
        ]);

        return true;
    }

    /**
     * Handle unknown subscription status
     */
    private function handleUnknownSubscriptionStatus(UserLicense|OrganizationLicense $license, array $subscription): bool
    {
        Log::warning('Unknown subscription status', [
            'license_type' => $license instanceof UserLicense ? 'user' : 'organization',
            'license_id' => $license->id,
            'subscription_id' => $subscription['id'],
            'status' => $subscription['status'] ?? 'unknown',
        ]);

        return true;
    }

    /**
     * Reset credits for subscription renewal
     */
    private function resetCreditsForRenewal(UserLicense|OrganizationLicense $license): void
    {
        $licenseCredits = $license->license->credits ?? 0;

        if ($license instanceof UserLicense) {
            $user = $license->user;
            if ($user) {
                // Use credit reset service for proper LIFO handling
                $resetService = app(LicenseCreditResetService::class);
                $resetService->processPremiumReset($license);
            }
        } else {
            // Organization license
            $resetService = app(LicenseCreditResetService::class);
            $resetService->processOrganizationPremiumReset($license);
        }
    }

    /**
     * Calculate net amount (assuming 21% VAT for simplicity)
     */
    private function calculateNetAmount(string $grossAmount): float
    {
        return round((float) $grossAmount / 1.21, 2);
    }

    /**
     * Calculate tax amount (assuming 21% VAT for simplicity)
     */
    private function calculateTaxAmount(string $grossAmount): float
    {
        $gross = (float) $grossAmount;

        return round($gross - ($gross / 1.21), 2);
    }

    /**
     * Get original billing snapshot from related order
     */
    private function getOriginalBillingSnapshot(UserLicense|OrganizationLicense $license): array
    {
        $payerType = $license instanceof UserLicense ? 'user' : 'organization';
        $payerId = $license instanceof UserLicense ? $license->user_id : $license->organization_id;

        $originalOrder = Order::where('payer_type', $payerType)
            ->where('payer_id', $payerId)
            ->where('license_id', $license->license_id)
            ->whereNotNull('billing_snapshot')
            ->orderBy('created_at', 'desc')
            ->first();

        return $originalOrder?->billing_snapshot ?? [];
    }

    /**
     * Process payment status and update order/licenses accordingly
     *
     * @param  array  $payment  Mollie payment data
     */
    private function processPaymentStatus(Order $order, array $payment): bool
    {
        $status = $payment['status'];
        $paymentType = $payment['metadata']['type'] ?? 'onetime';

        Log::info('Processing payment status', [
            'order_id' => $order->id,
            'payment_id' => $payment['id'],
            'status' => $status,
            'type' => $paymentType,
            'current_order_status' => $order->status,
        ]);

        // Map Mollie status to Order status
        $orderStatus = $this->mapMollieStatusToOrderStatus($status);

        // Idempotency check - if order is already in this status, don't process again
        if ($this->shouldSkipProcessing($order, $orderStatus)) {
            Log::info('Skipping processing due to idempotency check', [
                'order_id' => $order->id,
                'current_status' => $order->status,
                'payment_status' => $status,
                'mapped_order_status' => $orderStatus,
            ]);

            return true;
        }

        switch ($status) {
            case 'paid':
                return $this->handlePaidPayment($order, $payment, $paymentType);

            case 'canceled':
            case 'expired':
            case 'failed':
            case 'refunded':
            case 'charged_back':
                return $this->handleFailedPayment($order, $payment, $orderStatus);

            default:
                // Update order with mapped status for unhandled statuses (pending, open, etc.)
                $order->update(['status' => $orderStatus]);
                Log::info('Payment status updated but not specifically handled', [
                    'order_id' => $order->id,
                    'mollie_status' => $status,
                    'order_status' => $orderStatus,
                ]);

                return true;
        }
    }

    /**
     * Handle successful payment
     */
    private function handlePaidPayment(Order $order, array $payment, string $paymentType): bool
    {
        return DB::transaction(function () use ($order, $payment, $paymentType) {
            $paidAt = now();

            // Update order status and store payment type in meta
            $order->update([
                'status' => OrderStatus::Paid,
                'paid_at' => $paidAt,
                'mollie_payment_id' => $payment['id'],
                'payment_method' => $payment['method'] ?? null,
                'meta' => array_merge($order->meta ?? [], [
                    'mollie_payment_data' => $payment,
                    'payment_type' => $paymentType,
                    'webhook_processed_at' => $paidAt->toISOString(),
                ]),
            ]);

            // Use fulfillment service for idempotent processing
            return $this->fulfillmentService->fulfillOrder($order);
        });
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment(Order $order, array $payment, string $orderStatus): bool
    {
        $order->update([
            'status' => $orderStatus,
            'mollie_payment_id' => $payment['id'],
            'meta' => array_merge($order->meta ?? [], [
                'failed_at' => now()->toISOString(),
                'failure_reason' => $payment['details']['failureReason'] ?? $payment['status'],
                'mollie_payment_data' => $payment,
            ]),
        ]);

        Log::info('Payment failed or cancelled', [
            'order_id' => $order->id,
            'mollie_status' => $payment['status'],
            'order_status' => $orderStatus,
            'reason' => $payment['details']['failureReason'] ?? 'Unknown',
        ]);

        return true;
    }

    /**
     * Map Mollie payment status to Order status
     */
    private function mapMollieStatusToOrderStatus(string $mollieStatus): string
    {
        return match ($mollieStatus) {
            'paid' => 'paid',
            'canceled' => 'canceled',
            'expired' => 'expired',
            'failed' => 'failed',
            'refunded' => 'refunded',
            'charged_back' => 'charged_back',
            'pending', 'open' => 'pending',
            default => 'initiated'
        };
    }

    /**
     * Check if we should skip processing (idempotency)
     */
    private function shouldSkipProcessing(Order $order, string $orderStatus): bool
    {
        // If order is already in the target status, skip processing
        if ($order->status === $orderStatus) {
            return true;
        }

        // If order is already paid and we're trying to set it to paid again, skip
        if ($order->status === 'Paid' && $orderStatus === 'Paid') {
            return true;
        }

        return false;
    }
}
