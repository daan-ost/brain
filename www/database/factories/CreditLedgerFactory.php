<?php

namespace Database\Factories;

use App\Models\CreditLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreditLedgerFactory extends Factory
{
    protected $model = CreditLedger::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'delta' => $this->faker->numberBetween(-100, 100),
            'reason' => $this->faker->randomElement(['purchase', 'spend', 'adjust', 'refund']),
            'balance_after' => $this->faker->numberBetween(0, 1000),
            'meta' => [],
            'created_at' => now(),
        ];
    }

    public function purchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => 'purchase',
            'delta' => $this->faker->numberBetween(10, 100),
        ]);
    }

    public function spend(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => 'spend',
            'delta' => -$this->faker->numberBetween(1, 50),
        ]);
    }

    public function adjust(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => 'adjust',
            'delta' => -$this->faker->numberBetween(1, 30),
        ]);
    }

    public function withLicenseAssignmentId(int $licenseAssignmentId): static
    {
        return $this->state(fn (array $attributes) => [
            'meta' => array_merge($attributes['meta'] ?? [], [
                'license_assignment_id' => $licenseAssignmentId,
            ]),
        ]);
    }
}
