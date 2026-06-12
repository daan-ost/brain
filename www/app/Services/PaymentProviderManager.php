<?php

namespace App\Services;

use App\Contracts\PaymentProvider;
use App\Models\License;
use App\Services\Payments\MolliePaymentProvider;
use App\Services\Payments\StripePaymentProvider;
use RuntimeException;

class PaymentProviderManager
{
    public function for(License $license): PaymentProvider
    {
        $name = $license->payment_provider
            ?? config('services.default_payment_provider', 'mollie');

        return $this->byName($name);
    }

    public function byName(string $name): PaymentProvider
    {
        return match ($name) {
            'stripe' => app(StripePaymentProvider::class),
            'mollie' => app(MolliePaymentProvider::class),
            default => throw new RuntimeException("Unknown payment provider: {$name}"),
        };
    }
}
