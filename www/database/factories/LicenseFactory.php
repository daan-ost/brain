<?php

namespace Database\Factories;

use App\Models\License;
use Illuminate\Database\Eloquent\Factories\Factory;

class LicenseFactory extends Factory
{
    protected $model = License::class;

    public function definition(): array
    {
        return [
            'slug' => 'license-'.$this->faker->unique()->numberBetween(1000, 9999).'-'.$this->faker->lexify('???'),
            'name' => $this->faker->words(3, true),
            'tier' => $this->faker->randomElement(['free', 'premium', 'enterprise', 'onetime']),
            'amount' => $this->faker->randomFloat(2, 0, 99.99),
            'currency' => $this->faker->randomElement(['USD', 'EUR']),
            'billing_cycle' => $this->faker->randomElement(['monthly', 'yearly', 'one_time']),
            'credits' => $this->faker->numberBetween(10, 1000),
            'credit_reset_interval' => 'none',
            'period' => null,
            'json_restrictions' => null, // No defaults - must be set explicitly or via tier methods
            'ordering' => $this->faker->numberBetween(1, 100),
            'valid_from' => null,
            'valid_until' => null,
            'active' => true,
        ];
    }

    public function onetime(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'onetime',
            'billing_cycle' => 'one_time',
            'credit_reset_interval' => 'none',
            'period' => 180, // 6 months
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 5,
                        'max_total_size' => 104857600, // 100MB
                        'max_pages' => 200,
                        'max_file_size' => 20971520, // 20MB
                    ],
                ],
                'feature_restrictions' => [
                    'workflow_builder' => false,
                    'email_support' => true,
                    'api_access' => false,
                    'custom_branding' => false,
                    'priority_queue' => false,
                    'watermark_removal' => true,
                    'advanced_ocr' => false,
                    'team_collaboration' => true,
                ],
            ],
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'premium',
            'billing_cycle' => 'yearly',
            'credit_reset_interval' => 'monthly',
            'period' => 30,
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 10,
                        'max_total_size' => 524288000, // 500MB
                        'max_pages' => 1000,
                        'max_file_size' => 104857600, // 100MB
                    ],
                ],
                'feature_restrictions' => [
                    'workflow_builder' => true,
                    'email_support' => true,
                    'api_access' => true,
                    'custom_branding' => true,
                    'priority_queue' => true,
                    'watermark_removal' => true,
                    'advanced_ocr' => true,
                    'team_collaboration' => true,
                ],
            ],
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'free',
            'amount' => 0,
            'billing_cycle' => null,
            'credits' => 15,
            'credit_reset_interval' => 'daily',
            'period' => null,
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 2,
                        'max_total_size' => 20971520, // 20MB
                        'max_pages' => 50,
                        'max_file_size' => 10485760, // 10MB
                    ],
                ],
                'feature_restrictions' => [
                    'workflow_builder' => false,
                    'email_support' => false,
                    'api_access' => false,
                    'custom_branding' => false,
                    'priority_queue' => false,
                    'watermark_removal' => false,
                    'advanced_ocr' => false,
                    'team_collaboration' => false,
                ],
            ],
        ]);
    }
}
