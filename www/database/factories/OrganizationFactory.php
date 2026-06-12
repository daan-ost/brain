<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->company;

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->randomNumber(3),
            'description' => $this->faker->sentence,
            'active' => true,
            'billing_country_code' => 'NL',
            'currency_preference' => 'EUR',
            'vat_number' => null,
            'settings' => [
                'features' => ['conversion', 'workflows'],
                'limits' => ['max_file_size_mb' => 100],
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Configure organization with credit pool
     */
    public function withCreditPool(int $balance = 0): static
    {
        return $this->afterCreating(function (Organization $organization) use ($balance) {
            $organization->creditPool()->create([
                'organization_id' => $organization->id,
                'balance_credits' => $balance,
            ]);
        });
    }
}
