<?php

namespace Database\Factories;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnalyticsEventFactory extends Factory
{
    protected $model = AnalyticsEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_name' => $this->faker->randomElement([
                'license_expired_admin',
                'license_canceled_admin',
                'user_signup',
                'credit_purchase',
            ]),
            'event_data' => [],
            'ip_address' => $this->faker->ipv4,
            'user_agent' => $this->faker->userAgent,
            'created_at' => now(),
        ];
    }

    public function licenseExpiredAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_name' => 'license_expired_admin',
        ]);
    }

    public function licenseCanceledAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_name' => 'license_canceled_admin',
        ]);
    }
}
