<?php

use App\Contracts\PaymentProvider;
use App\Models\License;
use App\Services\PaymentProviderManager;
use App\Services\Payments\MolliePaymentProvider;
use App\Services\Payments\StripePaymentProvider;

describe('PaymentProviderManager', function () {

    beforeEach(function () {
        // StripeCheckoutService validates the key on construction
        config(['services.stripe.secret_key' => 'sk_test_fake_key_for_tests']);
    });

    it('returns mollie provider when license has no payment_provider', function () {
        config(['services.default_payment_provider' => 'mollie']);

        $license = License::factory()->make(['payment_provider' => null]);
        $manager = new PaymentProviderManager;

        $provider = $manager->for($license);

        expect($provider)->toBeInstanceOf(MolliePaymentProvider::class);
        expect($provider->name())->toBe('mollie');
    });

    it('returns stripe provider when license has payment_provider stripe', function () {
        $license = License::factory()->make(['payment_provider' => 'stripe']);
        $manager = new PaymentProviderManager;

        $provider = $manager->for($license);

        expect($provider)->toBeInstanceOf(StripePaymentProvider::class);
        expect($provider->name())->toBe('stripe');
    });

    it('returns mollie provider when license has payment_provider mollie', function () {
        $license = License::factory()->make(['payment_provider' => 'mollie']);
        $manager = new PaymentProviderManager;

        $provider = $manager->for($license);

        expect($provider)->toBeInstanceOf(MolliePaymentProvider::class);
        expect($provider->name())->toBe('mollie');
    });

    it('falls back to config default when license payment_provider is null', function () {
        config(['services.default_payment_provider' => 'stripe']);

        $license = License::factory()->make(['payment_provider' => null]);
        $manager = new PaymentProviderManager;

        $provider = $manager->for($license);

        expect($provider)->toBeInstanceOf(StripePaymentProvider::class);
    });

    it('throws RuntimeException for unknown provider name', function () {
        $manager = new PaymentProviderManager;

        expect(fn () => $manager->byName('paypal'))->toThrow(
            RuntimeException::class,
            'Unknown payment provider: paypal'
        );
    });

    it('byName stripe returns StripePaymentProvider implementing PaymentProvider interface', function () {
        $manager = new PaymentProviderManager;
        $provider = $manager->byName('stripe');

        expect($provider)->toBeInstanceOf(StripePaymentProvider::class);
        expect($provider)->toBeInstanceOf(PaymentProvider::class);
    });

    it('byName mollie returns MolliePaymentProvider implementing PaymentProvider interface', function () {
        $manager = new PaymentProviderManager;
        $provider = $manager->byName('mollie');

        expect($provider)->toBeInstanceOf(MolliePaymentProvider::class);
        expect($provider)->toBeInstanceOf(PaymentProvider::class);
    });
});
