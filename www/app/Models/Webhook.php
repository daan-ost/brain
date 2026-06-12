<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    public const MAX_WEBHOOKS_PER_USER = 10;

    public const MAX_FAILURES_BEFORE_DISABLE = 7;

    public const EVENT_EXECUTION_STARTED = 'execution.started';

    public const EVENT_EXECUTION_PROGRESS = 'execution.progress';

    public const EVENT_EXECUTION_COMPLETED = 'execution.completed';

    public const EVENT_EXECUTION_FAILED = 'execution.failed';

    public const VALID_EVENTS = [
        self::EVENT_EXECUTION_STARTED,
        self::EVENT_EXECUTION_PROGRESS,
        self::EVENT_EXECUTION_COMPLETED,
        self::EVENT_EXECUTION_FAILED,
    ];

    protected $fillable = [
        'user_id',
        'url',
        'secret',
        'events',
        'is_active',
        'description',
        'last_triggered_at',
        'last_response_code',
        'failure_count',
    ];

    protected $attributes = [
        'is_active' => true,
        'failure_count' => 0,
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->whereJsonContains('events', $event);
    }

    public function shouldReceiveEvent(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events);
    }

    public function incrementFailureCount(): void
    {
        $this->increment('failure_count');

        if ($this->failure_count >= self::MAX_FAILURES_BEFORE_DISABLE) {
            $this->update(['is_active' => false]);
        }
    }

    public function resetFailureCount(): void
    {
        $this->update(['failure_count' => 0]);
    }

    public function recordTrigger(int $responseCode): void
    {
        $this->update([
            'last_triggered_at' => now(),
            'last_response_code' => $responseCode,
        ]);
    }
}
