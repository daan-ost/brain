<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ThreadMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'sender_id',
        'sender_type',
        'content',
        'attachments',
        'is_read',
        'is_hidden',
        'notification_sent_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_read' => 'boolean',
        'is_hidden' => 'boolean',
        'notification_sent_at' => 'datetime',
    ];

    /**
     * Get the thread this message belongs to
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    /**
     * Get the sender (user) of this message
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Check if message is from user
     */
    public function isFromUser(): bool
    {
        return $this->sender_type === MessageThread::SENDER_USER;
    }

    /**
     * Check if message is from admin
     */
    public function isFromAdmin(): bool
    {
        return $this->sender_type === MessageThread::SENDER_ADMIN;
    }

    /**
     * Check if message is from LLM
     */
    public function isFromLlm(): bool
    {
        return $this->sender_type === MessageThread::SENDER_LLM;
    }

    /**
     * Check if message is from system
     */
    public function isFromSystem(): bool
    {
        return $this->sender_type === MessageThread::SENDER_SYSTEM;
    }

    /**
     * Check if message has attachments
     */
    public function hasAttachments(): bool
    {
        return ! empty($this->attachments);
    }

    /**
     * Get attachment count
     */
    public function getAttachmentCount(): int
    {
        return count($this->attachments ?? []);
    }

    /**
     * Get signed URLs for attachments (valid for 15 minutes)
     */
    public function getAttachmentUrls(): array
    {
        if (! $this->hasAttachments()) {
            return [];
        }

        $urls = [];
        foreach ($this->attachments as $attachment) {
            $path = $attachment['path'] ?? null;
            if ($path && Storage::disk('local')->exists($path)) {
                $urls[] = [
                    'url' => Storage::disk('local')->temporaryUrl($path, now()->addMinutes(15)),
                    'type' => $attachment['type'] ?? 'application/octet-stream',
                    'size_kb' => $attachment['size_kb'] ?? 0,
                    'name' => basename($path),
                ];
            }
        }

        return $urls;
    }

    /**
     * Scope to visible messages (not hidden)
     */
    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Scope to unread messages
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
