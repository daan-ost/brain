<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InboundEmailAttachment extends Model
{
    use SoftDeletes;

    // Virus scan status constants (prepared for ClamAV)
    const VIRUS_SCAN_PENDING = 'pending';

    const VIRUS_SCAN_CLEAN = 'clean';

    const VIRUS_SCAN_INFECTED = 'infected';

    const VIRUS_SCAN_FAILED = 'failed';

    protected $fillable = [
        'inbound_email_id',
        'original_filename',
        'stored_filename',
        'mime_type',
        'file_size',
        'file_path',
        'content_id',
        'is_inline',
        'virus_scan_status',
        'virus_scan_details',
    ];

    protected $casts = [
        // Encrypted fields (sensitive user data)
        'original_filename' => 'encrypted',
        'file_path' => 'encrypted',
        // Regular casts
        'file_size' => 'integer',
        'is_inline' => 'boolean',
        'virus_scan_details' => 'array',
    ];

    /**
     * Get the inbound email this attachment belongs to
     */
    public function inboundEmail(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class);
    }

    /**
     * Scope to only inline attachments
     */
    public function scopeInline(Builder $query): Builder
    {
        return $query->where('is_inline', true);
    }

    /**
     * Scope to regular attachments (not inline)
     */
    public function scopeRegular(Builder $query): Builder
    {
        return $query->where('is_inline', false);
    }

    /**
     * Scope to attachments with clean virus scan
     */
    public function scopeClean(Builder $query): Builder
    {
        return $query->where('virus_scan_status', self::VIRUS_SCAN_CLEAN);
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
     * Mark virus scan as clean (prepared for ClamAV)
     */
    public function markVirusClean(): void
    {
        $this->update(['virus_scan_status' => self::VIRUS_SCAN_CLEAN]);
    }

    /**
     * Mark virus as detected (prepared for ClamAV)
     */
    public function markVirusInfected(array $details = []): void
    {
        $this->update([
            'virus_scan_status' => self::VIRUS_SCAN_INFECTED,
            'virus_scan_details' => $details,
        ]);
    }

    /**
     * Get human-readable file size
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
