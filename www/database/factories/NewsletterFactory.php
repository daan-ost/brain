<?php

namespace Database\Factories;

use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Newsletter>
 */
class NewsletterFactory extends Factory
{
    protected $model = Newsletter::class;

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
            'status' => Newsletter::STATUS_DRAFT,
            'send_limit' => null,
            'batch_size' => 100,
            'total_recipients' => 0,
            'total_sent' => 0,
            'total_failed' => 0,
            'total_opened' => 0,
            'total_clicked' => 0,
            'total_bounced' => 0,
            'current_batch' => 0,
            'started_at' => null,
            'completed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the newsletter is sending.
     */
    public function sending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Newsletter::STATUS_SENDING,
            'started_at' => now(),
        ]);
    }

    /**
     * Indicate that the newsletter is paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Newsletter::STATUS_PAUSED,
            'started_at' => now()->subHour(),
        ]);
    }

    /**
     * Indicate that the newsletter is sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Newsletter::STATUS_SENT,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the newsletter is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Newsletter::STATUS_CANCELLED,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Set statistics for the newsletter.
     */
    public function withStatistics(int $recipients = 100, int $sent = 80, int $opened = 40, int $clicked = 20): static
    {
        return $this->state(fn (array $attributes) => [
            'total_recipients' => $recipients,
            'total_sent' => $sent,
            'total_opened' => $opened,
            'total_clicked' => $clicked,
            'total_failed' => $recipients - $sent,
        ]);
    }
}
