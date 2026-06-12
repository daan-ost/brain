<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InboundEmail extends Model
{
    // Status constants
    const STATUS_RECEIVED = 'received';

    const STATUS_PROCESSING = 'processing';

    const STATUS_PROCESSED = 'processed';

    const STATUS_BOUNCED = 'bounced';

    const STATUS_FAILED = 'failed';

    const STATUS_VIRUS_DETECTED = 'virus_detected';

    // Virus scan status constants (prepared for ClamAV)
    const VIRUS_SCAN_PENDING = 'pending';

    const VIRUS_SCAN_CLEAN = 'clean';

    const VIRUS_SCAN_INFECTED = 'infected';

    const VIRUS_SCAN_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'message_id',
        'from_email',
        'from_name',
        'to_email',
        'action_type',
        'subject',
        'body_text',
        'body_html',
        'headers',
        'thread_id',
        'user_id',
        'status',
        'processing_notes',
        'virus_scan_status',
        'virus_scan_details',
        'spam_score',
        'nested_email_count',
        'processed_at',
        'cleanup_scheduled_at',
        'completed_at',
        'output_file_path',
        'output_file_count',
    ];

    /**
     * Boot the model
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($email) {
            if (empty($email->uuid)) {
                $email->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get the route key for the model (use UUID instead of ID)
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $casts = [
        // Encrypted fields (sensitive user data)
        'from_email' => 'encrypted',
        'from_name' => 'encrypted',
        'subject' => 'encrypted',
        'body_text' => 'encrypted',
        'body_html' => 'encrypted',
        'headers' => 'encrypted:array',
        // Regular casts
        'virus_scan_details' => 'array',
        'spam_score' => 'decimal:2',
        'nested_email_count' => 'integer',
        'output_file_count' => 'integer',
        'processed_at' => 'datetime',
        'cleanup_scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user who received this email
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the thread this email belongs to
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    /**
     * Get the attachments for this email
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(InboundEmailAttachment::class);
    }

    /**
     * Scope to only processed emails
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    /**
     * Scope to failed emails
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to emails with clean virus scan
     */
    public function scopeClean(Builder $query): Builder
    {
        return $query->where('virus_scan_status', self::VIRUS_SCAN_CLEAN);
    }

    /**
     * Check if email is processed
     */
    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Check if email has failed
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if virus scan is clean (prepared for ClamAV)
     */
    public function isVirusClean(): bool
    {
        return $this->virus_scan_status === self::VIRUS_SCAN_CLEAN;
    }

    /**
     * Check if virus is detected (prepared for ClamAV)
     */
    public function hasVirus(): bool
    {
        return $this->virus_scan_status === self::VIRUS_SCAN_INFECTED;
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark as processed
     */
    public function markAsProcessed(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'processing_notes' => $reason,
        ]);
    }

    /**
     * Add processing note
     */
    public function addProcessingNote(string $note): void
    {
        $currentNotes = $this->processing_notes ?? '';
        $this->update([
            'processing_notes' => $currentNotes."\n".'['.now()->toDateTimeString().'] '.$note,
        ]);
    }

    /**
     * Extract action type from to_email
     * e.g., "merge+12345678@inbound.domain.com" -> "merge"
     */
    public static function extractActionFromEmail(string $email): ?string
    {
        if (preg_match('/^([a-z]+)\+/i', $email, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Extract token from to_email
     * e.g., "merge+12345678@inbound.domain.com" -> "12345678"
     */
    public static function extractTokenFromEmail(string $email): ?string
    {
        if (preg_match('/\+([a-zA-Z0-9]+)@/i', $email, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Mark as completed with output info
     */
    public function markAsCompleted(?int $fileCount = null, ?string $outputPath = null): void
    {
        $data = [
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'completed_at' => now(),
        ];

        if ($fileCount !== null) {
            $data['output_file_count'] = $fileCount;
        }

        if ($outputPath !== null) {
            $data['output_file_path'] = $outputPath;
        }

        $this->update($data);
    }

    /**
     * Schedule cleanup for this email
     */
    public function scheduleCleanup(?int $daysFromNow = null): void
    {
        $days = $daysFromNow ?? config('inbound.limits.result_retention_days', 7);

        $this->update([
            'cleanup_scheduled_at' => now()->addDays($days),
        ]);
    }

    /**
     * Scope to get user's recent inbound emails (for history)
     */
    public function scopeForUserHistory(Builder $query, int $userId, int $limit = 20): Builder
    {
        return $query->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);
    }

    /**
     * Check if output/download is still available
     */
    public function isOutputAvailable(): bool
    {
        if (! $this->output_file_path) {
            return false;
        }

        if ($this->cleanup_scheduled_at && $this->cleanup_scheduled_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get days until cleanup/expiry
     */
    public function getDaysUntilExpiry(): ?int
    {
        if (! $this->cleanup_scheduled_at) {
            return null;
        }

        $days = now()->diffInDays($this->cleanup_scheduled_at, false);

        return (int) max(0, $days);
    }
}
