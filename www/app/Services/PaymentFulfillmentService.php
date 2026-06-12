<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\CreditLedger;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentFulfillmentService
{
    /**
     * Fulfill order - idempotent operation
     */
    public function fulfillOrder(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            // Lock the order row for concurrency safety
            $order = Order::where('id', $order->id)->lockForUpdate()->first();
            if (! $order) {
                Log::error('Order not found during fulfillment', [
                    'order_id' => $order->id ?? 'unknown',
                    'order_uuid' => $order->uuid ?? 'unknown',
                ]);

                return false;
            }

            // Runtime idempotency check with detailed logging
            if ($this->isOrderAlreadyFulfilled($order)) {
                Log::info('Order already fulfilled, skipping', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'mollie_payment_id' => $order->mollie_payment_id,
                    'status' => $order->status,
                    'fulfillment_done' => $order->meta['fulfillment_done'] ?? false,
                ]);

                return true;
            }

            $license = $order->license;
            if (! $license) {
                Log::error('License not found for order fulfillment', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'license_id' => $order->license_id,
                ]);

                return false;
            }

            $fulfillmentType = $this->determineFulfillmentType($order);

            Log::info('Starting order fulfillment', [
                'order_id' => $order->id,
                'order_uuid' => $order->uuid,
                'mollie_payment_id' => $order->mollie_payment_id,
                'fulfillment_type' => $fulfillmentType,
                'license_id' => $license->id,
                'payer_type' => $order->payer_type,
                'payer_id' => $order->payer_id,
            ]);

            $result = match ($fulfillmentType) {
                'onetime' => $this->fulfillOnetimePurchase($order, $license),
                'premium_first' => $this->fulfillPremiumFirstPayment($order, $license),
                default => $this->fulfillGenericPurchase($order, $license)
            };

            if ($result) {
                Log::info('Order fulfillment completed successfully', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'mollie_payment_id' => $order->mollie_payment_id,
                ]);
            } else {
                Log::error('Order fulfillment failed', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'mollie_payment_id' => $order->mollie_payment_id,
                ]);
            }

            return $result;
        });
    }

    /**
     * Admin action: mark an invoice-requested order as paid and fulfil it.
     *
     * Safe to call multiple times (idempotent). Handles two cases:
     *   - Trusted org: credits/license were already provisioned at checkout;
     *     we just flip the order status and mark the license payment as paid.
     *   - Non-trusted org: license is still pending; full fulfillment runs here.
     */
    public function fulfillInvoicePayment(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            // Idempotency: already fully processed
            if ($order->isPaid() && ($order->meta['fulfillment_done'] ?? false)) {
                Log::info('fulfillInvoicePayment: order already fulfilled, skipping', [
                    'order_id' => $order->id,
                ]);

                return true;
            }

            $now = now();

            // 1. Transition order to paid
            $order->update([
                'status' => OrderStatus::Paid,
                'paid_at' => $now,
            ]);
            $order->refresh();

            // 2. Run standard fulfillment (idempotent, won't double-add credits).
            //    With order.status = paid, isPaid() is now true inside fulfillOrder,
            //    so the invoice license will be activated AND payment_status set to paid.
            $this->fulfillOrder($order);
            $order->refresh();

            // 3. Explicitly mark the invoice OrganizationLicense as paid.
            //    Handles the trusted-org path where fulfillOrder returned early
            //    (credits already existed) without updating payment_status.
            $invoiceLicenseId = $order->meta['invoice_license_id'] ?? null;
            if ($invoiceLicenseId) {
                $orgLicense = OrganizationLicense::find($invoiceLicenseId);
                $orgLicense?->markAsPaid();
            }

            // 4. Mark order as fulfilled + generate invoice PDF record.
            //    fulfillOrder skips this for invoice orders; we do it explicitly here.
            if (! ($order->meta['fulfillment_done'] ?? false)) {
                $this->markOrderAsFulfilled($order, $now);
            }

            Log::info('Invoice payment marked as paid by admin', [
                'order_id'           => $order->id,
                'invoice_license_id' => $invoiceLicenseId,
                'paid_at'            => $now->toISOString(),
            ]);

            return true;
        });
    }

    /**
     * Check if order is already fulfilled with comprehensive runtime checks
     */
    private function isOrderAlreadyFulfilled(Order $order): bool
    {
        $fulfillmentDone = $order->meta['fulfillment_done'] ?? false;

        // Provider-aware match: source matcht payment_provider, external_ref matcht
        // het bijbehorende ID-veld (Stripe payment_intent of Mollie payment_id).
        // Fallback voor legacy rows die alleen mollie_*_id hebben.
        $orderProvider = $order->payment_provider; // accessor: 'mollie' | 'stripe' | null
        $orderExternalRef = $order->provider_payment_id ?? $order->mollie_payment_id;

        // Check if user license or organization license exists for this order
        if ($order->payer_type === 'user') {
            $existingLicense = UserLicense::where('user_id', $order->payer_id)
                ->where('license_id', $order->license_id)
                ->when($orderProvider, fn ($q) => $q->where('source', $orderProvider))
                ->where('external_ref', $orderExternalRef)
                ->first();

            // Also check for any credit ledger entries for this order
            $existingLedgerEntry = CreditLedger::where('user_id', $order->payer_id)
                ->whereJsonContains('meta->order_id', $order->id)
                ->exists();

            if ($existingLicense) {
                Log::info('Found existing user license for order', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'license_record_id' => $existingLicense->id,
                    'status' => $existingLicense->status,
                    'external_ref' => $existingLicense->external_ref,
                ]);
            }

            if ($existingLedgerEntry) {
                Log::info('Found existing credit ledger entry for order', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'user_id' => $order->payer_id,
                ]);
            }

            return $fulfillmentDone || $existingLicense !== null || $existingLedgerEntry;

        } else {
            $existingLicense = OrganizationLicense::where('organization_id', $order->payer_id)
                ->where('license_id', $order->license_id)
                ->when($orderProvider, fn ($q) => $q->where('source', $orderProvider))
                ->where('external_ref', $orderExternalRef)
                ->first();

            // Also check for any organization credit ledger entries for this order
            $existingLedgerEntry = OrganizationCreditLedger::where('organization_id', $order->payer_id)
                ->whereJsonContains('meta->order_id', $order->id)
                ->exists();

            if ($existingLicense) {
                Log::info('Found existing organization license for order', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'license_record_id' => $existingLicense->id,
                    'status' => $existingLicense->status,
                    'external_ref' => $existingLicense->external_ref,
                ]);
            }

            if ($existingLedgerEntry) {
                Log::info('Found existing organization credit ledger entry for order', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'organization_id' => $order->payer_id,
                ]);
            }

            return $fulfillmentDone || $existingLicense !== null || $existingLedgerEntry;
        }
    }

    /**
     * Determine fulfillment type from order and metadata
     */
    private function determineFulfillmentType(Order $order): string
    {
        $paymentType = $order->meta['payment_type'] ?? null;

        if ($paymentType) {
            return $paymentType;
        }

        // Fallback based on order type
        return $order->type === 'subscription' ? 'premium_first' : 'onetime';
    }

    /**
     * Fulfill one-time credit purchase
     */
    private function fulfillOnetimePurchase(Order $order, $license): bool
    {
        // Don't fulfill canceled orders
        if ($order->status->value === 'canceled' || $order->status->value === 'failed') {
            Log::info('Skipping fulfillment for non-paid order', [
                'order_id' => $order->id,
                'status' => $order->status->value,
            ]);

            return true; // Return true for idempotency but don't create artifacts
        }

        $paidAt = now();

        // Calculate validity period
        $period = $license->period ?? 180; // Default 6 months
        $endsAt = $paidAt->copy()->addDays($period);

        $provider = $order->payment_provider ?? 'mollie';
        $paymentRef = $order->provider_payment_id ?? $order->mollie_payment_id;

        // Create license assignment
        if ($order->payer_type === 'user') {
            $this->deactivateCurrentUserLicense($order->payer_id);
            UserLicense::create([
                'user_id' => $order->payer_id,
                'license_id' => $license->id,
                'status' => 'active',
                'starts_at' => $paidAt,
                'ends_at' => $endsAt,
                'source' => $provider,
                'external_ref' => $paymentRef,
                'payment_provider' => $provider,
                'provider_customer_id' => $order->provider_customer_id ?? null,
                'is_current' => true,
            ]);

            // Add credits to user
            $this->addCreditsToUser($order->payer_id, $license->credits, $order->id, 'purchase', $order);

        } else {
            // Verify organization exists before license creation
            $organization = Organization::find($order->payer_id);
            if (! $organization) {
                throw new \RuntimeException("Organization not found for ID: {$order->payer_id}. Cannot create license for order {$order->id}.");
            }

            // Check if this is an invoice order that already has a license created
            $existingLicenseId = $order->meta['invoice_license_id'] ?? null;
            $existingLicense = $existingLicenseId ? OrganizationLicense::find($existingLicenseId) : null;

            if ($existingLicense) {
                // Invoice order - activate the pending license
                // Only mark as paid if order is actually paid (not just invoice_requested for trusted orgs)
                $isActuallyPaid = $order->isPaid();

                $updateData = [
                    'status' => 'active',
                    'starts_at' => $paidAt,
                    'ends_at' => $endsAt,
                    'is_current' => true,
                ];

                if ($isActuallyPaid) {
                    $updateData['payment_status'] = 'paid';
                    $updateData['paid_at'] = $paidAt;
                }

                $existingLicense->update($updateData);

                Log::info('Activated existing invoice license', [
                    'order_id' => $order->id,
                    'organization_license_id' => $existingLicense->id,
                    'payment_marked_as_paid' => $isActuallyPaid,
                ]);
            } else {
                try {
                    $this->deactivateCurrentOrganizationLicense($order->payer_id);
                    OrganizationLicense::create([
                        'organization_id' => $order->payer_id,
                        'license_id' => $license->id,
                        'status' => 'active',
                        'starts_at' => $paidAt,
                        'ends_at' => $endsAt,
                        'source' => $provider,
                        'external_ref' => $paymentRef,
                        'payment_provider' => $provider,
                        'provider_customer_id' => $order->provider_customer_id ?? null,
                        'is_current' => true,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    throw new \RuntimeException("Failed to create organization license for order {$order->id}: {$e->getMessage()}");
                }
            }

            // Add credits to organization
            $this->addCreditsToOrganization($order->payer_id, $license->credits, $order->id, 'purchase', $order);
        }

        // For invoice orders, don't mark as fulfilled yet - invoice still needs to be paid
        // Invoice generation already happened at order creation
        $isInvoiceOrder = isset($order->meta['invoice_license_id']);
        if (! $isInvoiceOrder) {
            $this->markOrderAsFulfilled($order, $paidAt);
        } else {
            Log::info('Skipping markOrderAsFulfilled for invoice order - invoice payment pending', [
                'order_id' => $order->id,
            ]);
        }

        Log::info('Onetime purchase fulfilled', [
            'order_id' => $order->id,
            'license_id' => $license->id,
            'credits' => $license->credits,
            'valid_until' => $endsAt->toISOString(),
            'payer_type' => $order->payer_type,
            'payer_id' => $order->payer_id,
        ]);

        return true;
    }

    /**
     * Fulfill premium subscription first payment
     */
    private function fulfillPremiumFirstPayment(Order $order, $license): bool
    {
        $paidAt = now();

        // Premium subscriptions auto-renew, no end date
        $endsAt = null;

        // For Mollie: create subscription now (Stripe subscription already exists from Checkout)
        $subscriptionId = $order->provider_subscription_id ?? null;
        $customerId = $order->provider_customer_id ?? $order->mollie_customer_id;
        $provider = $order->payment_provider ?? 'mollie';

        if ($provider === 'mollie' && $customerId && ! isset($order->meta['invoice_license_id'])) {
            $subscriptionResult = $this->createMollieSubscription($order, $customerId);
            if ($subscriptionResult['success']) {
                $subscriptionId = $subscriptionResult['subscription_id'];
                $order->update([
                    'mollie_subscription_id' => $subscriptionId,
                    'provider_subscription_id' => $subscriptionId,
                ]);
            }
        }

        $paymentRef = $order->provider_payment_id ?? $order->mollie_payment_id;

        // Create license assignment
        if ($order->payer_type === 'user') {
            $this->deactivateCurrentUserLicense($order->payer_id);
            UserLicense::create([
                'user_id' => $order->payer_id,
                'license_id' => $license->id,
                'price_at_purchase' => $order->gross_amount,
                'currency_at_purchase' => $order->currency,
                'status' => 'active',
                'starts_at' => $paidAt,
                'ends_at' => $endsAt,
                'source' => $provider,
                'external_ref' => $paymentRef,
                'mollie_subscription_id' => $provider === 'mollie' ? $subscriptionId : null,
                'mollie_customer_id' => $provider === 'mollie' ? $customerId : null,
                'payment_provider' => $provider,
                'provider_subscription_id' => $subscriptionId,
                'provider_customer_id' => $customerId,
                'is_current' => true,
            ]);

            // Add annual credits to user
            $this->addCreditsToUser($order->payer_id, $license->credits, $order->id, 'subscription', $order);

        } else {
            // Verify organization exists before license creation
            $organization = Organization::find($order->payer_id);
            if (! $organization) {
                throw new \RuntimeException("Organization not found for ID: {$order->payer_id}. Cannot create license for order {$order->id}.");
            }

            // Check if this is an invoice order that already has a license created
            $existingLicenseId = $order->meta['invoice_license_id'] ?? null;
            $existingLicense = $existingLicenseId ? OrganizationLicense::find($existingLicenseId) : null;

            if ($existingLicense) {
                // Invoice order - activate the pending license
                // Only mark as paid if order is actually paid (not just invoice_requested for trusted orgs)
                $isActuallyPaid = $order->isPaid();

                $updateData = [
                    'status' => 'active',
                    'starts_at' => $paidAt,
                    'ends_at' => $endsAt,
                    'is_current' => true,
                    'price_at_purchase' => $order->gross_amount,
                    'currency_at_purchase' => $order->currency,
                ];

                if ($isActuallyPaid) {
                    $updateData['payment_status'] = 'paid';
                    $updateData['paid_at'] = $paidAt;
                }

                $existingLicense->update($updateData);

                Log::info('Activated existing invoice license for subscription', [
                    'order_id' => $order->id,
                    'organization_license_id' => $existingLicense->id,
                    'payment_marked_as_paid' => $isActuallyPaid,
                ]);
            } else {
                try {
                    $this->deactivateCurrentOrganizationLicense($order->payer_id);
                    OrganizationLicense::create([
                        'organization_id' => $order->payer_id,
                        'license_id' => $license->id,
                        'price_at_purchase' => $order->gross_amount,
                        'currency_at_purchase' => $order->currency,
                        'status' => 'active',
                        'starts_at' => $paidAt,
                        'ends_at' => $endsAt,
                        'source' => $provider,
                        'external_ref' => $paymentRef,
                        'mollie_subscription_id' => $provider === 'mollie' ? $subscriptionId : null,
                        'mollie_customer_id' => $provider === 'mollie' ? $customerId : null,
                        'payment_provider' => $provider,
                        'provider_subscription_id' => $subscriptionId,
                        'provider_customer_id' => $customerId,
                        'is_current' => true,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    throw new \RuntimeException("Failed to create organization license for premium subscription order {$order->id}: {$e->getMessage()}");
                }
            }

            // Add annual credits to organization
            $this->addCreditsToOrganization($order->payer_id, $license->credits, $order->id, 'subscription', $order);
        }

        // For invoice orders, don't mark as fulfilled yet - invoice still needs to be paid
        $isInvoiceOrder = isset($order->meta['invoice_license_id']);
        if (! $isInvoiceOrder) {
            $this->markOrderAsFulfilled($order, $paidAt);
        } else {
            Log::info('Skipping markOrderAsFulfilled for invoice subscription - invoice payment pending', [
                'order_id' => $order->id,
            ]);
        }

        Log::info('Premium subscription fulfilled', [
            'order_id' => $order->id,
            'license_id' => $license->id,
            'credits' => $license->credits,
            'auto_renew' => true,
            'subscription_id' => $order->mollie_subscription_id,
            'payer_type' => $order->payer_type,
            'payer_id' => $order->payer_id,
        ]);

        return true;
    }

    /**
     * Generic fulfillment for other types
     */
    private function fulfillGenericPurchase(Order $order, $license): bool
    {
        // Default to onetime logic for now
        return $this->fulfillOnetimePurchase($order, $license);
    }

    /**
     * Add credits to user balance and ledger with comprehensive VAT breakdown
     *
     * Credit assignment logic:
     * - If user only has free tier license: SET credits to license amount (replace free credits)
     * - If user has active paid license: ADD credits to current balance (stacking purchases)
     */
    private function addCreditsToUser(int $userId, int $credits, string $orderId, string $reason, ?Order $order = null): void
    {
        $user = User::find($userId);
        if (! $user) {
            throw new \RuntimeException("User not found for ID: {$userId}. Cannot fulfill order {$orderId}.");
        }

        $currentBalance = $user->credits ?? 0;

        // Determine if user had an active paid license BEFORE this order
        // We need to exclude the license that was just created by this order
        // A license is "pre-existing" if it was created before this order's paid_at time
        // OR if it has a different external_ref than this order's mollie_payment_id
        $orderPaymentRef = $order?->mollie_payment_id;

        $hasPreExistingPaidLicense = UserLicense::where('user_id', $userId)
            ->where('status', 'active')
            ->whereHas('license', function ($query) {
                $query->whereIn('tier', ['onetime', 'premium', 'business']);
            })
            ->when($orderPaymentRef, function ($query) use ($orderPaymentRef) {
                // Exclude the license created by this order
                $query->where('external_ref', '!=', $orderPaymentRef);
            })
            ->exists();

        // If user has pre-existing paid license: ADD credits (stacking)
        // If user only has free tier (or this is their first paid license): SET credits
        if ($hasPreExistingPaidLicense) {
            $newBalance = $currentBalance + $credits;
            Log::info('Adding credits to existing paid license balance (stacking)', [
                'user_id' => $userId,
                'current_balance' => $currentBalance,
                'credits_added' => $credits,
                'new_balance' => $newBalance,
            ]);
        } else {
            $newBalance = $credits;
            Log::info('Setting credits for new paid license (replacing free tier)', [
                'user_id' => $userId,
                'previous_balance' => $currentBalance,
                'new_balance' => $newBalance,
            ]);
        }

        try {
            $user->update([
                'credits' => $newBalance,
                'credits_updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \RuntimeException("Failed to update user credits for order {$orderId}: {$e->getMessage()}");
        }

        // Build comprehensive meta with VAT breakdown if order available
        $meta = ['order_id' => $orderId];

        if ($order) {
            // Add VAT breakdown if available
            if (isset($order->net_amount, $order->tax_amount, $order->gross_amount)) {
                $meta['tax_rate'] = $order->billing_snapshot['tax_rate'] ?? null;
                $meta['net_amount'] = $order->net_amount;
                $meta['tax_amount'] = $order->tax_amount;
                $meta['gross_amount'] = $order->gross_amount;
                $meta['currency'] = $order->currency;
            }

            // Add buyer information if available
            if (isset($order->billing_snapshot)) {
                $billing = $order->billing_snapshot;
                $meta['buyer_country'] = $billing['country'] ?? null;
                $meta['vat_rule'] = $billing['vat_rule'] ?? null;
                $meta['buyer_type'] = $billing['buyer_type'] ?? null;
                $meta['vat_id_validated'] = $billing['vat_id_validated'] ?? null;
            }

            // Add payment identifiers (provider-aware: schrijft mollie_payment_id voor
            // Mollie, provider_payment_id voor Stripe; payment_provider matcht de Order accessor)
            $meta['order_uuid'] = $order->uuid;
            $meta['mollie_payment_id'] = $order->mollie_payment_id;
            $meta['provider_payment_id'] = $order->provider_payment_id;
            $meta['sku'] = $order->meta['license_code'] ?? null;
            $meta['payment_provider'] = $order->payment_provider ?? 'mollie';
        }

        // Calculate actual delta (may differ from credits if replacing free tier)
        $actualDelta = $newBalance - $currentBalance;

        // Add credit assignment mode to meta for audit trail
        $meta['credit_assignment_mode'] = $hasPreExistingPaidLicense ? 'add' : 'set';
        $meta['credits_purchased'] = $credits;
        $meta['previous_balance'] = $currentBalance;

        try {
            CreditLedger::create([
                'user_id' => $userId,
                'delta' => $actualDelta,
                'reason' => $reason,
                'balance_after' => $newBalance,
                'meta' => $meta,
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \RuntimeException("Failed to create user credit ledger entry for order {$orderId}: {$e->getMessage()}");
        }

        Log::info('Credits assigned to user', [
            'user_id' => $userId,
            'credits_purchased' => $credits,
            'actual_delta' => $actualDelta,
            'previous_balance' => $currentBalance,
            'new_balance' => $newBalance,
            'assignment_mode' => $hasPreExistingPaidLicense ? 'add' : 'set',
            'order_id' => $orderId,
            'order_uuid' => $order?->uuid,
            'reason' => $reason,
        ]);

        // Log analytics event for credits purchase
        AnalyticsService::log('credits_purchased', [
            'user_id' => $userId,
            'credits_purchased' => $credits,
            'new_balance' => $newBalance,
            'order_id' => $order?->id,
            'order_uuid' => $order?->uuid,
            'source' => $this->determineSource($reason, $order),
            'payment_method' => $order?->payment_provider ?? $order?->meta['payment_provider'] ?? 'unknown',
            'license_id' => $order?->license_id,
            'license_slug' => $order?->license?->slug,
            'reason' => $reason,
        ]);
    }

    /**
     * Add credits to organization pool and ledger with comprehensive VAT breakdown
     */
    private function addCreditsToOrganization(int $organizationId, int $credits, string $orderId, string $reason, ?Order $order = null): void
    {
        $organization = Organization::find($organizationId);
        if (! $organization) {
            throw new \RuntimeException("Organization not found for ID: {$organizationId}. Cannot fulfill order {$orderId}.");
        }

        $pool = $organization->creditPool;
        $currentBalance = $pool->balance_credits ?? 0;
        $newBalance = $currentBalance + $credits;

        try {
            $organization->creditPool()->updateOrCreate(
                ['organization_id' => $organizationId],
                [
                    'balance_credits' => $newBalance,
                    'updated_at' => now(),
                ]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \RuntimeException("Failed to update organization credit pool for order {$orderId}: {$e->getMessage()}");
        }

        // Build comprehensive meta with VAT breakdown if order available
        $meta = ['order_id' => $orderId];

        if ($order) {
            // Add VAT breakdown if available
            if (isset($order->net_amount, $order->tax_amount, $order->gross_amount)) {
                $meta['tax_rate'] = $order->billing_snapshot['tax_rate'] ?? null;
                $meta['net_amount'] = $order->net_amount;
                $meta['tax_amount'] = $order->tax_amount;
                $meta['gross_amount'] = $order->gross_amount;
                $meta['currency'] = $order->currency;
            }

            // Add buyer information if available
            if (isset($order->billing_snapshot)) {
                $billing = $order->billing_snapshot;
                $meta['buyer_country'] = $billing['country'] ?? null;
                $meta['vat_rule'] = $billing['vat_rule'] ?? null;
                $meta['buyer_type'] = $billing['buyer_type'] ?? null;
                $meta['vat_id_validated'] = $billing['vat_id_validated'] ?? null;
            }

            // Add payment identifiers (provider-aware)
            $meta['order_uuid'] = $order->uuid;
            $meta['mollie_payment_id'] = $order->mollie_payment_id;
            $meta['provider_payment_id'] = $order->provider_payment_id;
            $meta['sku'] = $order->meta['license_code'] ?? null;
            $meta['payment_provider'] = $order->payment_provider ?? 'mollie';
        }

        try {
            OrganizationCreditLedger::create([
                'organization_id' => $organizationId,
                'delta' => $credits,
                'reason' => $reason,
                'balance_after' => $newBalance,
                'meta' => $meta,
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \RuntimeException("Failed to create organization credit ledger entry for order {$orderId}: {$e->getMessage()}");
        }

        Log::info('Credits added to organization', [
            'organization_id' => $organizationId,
            'credits_added' => $credits,
            'new_balance' => $newBalance,
            'order_id' => $orderId,
            'order_uuid' => $order?->uuid,
            'reason' => $reason,
        ]);

        // Log analytics event for organization credits purchase
        AnalyticsService::log('organization_credits_purchased', [
            'organization_id' => $organizationId,
            'credits_purchased' => $credits,
            'new_balance' => $newBalance,
            'order_id' => $order?->id,
            'order_uuid' => $order?->uuid,
            'source' => $this->determineSource($reason, $order),
            'payment_method' => $order?->payment_provider ?? $order?->meta['payment_provider'] ?? 'unknown',
            'license_id' => $order?->license_id,
            'license_slug' => $order?->license?->slug,
            'reason' => $reason,
        ]);
    }

    /**
     * Mark order as fulfilled in metadata
     */
    protected function markOrderAsFulfilled(Order $order, \Carbon\Carbon $fulfilledAt): void
    {
        $order->update([
            'meta' => array_merge($order->meta ?? [], [
                'fulfillment_done' => true,
                'fulfilled_at' => $fulfilledAt->toISOString(),
                'fulfillment_service' => self::class,
            ]),
        ]);

        // Generate invoice for the order
        $this->generateInvoice($order);
    }

    /**
     * Generate invoice for paid order
     */
    private function generateInvoice(Order $order): void
    {
        try {
            $invoiceService = app(InvoiceGenerationService::class);
            $result = $invoiceService->generateInvoice($order);

            Log::info('Invoice generated for order', [
                'order_id' => $order->id,
                'invoice_number' => $result['invoice_number'],
                'already_exists' => $result['already_exists'] ?? false,
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the order fulfillment
            Log::error('Failed to generate invoice for order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Create fulfillment for admin-granted license (manual)
     */
    public function fulfillManualLicense(int $licenseId, string $payerType, int $payerId, ?string $adminUserId = null, ?array $options = []): bool
    {
        return DB::transaction(function () use ($licenseId, $payerType, $payerId, $adminUserId, $options) {
            $license = \App\Models\License::find($licenseId);
            if (! $license) {
                Log::error('License not found for manual fulfillment', [
                    'license_id' => $licenseId,
                ]);

                return false;
            }

            // Optionally create audit order
            $order = null;
            if ($options['create_audit_order'] ?? false) {
                $order = Order::create([
                    'uuid' => Str::uuid(),
                    'payer_type' => $payerType,
                    'payer_id' => $payerId,
                    'license_id' => $license->id,
                    'type' => $license->tier === 'premium' ? 'subscription' : 'onetime',
                    'currency' => 'EUR',
                    'net_amount' => 0.00,
                    'tax_amount' => 0.00,
                    'gross_amount' => 0.00,
                    'country' => 'NL',
                    'status' => 'paid',
                    'billing_snapshot' => ['manual_grant' => true],
                    'meta' => [
                        'license_code' => $license->code,
                        'credits_amount' => $license->credits,
                        'payment_provider' => 'manual',
                        'admin_user_id' => $adminUserId,
                        'created_at' => now()->toISOString(),
                    ],
                ]);
            }

            $paidAt = now();
            $period = $license->period ?? 180;
            $endsAt = $paidAt->copy()->addDays($period);

            // Create license assignment
            if ($payerType === 'user') {
                $this->deactivateCurrentUserLicense($payerId);
                UserLicense::create([
                    'user_id' => $payerId,
                    'license_id' => $license->id,
                    'status' => 'active',
                    'starts_at' => $paidAt,
                    'ends_at' => $endsAt,
                    'source' => 'manual',
                    'external_ref' => $order ? $order->id : "manual-{$adminUserId}-".time(),
                    'is_current' => true,
                ]);

                $this->addCreditsToUser($payerId, $license->credits, $order ? $order->id : 'manual', 'purchase');
            } else {
                $this->deactivateCurrentOrganizationLicense($payerId);
                OrganizationLicense::create([
                    'organization_id' => $payerId,
                    'license_id' => $license->id,
                    'status' => 'active',
                    'starts_at' => $paidAt,
                    'ends_at' => $endsAt,
                    'source' => 'manual',
                    'external_ref' => $order ? $order->id : "manual-{$adminUserId}-".time(),
                    'is_current' => true,
                ]);

                $this->addCreditsToOrganization($payerId, $license->credits, $order ? $order->id : 'manual', 'purchase');
            }

            Log::info('Manual license granted', [
                'license_id' => $license->id,
                'payer_type' => $payerType,
                'payer_id' => $payerId,
                'admin_user_id' => $adminUserId,
                'order_id' => $order?->id,
                'credits' => $license->credits,
            ]);

            return true;
        });
    }

    /**
     * Create Mollie subscription for recurring payments
     */
    private function createMollieSubscription(Order $order, string $customerId): array
    {
        try {
            $subscriptionService = app(MollieSubscriptionService::class);
            $result = $subscriptionService->createSubscription($order, $customerId);

            if ($result['success']) {
                $subscriptionId = $result['data']['id'] ?? null;

                Log::info('Mollie subscription created successfully', [
                    'order_id' => $order->id,
                    'customer_id' => $customerId,
                    'subscription_id' => $subscriptionId,
                    'interval' => $result['data']['interval'] ?? 'unknown',
                    'amount' => $result['data']['amount'] ?? null,
                ]);

                return [
                    'success' => true,
                    'subscription_id' => $subscriptionId,
                ];
            }

            Log::error('Failed to create Mollie subscription', [
                'order_id' => $order->id,
                'customer_id' => $customerId,
                'error' => $result['error'] ?? 'Unknown error',
            ]);

            return [
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error',
            ];

        } catch (\Exception $e) {
            Log::error('Exception creating Mollie subscription', [
                'order_id' => $order->id,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Determine the source of credits based on reason and order metadata.
     * Leest eerst de Order accessor (autoritatief) en valt terug op meta voor
     * legacy orders die nog geen payment_provider kolom hadden.
     */
    private function determineSource(string $reason, ?Order $order): string
    {
        if (! $order) {
            return 'manual';
        }

        $provider = $order->payment_provider ?? $order->meta['payment_provider'] ?? null;

        return match ($provider) {
            'admin_manual' => 'admin_grant',
            'mollie' => $reason === 'subscription' ? 'mollie_subscription' : 'mollie_payment',
            'stripe' => $reason === 'subscription' ? 'stripe_subscription' : 'stripe_payment',
            'invoice' => 'invoice_payment',
            default => $reason,
        };
    }

    private function deactivateCurrentUserLicense(int $userId): void
    {
        UserLicense::where('user_id', $userId)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }

    private function deactivateCurrentOrganizationLicense(int $organizationId): void
    {
        OrganizationLicense::where('organization_id', $organizationId)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}
