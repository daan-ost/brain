<?php

namespace Database\Factories;

use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'payer_type' => 'user',
            'payer_id' => User::factory(),
            'license_id' => License::factory(),
            'type' => 'onetime',
            'currency' => 'EUR',
            'net_amount' => $this->faker->randomFloat(2, 5.00, 100.00),
            'tax_amount' => function (array $attributes) {
                return round($attributes['net_amount'] * 0.21, 2);
            },
            'gross_amount' => function (array $attributes) {
                return $attributes['net_amount'] + $attributes['tax_amount'];
            },
            'country' => 'NL',
            'status' => 'paid',
            'mollie_payment_id' => 'tr_'.$this->faker->bothify('??????????'),
            'mollie_subscription_id' => null,
            'billing_snapshot' => [
                'tax_rate' => 21,
                'country' => 'NL',
                'vat_rule' => 'domestic',
                'buyer_type' => 'individual',
                'vat_id_validated' => false,
                'company_name' => null,
                'vat_number' => null,
            ],
            'meta' => [
                'license_code' => 'TEST_LICENSE',
                'credits_amount' => 100,
                'payment_provider' => 'mollie',
            ],
            'paid_at' => now(),
            'payment_method' => 'mollie',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Configure for organization payer
     */
    public function forOrganization(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'payer_type' => 'organization',
                'payer_id' => Organization::factory(),
            ];
        });
    }

    /**
     * Configure for subscription type. Vult subscription_id veld dat past bij
     * de huidige provider — Mollie default, Stripe als ->stripe() al toegepast is.
     */
    public function subscription(): static
    {
        return $this->state(function (array $attributes) {
            $isStripe = ($attributes['payment_provider'] ?? null) === 'stripe';

            return [
                'type' => 'subscription',
                'mollie_subscription_id' => $isStripe ? null : 'sub_'.$this->faker->bothify('??????????'),
                'provider_subscription_id' => $isStripe ? 'sub_'.$this->faker->bothify('????????????????????????') : ($attributes['provider_subscription_id'] ?? null),
                'meta' => array_merge($attributes['meta'] ?? [], [
                    'payment_type' => 'premium_first',
                ]),
            ];
        });
    }

    /**
     * Configure for canceled status
     */
    public function canceled(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'canceled',
            ];
        });
    }

    /**
     * Configure for failed status
     */
    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'failed',
            ];
        });
    }

    /**
     * Configure as Stripe-paid order. Wist mollie_*_id velden, vult provider_*_id
     * en meta.payment_provider. Combineer met ->subscription() voor subscription test data:
     *   Order::factory()->stripe()->subscription()->create()
     */
    public function stripe(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'payment_provider' => 'stripe',
                'provider_payment_id' => 'pi_'.$this->faker->bothify('????????????????????????'),
                'provider_customer_id' => 'cus_'.$this->faker->bothify('??????????????'),
                'provider_subscription_id' => null,
                'mollie_payment_id' => null,
                'mollie_customer_id' => null,
                'mollie_subscription_id' => null,
                'payment_method' => 'card',
                'meta' => array_merge($attributes['meta'] ?? [], [
                    'payment_provider' => 'stripe',
                ]),
            ];
        });
    }

    /**
     * Configure with comprehensive billing information
     */
    public function withComprehensiveBilling(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'billing_snapshot' => [
                    'tax_rate' => 21,
                    'country' => 'NL',
                    'vat_rule' => 'domestic',
                    'buyer_type' => 'business',
                    'vat_id_validated' => true,
                    'company_name' => $this->faker->company,
                    'vat_number' => 'NL'.$this->faker->numerify('#########B##'),
                    'address_line_1' => $this->faker->streetAddress,
                    'city' => $this->faker->city,
                    'postal_code' => $this->faker->postcode,
                ],
                'meta' => array_merge($attributes['meta'] ?? [], [
                    'license_code' => 'PREMIUM_'.$this->faker->randomNumber(3),
                    'billing_validated' => true,
                ]),
            ];
        });
    }
}
