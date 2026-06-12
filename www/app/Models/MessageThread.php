<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class MessageThread extends Model
{
    // Status constants
    const STATUS_OPEN = 'open';

    const STATUS_WAITING_FOR_USER = 'waiting_for_user';

    const STATUS_CLOSED = 'closed';

    // Sender types
    const SENDER_USER = 'user';

    const SENDER_ADMIN = 'admin';

    const SENDER_LLM = 'llm';

    const SENDER_SYSTEM = 'system';

    // Thumb values
    const THUMB_UP = 'up';

    const THUMB_DOWN = 'down';

    protected $fillable = [
        'uuid',
        'user_id',
        'category_id',
        'title',
        'status',
        'last_message_at',
        'last_message_from',
        'rating',
        'unread_count_user',
        'unread_count_admin',
        'source',
        'thumb',
        'context_json',
        'settings_json',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($thread) {
            if (empty($thread->uuid)) {
                $thread->uuid = Str::uuid()->toString();
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
        'last_message_at' => 'datetime',
        'rating' => 'integer',
        'unread_count_user' => 'integer',
        'unread_count_admin' => 'integer',
        'context_json' => 'array',
        'settings_json' => 'array',
    ];

    /**
     * Get the user who owns this thread
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category of this thread
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MessageCategory::class, 'category_id');
    }

    /**
     * Get the messages in this thread
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class, 'thread_id')->orderBy('created_at');
    }

    /**
     * Get the latest message in this thread (for efficient eager loading)
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ThreadMessage::class, 'thread_id')->latestOfMany();
    }

    /**
     * Scope to only open threads
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope to threads with unread messages for admin
     */
    public function scopeUnreadForAdmin($query)
    {
        return $query->where('unread_count_admin', '>', 0);
    }

    /**
     * Scope to threads with unread messages for user
     */
    public function scopeUnreadForUser($query)
    {
        return $query->where('unread_count_user', '>', 0);
    }

    /**
     * Check if thread is open
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Check if thread is closed
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Get a context value
     */
    public function getContext(string $key, $default = null)
    {
        return data_get($this->context_json, $key, $default);
    }

    /**
     * Get a setting value
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings_json, $key, $default);
    }

    /**
     * Update last message info
     */
    public function updateLastMessage(string $senderType): void
    {
        $this->update([
            'last_message_at' => now(),
            'last_message_from' => $senderType,
        ]);
    }

    /**
     * Increment unread count for user (when admin sends)
     */
    public function incrementUnreadForUser(): void
    {
        $this->increment('unread_count_user');
    }

    /**
     * Increment unread count for admin (when user sends)
     */
    public function incrementUnreadForAdmin(): void
    {
        $this->increment('unread_count_admin');
    }

    /**
     * Mark all messages as read for user
     */
    public function markReadForUser(): void
    {
        $this->update(['unread_count_user' => 0]);
        $this->messages()
            ->where('sender_type', '!=', self::SENDER_USER)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    /**
     * Mark all messages as read for admin
     */
    public function markReadForAdmin(): void
    {
        $this->update(['unread_count_admin' => 0]);
        $this->messages()
            ->where('sender_type', self::SENDER_USER)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }
}
