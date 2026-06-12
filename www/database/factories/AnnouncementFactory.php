<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title_json' => [
                'en' => $this->faker->sentence(4),
                'nl' => $this->faker->sentence(4),
            ],
            'body_json' => [
                'en' => '<p>'.$this->faker->paragraph().'</p>',
                'nl' => '<p>'.$this->faker->paragraph().'</p>',
            ],
            'urgency' => $this->faker->randomElement(['info', 'warning', 'update']),
            'cta_label_json' => null,
            'cta_url' => null,
            'starts_at' => now(),
            'ends_at' => now()->addWeek(),
            'total_views' => 0,
            'active' => true,
        ];
    }

    /**
     * Indicate that the announcement is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }

    /**
     * Indicate that the announcement has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subWeek(),
        ]);
    }

    /**
     * Indicate that the announcement is upcoming.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /**
     * Indicate that the announcement has a CTA.
     */
    public function withCta(): static
    {
        return $this->state(fn (array $attributes) => [
            'cta_label_json' => [
                'en' => 'Learn More',
                'nl' => 'Meer info',
            ],
            'cta_url' => $this->faker->url(),
        ]);
    }

    /**
     * Set the urgency level to info.
     */
    public function info(): static
    {
        return $this->state(fn (array $attributes) => [
            'urgency' => 'info',
        ]);
    }

    /**
     * Set the urgency level to warning.
     */
    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'urgency' => 'warning',
        ]);
    }

    /**
     * Set the urgency level to update.
     */
    public function update(): static
    {
        return $this->state(fn (array $attributes) => [
            'urgency' => 'update',
        ]);
    }
}
