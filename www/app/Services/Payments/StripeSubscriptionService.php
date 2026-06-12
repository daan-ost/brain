<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Subscription;

class StripeSubscriptionService
{
    // Stripe SDK is initialized in AppServiceProvider::boot()

    /**
     * Cancel a subscription at the end of the current billing period.
     */
    public function cancel(string $subscriptionId): bool
    {
        try {
            Subscription::update($subscriptionId, ['cancel_at_period_end' => true]);

            Log::info('Stripe subscription scheduled for cancellation', [
                'subscription_id' => $subscriptionId,
            ]);

            return true;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe subscription cancel failed', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Immediately cancel a subscription (used for plan upgrades/refunds).
     */
    public function cancelImmediately(string $subscriptionId): bool
    {
        try {
            $sub = Subscription::retrieve($subscriptionId);
            $sub->cancel();

            Log::info('Stripe subscription cancelled immediately', [
                'subscription_id' => $subscriptionId,
            ]);

            return true;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe subscription immediate cancel failed', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Create a Stripe Billing Portal session for self-service subscription management.
     */
    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $session = PortalSession::create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    /**
     * Retrieve a subscription from Stripe.
     */
    public function retrieve(string $subscriptionId): Subscription
    {
        return Subscription::retrieve($subscriptionId);
    }

    /**
     * Switch a subscription to a new Stripe Price (gebruikt bij price changes).
     * Vervangt het eerste subscription item door $newPriceId. Proration laat
     * Stripe automatisch de pro-rated bedrag bepalen voor de huidige periode.
     *
     * @return array{success: bool, subscription_id?: string, error?: string}
     */
    public function updatePrice(string $subscriptionId, string $newPriceId): array
    {
        try {
            $subscription = Subscription::retrieve($subscriptionId);
            $itemId = $subscription->items->data[0]->id ?? null;

            if (! $itemId) {
                return ['success' => false, 'error' => 'Subscription has no items to update'];
            }

            $updated = Subscription::update($subscriptionId, [
                'items' => [
                    [
                        'id' => $itemId,
                        'price' => $newPriceId,
                    ],
                ],
                'proration_behavior' => 'create_prorations',
            ]);

            Log::info('Stripe subscription price updated', [
                'subscription_id' => $subscriptionId,
                'new_price_id' => $newPriceId,
            ]);

            return ['success' => true, 'subscription_id' => $updated->id];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe subscription updatePrice failed', [
                'subscription_id' => $subscriptionId,
                'new_price_id' => $newPriceId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
