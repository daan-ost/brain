<?php

namespace Database\Factories;

use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserLicenseFactory extends Factory
{
    protected $model = UserLicense::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'license_id' => License::factory(),
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
            'source' => 'manual',
            'external_ref' => null,
            'is_current' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'is_current' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'is_current' => false,
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
            'is_current' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'is_current' => false,
        ]);
    }

    /**
     * Mollie-paid license — vult mollie_*_id velden + source/external_ref. Default
     * provider in legacy code, expliciet maken hier vergemakkelijkt asymmetrisch testen.
     */
    public function mollie(): static
    {
        return $this->state(function (array $attributes) {
            $subId = 'sub_'.$this->faker->bothify('??????????');

            return [
                'payment_provider' => 'mollie',
                'mollie_subscription_id' => $subId,
                'mollie_customer_id' => 'cst_'.$this->faker->bothify('??????????'),
                'provider_subscription_id' => null,
                'provider_customer_id' => null,
                'source' => 'mollie',
                'external_ref' => $subId,
            ];
        });
    }

    /**
     * Stripe-paid license — vult provider_*_id velden, wist mollie_*_id, zet source=stripe.
     */
    public function stripe(): static
    {
        return $this->state(function (array $attributes) {
            $subId = 'sub_'.$this->faker->bothify('????????????????????????');

            return [
                'payment_provider' => 'stripe',
                'provider_subscription_id' => $subId,
                'provider_customer_id' => 'cus_'.$this->faker->bothify('??????????????'),
                'mollie_subscription_id' => null,
                'mollie_customer_id' => null,
                'source' => 'stripe',
                'external_ref' => $subId,
            ];
        });
    }
}
