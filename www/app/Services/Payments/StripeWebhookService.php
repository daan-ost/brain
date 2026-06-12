<?php

namespace App\Services\Payments;

use App\Enums\LicenseStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use App\Services\InvoiceGenerationService;
use App\Services\LicenseCreditResetService;
use App\Services\PaymentFulfillmentService;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

class StripeWebhookService
{
    public function __construct(
        private PaymentFulfillmentService $fulfillmentService,
        private LicenseCreditResetService $creditResetService,
        private InvoiceGenerationService $invoiceService,
    ) {}

    public function handle(Event $event): void
    {
        Log::info('Stripe webhook received', [
            'event_id' => $event->id,
            'event_type' => $event->type,
        ]);

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event),
            'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($event),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event),
            'charge.refunded' => $this->handleChargeRefunded($event),
            default => Log::debug('Stripe webhook: unhandled event type', ['type' => $event->type]),
        };
    }

    // -------------------------------------------------------------------------
    // checkout.session.completed
    // -------------------------------------------------------------------------

    private function handleCheckoutSessionCompleted(Event $event): void
    {
        $session = $event->data->object;
        $orderId = $session->metadata->order_id ?? null;

        if (! $orderId) {
            Log::warning('Stripe: checkout.session.completed without order_id in metadata', [
                'session_id' => $session->id,
            ]);

            return;
        }

        $order = Order::find($orderId);
        if (! $order) {
            Log::error('Stripe: order not found for checkout session', [
                'order_id' => $orderId,
                'session_id' => $session->id,
            ]);

            return;
        }

        // Defense-in-depth: weiger Stripe-webhook calls op orders die expliciet
        // bij een andere provider horen.
        $orderProvider = $order->payment_provider;
        if ($orderProvider && $orderProvider !== 'stripe') {
            Log::warning('Stripe webhook ignored — order belongs to different provider', [
                'order_id' => $order->id,
                'session_id' => $session->id,
                'order_provider' => $orderProvider,
            ]);

            return;
        }

        // Fill provider fields from the session
        $order->update([
            'provider_payment_id' => $session->id,
            'provider_customer_id' => $session->customer,
            'provider_subscription_id' => $session->subscription ?? null,
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
        $order->refresh();

        if ($session->mode === 'subscription') {
            $this->activateSubscriptionLicense($order, $session);
        } else {
            // One-time payment
            $this->fulfillmentService->fulfillOrder($order);
        }

        $this->generateInvoiceIfNeeded($order);
    }

    private function activateSubscriptionLicense(Order $order, object $session): void
    {
        $subscriptionId = $session->subscription ?? null;
        $customerId = $session->customer ?? null;

        // Update the license with Stripe subscription details
        if ($order->payer_type === 'user') {
            UserLicense::where('user_id', $order->payer_id)
                ->where('license_id', $order->license_id)
                ->whereIn('status', [LicenseStatus::Pending->value, LicenseStatus::Inactive->value])
                ->latest()
                ->first()
                ?->update([
                    'payment_provider' => 'stripe',
                    'provider_subscription_id' => $subscriptionId,
                    'provider_customer_id' => $customerId,
                ]);
        } else {
            OrganizationLicense::where('organization_id', $order->payer_id)
                ->where('license_id', $order->license_id)
                ->whereIn('status', [LicenseStatus::Pending->value, LicenseStatus::Inactive->value])
                ->latest()
                ->first()
                ?->update([
                    'payment_provider' => 'stripe',
                    'provider_subscription_id' => $subscriptionId,
                    'provider_customer_id' => $customerId,
                ]);
        }

        $this->fulfillmentService->fulfillOrder($order);
    }

    // -------------------------------------------------------------------------
    // invoice.payment_succeeded
    // -------------------------------------------------------------------------

    private function handleInvoicePaymentSucceeded(Event $event): void
    {
        $invoice = $event->data->object;

        // Only process subscription renewals, not the first payment (that's checkout.session.completed)
        if (($invoice->billing_reason ?? '') !== 'subscription_cycle') {
            return;
        }

        $subscriptionId = $invoice->subscription ?? null;
        if (! $subscriptionId) {
            return;
        }

        Log::info('Stripe: subscription renewal payment received', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscriptionId,
            'amount' => $invoice->amount_paid,
        ]);

        // Find the license by subscription ID (also past_due: payment may recover dunning)
        $renewableStatuses = array_map(
            fn ($s) => $s->value,
            [...LicenseStatus::activeStatuses(), LicenseStatus::PastDue]
        );

        $userLicense = UserLicense::where('payment_provider', 'stripe')
            ->where('provider_subscription_id', $subscriptionId)
            ->whereIn('status', $renewableStatuses)
            ->first();

        $orgLicense = null;
        if (! $userLicense) {
            $orgLicense = OrganizationLicense::where('payment_provider', 'stripe')
                ->where('provider_subscription_id', $subscriptionId)
                ->whereIn('status', $renewableStatuses)
                ->first();
        }

        $license = $userLicense ?? $orgLicense;
        if (! $license) {
            Log::warning('Stripe: could not find active license for renewal', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoice->id,
            ]);

            return;
        }

        $billingSnapshot = $this->getOriginalBillingSnapshot($license);

        // BTW: lees uit billing_snapshot van de oorspronkelijke order zodat
        // Duitse B2B (reverse charge 0%), Belgische particulieren (21% maar andere
        // VAT-regel) en niet-NL klanten de juiste split krijgen. Fallback 21% NL
        // alleen als snapshot ontbreekt (legacy of seed data).
        $taxRatePercent = (float) ($billingSnapshot['tax_rate'] ?? 21);
        $grossEur = round($invoice->amount_paid / 100, 2);

        if ($taxRatePercent > 0) {
            $divisor = 1 + ($taxRatePercent / 100);
            $netAmount = round($grossEur / $divisor, 2);
            $taxAmount = round($grossEur - $netAmount, 2);
        } else {
            // 0% reverse charge of vrijgesteld
            $netAmount = $grossEur;
            $taxAmount = 0.0;
        }

        // Create a renewal order for bookkeeping and invoice generation
        $renewalOrder = Order::create([
            'payer_type' => $license instanceof UserLicense ? 'user' : 'organization',
            'payer_id' => $license instanceof UserLicense ? $license->user_id : $license->organization_id,
            'license_id' => $license->license_id,
            'type' => 'subscription',
            'currency' => strtoupper($invoice->currency),
            'net_amount' => $netAmount,
            'tax_amount' => $taxAmount,
            'gross_amount' => $grossEur,
            'country' => $billingSnapshot['country'] ?? 'NL',
            'status' => OrderStatus::Paid,
            'payment_provider' => 'stripe',
            'provider_payment_id' => $invoice->payment_intent,
            'provider_customer_id' => $invoice->customer,
            'provider_subscription_id' => $subscriptionId,
            'provider_invoice_id' => $invoice->id,
            'paid_at' => now(),
            'billing_snapshot' => $billingSnapshot,
            'meta' => [
                'type' => 'subscription_renewal',
                'stripe_invoice_id' => $invoice->id,
            ],
        ]);

        // Reset credits for the new period
        if ($license instanceof UserLicense) {
            $this->creditResetService->processPremiumReset($license);
        } else {
            $this->creditResetService->processOrganizationPremiumReset($license);
        }

        // Reset past_due status if payment succeeded after a failed attempt
        if ($license->status === LicenseStatus::PastDue->value) {
            $license->update(['status' => LicenseStatus::Active->value]);
        }

        $this->generateInvoiceIfNeeded($renewalOrder);
    }

    // -------------------------------------------------------------------------
    // invoice.payment_failed
    // -------------------------------------------------------------------------

    private function handleInvoicePaymentFailed(Event $event): void
    {
        $invoice = $event->data->object;
        $subscriptionId = $invoice->subscription ?? null;

        if (! $subscriptionId) {
            return;
        }

        Log::warning('Stripe: subscription payment failed', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscriptionId,
        ]);

        $this->setLicenseStatusBySubscription($subscriptionId, LicenseStatus::PastDue->value);
    }

    // -------------------------------------------------------------------------
    // customer.subscription.deleted
    // -------------------------------------------------------------------------

    private function handleSubscriptionDeleted(Event $event): void
    {
        $subscription = $event->data->object;
        $subscriptionId = $subscription->id;

        Log::info('Stripe: subscription deleted/canceled', [
            'subscription_id' => $subscriptionId,
        ]);

        $endsAt = isset($subscription->current_period_end)
            ? \Carbon\Carbon::createFromTimestamp($subscription->current_period_end)
            : now();

        $this->updateLicenseBySubscription($subscriptionId, [
            'status' => LicenseStatus::Canceled->value,
            'ends_at' => $endsAt,
        ]);
    }

    // -------------------------------------------------------------------------
    // customer.subscription.updated
    // -------------------------------------------------------------------------

    private function handleSubscriptionUpdated(Event $event): void
    {
        $subscription = $event->data->object;
        $subscriptionId = $subscription->id;

        // Handle dunning: unpaid → past_due
        if (($subscription->status ?? '') === 'unpaid') {
            Log::warning('Stripe: subscription became unpaid (dunning)', [
                'subscription_id' => $subscriptionId,
            ]);
            $this->setLicenseStatusBySubscription($subscriptionId, LicenseStatus::PastDue->value);
        }

        // cancel_at_period_end set to true via Customer Portal → just log, license stays active until period ends
        if ($subscription->cancel_at_period_end) {
            Log::info('Stripe: subscription scheduled for cancellation at period end', [
                'subscription_id' => $subscriptionId,
                'cancel_at' => $subscription->cancel_at,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // charge.refunded
    // -------------------------------------------------------------------------

    private function handleChargeRefunded(Event $event): void
    {
        $charge = $event->data->object;
        $paymentIntentId = $charge->payment_intent ?? null;

        if (! $paymentIntentId) {
            return;
        }

        Log::info('Stripe: charge refunded', [
            'charge_id' => $charge->id,
            'payment_intent' => $paymentIntentId,
            'amount_refunded' => $charge->amount_refunded,
        ]);

        $order = Order::where('payment_provider', 'stripe')
            ->where('provider_payment_id', $paymentIntentId)
            ->first();

        if (! $order) {
            // Checkout sessions store session ID, not payment intent — try via invoice
            Log::debug('Stripe: no order found by payment_intent for refund', [
                'payment_intent' => $paymentIntentId,
            ]);

            return;
        }

        $order->update(['status' => OrderStatus::Refunded]);

        Log::info('Stripe: order marked as refunded', ['order_id' => $order->id]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function setLicenseStatusBySubscription(string $subscriptionId, string $status): void
    {
        $this->updateLicenseBySubscription($subscriptionId, ['status' => $status]);
    }

    private function updateLicenseBySubscription(string $subscriptionId, array $data): void
    {
        $updated = UserLicense::where('payment_provider', 'stripe')
            ->where('provider_subscription_id', $subscriptionId)
            ->update($data);

        if (! $updated) {
            OrganizationLicense::where('payment_provider', 'stripe')
                ->where('provider_subscription_id', $subscriptionId)
                ->update($data);
        }
    }

    private function generateInvoiceIfNeeded(Order $order): void
    {
        try {
            $this->invoiceService->generateInvoice($order);
        } catch (\Throwable $e) {
            Log::error('Stripe: invoice generation failed (non-fatal)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getOriginalBillingSnapshot(UserLicense|OrganizationLicense $license): array
    {
        $payerType = $license instanceof UserLicense ? 'user' : 'organization';
        $payerId = $license instanceof UserLicense ? $license->user_id : $license->organization_id;

        $originalOrder = Order::where('payer_type', $payerType)
            ->where('payer_id', $payerId)
            ->where('license_id', $license->license_id)
            ->whereNotNull('billing_snapshot')
            ->latest()
            ->first();

        return $originalOrder?->billing_snapshot ?? [];
    }
}
