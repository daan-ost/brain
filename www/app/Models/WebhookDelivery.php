<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETRYING = 'retrying';

    public const MAX_RETRIES = 7;

    public const TIMEOUT_SECONDS = 30;

    // Retry delays in seconds: 1m, 5m, 30m, 2h, 8h, 24h
    public const RETRY_DELAYS = [
        1 => 60,        // 1 minute
        2 => 300,       // 5 minutes
        3 => 1800,      // 30 minutes
        4 => 7200,      // 2 hours
        5 => 28800,     // 8 hours
        6 => 86400,     // 24 hours
    ];

    public $timestamps = false;

    protected $fillable = [
        'webhook_id',
        'event',
        'payload',
        'response_code',
        'response_body',
        'duration_ms',
        'attempt',
        'status',
        'next_retry_at',
        'created_at',
    ];

    protected $attributes = [
        'attempt' => 1,
        'status' => 'pending',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_retry_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeReadyForRetry($query)
    {
        return $query->where('status', self::STATUS_RETRYING)
            ->where('next_retry_at', '<=', now());
    }

    public function markAsSuccess(int $responseCode, ?string $responseBody, int $durationMs): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'response_code' => $responseCode,
            'response_body' => $responseBody ? substr($responseBody, 0, 1000) : null,
            'duration_ms' => $durationMs,
            'next_retry_at' => null,
        ]);
    }

    public function markAsFailed(int $responseCode, ?string $responseBody, int $durationMs): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'response_code' => $responseCode,
            'response_body' => $responseBody ? substr($responseBody, 0, 1000) : null,
            'duration_ms' => $durationMs,
            'next_retry_at' => null,
        ]);
    }

    public function scheduleRetry(): bool
    {
        if ($this->attempt >= self::MAX_RETRIES) {
            $this->update(['status' => self::STATUS_FAILED]);

            return false;
        }

        $delay = self::RETRY_DELAYS[$this->attempt] ?? 86400;

        $this->update([
            'status' => self::STATUS_RETRYING,
            'attempt' => $this->attempt + 1,
            'next_retry_at' => now()->addSeconds($delay),
        ]);

        return true;
    }

    public function shouldRetry(int $responseCode): bool
    {
        // 4xx errors are permanent (except 429 rate limit)
        if ($responseCode >= 400 && $responseCode < 500 && $responseCode !== 429) {
            return false;
        }

        // 5xx errors and 429 should retry
        return true;
    }
}
