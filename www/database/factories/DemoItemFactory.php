<?php

namespace Database\Factories;

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use App\Models\DemoItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoItem>
 */
class DemoItemFactory extends Factory
{
    protected $model = DemoItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => DemoItemStatus::Draft,
            'priority' => DemoItemPriority::Medium,
            'amount' => fake()->randomFloat(2, 10, 500),
            'due_date' => fake()->optional()->dateTimeBetween('now', '+30 days'),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => DemoItemStatus::Draft]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => DemoItemStatus::Active]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => DemoItemStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => DemoItemStatus::Cancelled]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => DemoItemStatus::Active,
            'due_date' => now()->subDays(5),
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => ['priority' => DemoItemPriority::Urgent]);
    }
}
