<?php

namespace Database\Factories;

use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NewsletterRecipient>
 */
class NewsletterRecipientFactory extends Factory
{
    protected $model = NewsletterRecipient::class;

    public function definition(): array
    {
        return [
            'newsletter_id'  => Newsletter::factory(),
            'user_id'        => User::factory(),
            'email'          => $this->faker->unique()->safeEmail(),
            'locale'         => 'nl',
            'status'         => NewsletterRecipient::STATUS_PENDING,
            'ses_message_id' => null,
            'attempts'       => 0,
            'error_message'  => null,
            'sent_at'        => null,
            'opened_at'      => null,
            'clicked_at'     => null,
            'bounced_at'     => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status'         => NewsletterRecipient::STATUS_SENT,
            'ses_message_id' => $this->faker->uuid(),
            'sent_at'        => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status'        => NewsletterRecipient::STATUS_FAILED,
            'attempts'      => NewsletterRecipient::MAX_ATTEMPTS,
            'error_message' => 'SES send failed',
        ]);
    }
}
