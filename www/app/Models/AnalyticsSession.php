<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalyticsSession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'session_group_id',
        'user_id',
        'guest_sid',
        'device_type',
        'user_agent',
        'started_at',
        'last_activity_at',
        'ended_at',
        'rapid_click_count',
        'rage_clicks',
        'form_abandonment',
        'frustration_score',
        'scroll_depth',
        'session_actions',
        'inferred_intent',
        'behavior_snapshot',
        'last_actions_before_exit',
        'total_events',
        'total_pages_viewed',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'ended_at' => 'datetime',
        'form_abandonment' => 'boolean',
        'frustration_score' => 'decimal:2',
        'scroll_depth' => 'decimal:2',
        'session_actions' => 'array',
        'behavior_snapshot' => 'array',
        'last_actions_before_exit' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'session_id');
    }
}
