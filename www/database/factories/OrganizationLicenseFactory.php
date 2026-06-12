<?php

namespace Database\Factories;

use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrganizationLicense>
 */
class OrganizationLicenseFactory extends Factory
{
    protected $model = OrganizationLicense::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'license_id' => License::factory(),
            'status' => 'active',
            'starts_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'ends_at' => $this->faker->dateTimeBetween('+30 days', '+1 year'),
            'source' => 'mollie',
            'external_ref' => 'tr_'.$this->faker->regexify('[a-zA-Z0-9]{10}'),
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Configure for manual source
     */
    public function manual(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'source' => 'manual',
                'external_ref' => 'manual-'.time().'-'.$this->faker->randomNumber(3),
            ];
        });
    }

    /**
     * Configure for subscription source (Mollie default)
     */
    public function subscription(): static
    {
        return $this->state(function (array $attributes) {
            $subId = 'sub_'.$this->faker->regexify('[a-zA-Z0-9]{10}');

            return [
                'source' => 'mollie',
                'external_ref' => $subId,
                'mollie_subscription_id' => $subId,
                'mollie_customer_id' => 'cst_'.$this->faker->regexify('[a-zA-Z0-9]{10}'),
                'payment_provider' => 'mollie',
                'ends_at' => $this->faker->dateTimeBetween('+6 months', '+1 year'),
            ];
        });
    }

    /**
     * Stripe-paid organization license — provider_*_id velden gevuld, mollie_*_id null,
     * source=stripe en external_ref koppelt aan stripe subscription/payment id.
     */
    public function stripe(): static
    {
        return $this->state(function (array $attributes) {
            $subId = 'sub_'.$this->faker->regexify('[a-zA-Z0-9]{24}');

            return [
                'payment_provider' => 'stripe',
                'provider_subscription_id' => $subId,
                'provider_customer_id' => 'cus_'.$this->faker->regexify('[a-zA-Z0-9]{14}'),
                'mollie_subscription_id' => null,
                'mollie_customer_id' => null,
                'source' => 'stripe',
                'external_ref' => $subId,
            ];
        });
    }
}
