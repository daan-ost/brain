<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Newsletter recipient model for tracking email delivery and engagement.
 *
 * @property int $id
 * @property int $newsletter_id
 * @property int $user_id
 * @property string $email
 * @property string $locale
 * @property string $status
 * @property string|null $ses_message_id
 * @property int $attempts
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $opened_at
 * @property \Carbon\Carbon|null $clicked_at
 * @property \Carbon\Carbon|null $bounced_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Newsletter $newsletter
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<NewsletterClick> $clicks
 */
class NewsletterRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'newsletter_id',
        'user_id',
        'email',
        'locale',
        'status',
        'ses_message_id',
        'attempts',
        'error_message',
        'sent_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const MAX_ATTEMPTS = 3;

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(NewsletterClick::class, 'recipient_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeSkipped(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SKIPPED);
    }

    public function scopeRetryable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    // =========================================================================
    // STATUS METHODS
    // =========================================================================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function canRetry(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }

    // =========================================================================
    // TRACKING METHODS
    // =========================================================================

    /**
     * Mark the recipient as successfully sent.
     */
    public function markAsSent(string $sesMessageId): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'ses_message_id' => $sesMessageId,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark the recipient as failed with an error message.
     *
     * If retry attempts remain, status stays pending for retry.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->increment('attempts');
        $this->refresh(); // sync in-memory model after DB increment
        $this->update([
            'status' => $this->canRetry() ? self::STATUS_PENDING : self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Mark the recipient as skipped (e.g., newsletter cancelled).
     */
    public function markAsSkipped(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'error_message' => $reason,
        ]);
    }

    /**
     * Record that the email was opened (only counts first open).
     */
    public function markAsOpened(): void
    {
        if ($this->opened_at === null) {
            $this->update(['opened_at' => now()]);
            $this->newsletter->increment('total_opened');
        }
    }

    /**
     * Record that a link was clicked (only counts first click).
     */
    public function markAsClicked(): void
    {
        if ($this->clicked_at === null) {
            $this->update(['clicked_at' => now()]);
            $this->newsletter->increment('total_clicked');
        }
    }

    /**
     * Mark the recipient as bounced and also mark the user's email as bounced.
     */
    public function markAsBounced(): void
    {
        if ($this->bounced_at === null) {
            $this->update(['bounced_at' => now()]);
            $this->newsletter->increment('total_bounced');

            // Also mark the user's email as bounced
            $this->user?->update(['email_bounced_at' => now()]);
        }
    }

    /**
     * Record a link click with the URL that was clicked.
     *
     * @param  string  $url  The URL that was clicked
     * @return bool True if recorded, false if URL is invalid
     */
    public function recordClick(string $url): bool
    {
        // Validate URL format and scheme
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Only allow http and https schemes
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // Limit URL length to prevent storage abuse
        if (strlen($url) > 2048) {
            $url = substr($url, 0, 2048);
        }

        $this->clicks()->create([
            'url' => $url,
            'clicked_at' => now(),
        ]);

        $this->markAsClicked();

        return true;
    }
}
