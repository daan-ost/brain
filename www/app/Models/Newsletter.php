<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Newsletter model for managing email campaigns.
 *
 * Supports multilingual content (EN/NL) with batch processing
 * and comprehensive statistics tracking.
 *
 * @property int $id
 * @property array<string, string>|null $title_json
 * @property array<string, string>|null $body_json
 * @property string $status
 * @property int|null $send_limit
 * @property int $batch_size
 * @property int $total_recipients
 * @property int $total_sent
 * @property int $total_failed
 * @property int $total_opened
 * @property int $total_clicked
 * @property int $total_bounced
 * @property int $current_batch
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property int $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<NewsletterRecipient> $recipients
 * @property-read string $title_en
 * @property-read string $title_nl
 * @property-read string $body_en
 * @property-read string $body_nl
 */
class Newsletter extends Model
{
    use HasFactory;

    protected $fillable = [
        'title_json',
        'body_json',
        'status',
        'send_limit',
        'segment_key',
        'batch_size',
        'total_recipients',
        'total_sent',
        'total_failed',
        'total_opened',
        'total_clicked',
        'total_bounced',
        'current_batch',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'title_json' => 'array',
        'body_json' => 'array',
        'send_limit' => 'integer',
        'batch_size' => 'integer',
        'total_recipients' => 'integer',
        'total_sent' => 'integer',
        'total_failed' => 'integer',
        'total_opened' => 'integer',
        'total_clicked' => 'integer',
        'total_bounced' => 'integer',
        'current_batch' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the user who created this newsletter.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all recipients for this newsletter.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NewsletterRecipient::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeSending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENDING);
    }

    public function scopePaused(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAUSED);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    // =========================================================================
    // STATUS METHODS
    // =========================================================================

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSending(): bool
    {
        return $this->status === self::STATUS_SENDING;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBeSent(): bool
    {
        return $this->isDraft();
    }

    public function canBePaused(): bool
    {
        return $this->isSending();
    }

    public function canBeResumed(): bool
    {
        return $this->isPaused();
    }

    public function canBeCancelled(): bool
    {
        return $this->isSending() || $this->isPaused();
    }

    // =========================================================================
    // TRANSLATION METHODS
    // =========================================================================

    /**
     * Get the newsletter title for the given locale.
     *
     * Falls back to English if the locale is not available.
     */
    public function getTitle(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $titles = $this->title_json ?? [];

        return $titles[$locale] ?? $titles['en'] ?? '';
    }

    /**
     * Get the newsletter body for the given locale.
     *
     * Falls back to English if the locale is not available.
     */
    public function getBody(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $bodies = $this->body_json ?? [];

        return $bodies[$locale] ?? $bodies['en'] ?? '';
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getTitleEnAttribute(): string
    {
        return $this->title_json['en'] ?? '';
    }

    public function getTitleNlAttribute(): string
    {
        return $this->title_json['nl'] ?? '';
    }

    public function getBodyEnAttribute(): string
    {
        return $this->body_json['en'] ?? '';
    }

    public function getBodyNlAttribute(): string
    {
        return $this->body_json['nl'] ?? '';
    }

    // =========================================================================
    // STATISTICS METHODS
    // =========================================================================

    /**
     * Calculate the email open rate as a percentage.
     */
    public function getOpenRate(): float
    {
        if ($this->total_sent === 0) {
            return 0;
        }

        return round(($this->total_opened / $this->total_sent) * 100, 2);
    }

    /**
     * Calculate the link click rate as a percentage.
     */
    public function getClickRate(): float
    {
        if ($this->total_sent === 0) {
            return 0;
        }

        return round(($this->total_clicked / $this->total_sent) * 100, 2);
    }

    /**
     * Calculate the email bounce rate as a percentage.
     */
    public function getBounceRate(): float
    {
        if ($this->total_sent === 0) {
            return 0;
        }

        return round(($this->total_bounced / $this->total_sent) * 100, 2);
    }

    /**
     * Calculate the sending progress as a percentage.
     */
    public function getProgress(): float
    {
        if ($this->total_recipients === 0) {
            return 0;
        }

        return round((($this->total_sent + $this->total_failed) / $this->total_recipients) * 100, 2);
    }

    /**
     * Recalculate and update statistics from recipient records.
     */
    public function refreshStatistics(): void
    {
        $this->update([
            'total_sent' => $this->recipients()->where('status', 'sent')->count(),
            'total_failed' => $this->recipients()->where('status', 'failed')->count(),
            'total_opened' => $this->recipients()->whereNotNull('opened_at')->count(),
            'total_clicked' => $this->recipients()->whereNotNull('clicked_at')->count(),
            'total_bounced' => $this->recipients()->whereNotNull('bounced_at')->count(),
        ]);
    }
}
