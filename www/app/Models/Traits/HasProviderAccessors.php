<?php

namespace App\Models\Traits;

/**
 * Provider-aware accessors for license-like models that carry both legacy
 * mollie_*_id columns and post-Stripe-migration payment_provider + provider_*_id
 * columns. Resolves the active provider and exposes a single getter regardless
 * of which schema generation the row originated from.
 */
trait HasProviderAccessors
{
    public function getPaymentProviderAttribute(): ?string
    {
        if (! empty($this->attributes['payment_provider'] ?? null)) {
            return $this->attributes['payment_provider'];
        }

        if (! empty($this->attributes['mollie_subscription_id'] ?? null)
            || ! empty($this->attributes['mollie_customer_id'] ?? null)
        ) {
            return 'mollie';
        }

        return null;
    }

    public function getProviderSubscriptionIdAttribute(): ?string
    {
        return $this->attributes['provider_subscription_id']
            ?? $this->attributes['mollie_subscription_id']
            ?? null;
    }

    public function getProviderCustomerIdAttribute(): ?string
    {
        return $this->attributes['provider_customer_id']
            ?? $this->attributes['mollie_customer_id']
            ?? null;
    }

    public function getProviderDashboardUrlAttribute(): ?string
    {
        $id = $this->provider_subscription_id;
        if (! $id) {
            return null;
        }

        return match ($this->payment_provider) {
            'mollie' => "https://my.mollie.com/dashboard/subscriptions/{$id}",
            'stripe' => "https://dashboard.stripe.com/subscriptions/{$id}",
            default => null,
        };
    }

    /**
     * Scope: licenses that have any provider subscription (Mollie legacy OR new provider_* column).
     */
    public function scopeHasProviderSubscription($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('mollie_subscription_id')
                ->orWhereNotNull('provider_subscription_id');
        });
    }
}
