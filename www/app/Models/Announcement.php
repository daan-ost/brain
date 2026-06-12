<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title_json',
        'body_json',
        'urgency',
        'cta_label_json',
        'cta_url',
        'starts_at',
        'ends_at',
        'total_views',
        'active',
        // Virtual fields for Backpack forms
        'title_en',
        'title_nl',
        'body_en',
        'body_nl',
        'cta_label_en',
        'cta_label_nl',
    ];

    protected $casts = [
        'title_json' => 'array',
        'body_json' => 'array',
        'cta_label_json' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'active' => 'boolean',
        'total_views' => 'integer',
    ];

    /**
     * Urgency levels with their display colors
     */
    public const URGENCY_COLORS = [
        'info' => 'blue',
        'warning' => 'orange',
        'update' => 'green',
    ];

    /**
     * Relationships
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_announcements')
            ->withPivot('seen_at');
    }

    public function userAnnouncements(): HasMany
    {
        return $this->hasMany(UserAnnouncement::class);
    }

    /**
     * Scopes
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeCurrentlyVisible(Builder $query): Builder
    {
        $now = now();

        return $query->where('active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('starts_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('ends_at', '<', now());
    }

    /**
     * Get translated title for current locale
     */
    public function getTitle(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $titles = $this->title_json ?? [];

        return $titles[$locale] ?? $titles['en'] ?? '';
    }

    /**
     * Get translated body for current locale
     */
    public function getBody(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $bodies = $this->body_json ?? [];

        return $bodies[$locale] ?? $bodies['en'] ?? '';
    }

    /**
     * Get translated CTA label for current locale
     */
    public function getCtaLabel(?string $locale = null): ?string
    {
        if (empty($this->cta_label_json)) {
            return null;
        }

        $locale = $locale ?? app()->getLocale();
        $labels = $this->cta_label_json ?? [];

        return $labels[$locale] ?? $labels['en'] ?? null;
    }

    /**
     * Check if CTA is configured
     */
    public function hasCta(): bool
    {
        return ! empty($this->cta_url) && ! empty($this->cta_label_json);
    }

    /**
     * Get color class based on urgency level
     */
    public function getUrgencyColor(): string
    {
        return self::URGENCY_COLORS[$this->urgency] ?? 'blue';
    }

    /**
     * Check if announcement is currently visible
     */
    public function isCurrentlyVisible(): bool
    {
        $now = now();

        return $this->active
            && $this->starts_at <= $now
            && $this->ends_at >= $now;
    }

    /**
     * Check if user has seen this announcement
     */
    public function hasBeenSeenByUser(User $user): bool
    {
        return $this->userAnnouncements()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Increment view counter
     */
    public function incrementViews(): void
    {
        $this->increment('total_views');
    }

    /**
     * Convert to frontend-friendly array
     */
    public function toFrontendArray(?string $locale = null): array
    {
        return [
            'id' => $this->id,
            'title' => $this->getTitle($locale),
            'body' => $this->getBody($locale),
            'urgency' => $this->urgency,
            'urgency_color' => $this->getUrgencyColor(),
            'cta_label' => $this->getCtaLabel($locale),
            'cta_url' => $this->cta_url,
            'has_cta' => $this->hasCta(),
        ];
    }

    /**
     * Accessors for individual language fields (used by Backpack forms)
     */
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

    public function getCtaLabelEnAttribute(): ?string
    {
        return $this->cta_label_json['en'] ?? null;
    }

    public function getCtaLabelNlAttribute(): ?string
    {
        return $this->cta_label_json['nl'] ?? null;
    }

    /**
     * Mutators for individual language fields (used by Backpack forms)
     */
    public function setTitleEnAttribute(?string $value): void
    {
        $titles = $this->title_json ?? [];
        $titles['en'] = $value ?? '';
        $this->attributes['title_json'] = json_encode($titles);
    }

    public function setTitleNlAttribute(?string $value): void
    {
        $titles = $this->title_json ?? [];
        $titles['nl'] = $value ?? '';
        $this->attributes['title_json'] = json_encode($titles);
    }

    public function setBodyEnAttribute(?string $value): void
    {
        $bodies = $this->body_json ?? [];
        $bodies['en'] = $value ?? '';
        $this->attributes['body_json'] = json_encode($bodies);
    }

    public function setBodyNlAttribute(?string $value): void
    {
        $bodies = $this->body_json ?? [];
        $bodies['nl'] = $value ?? '';
        $this->attributes['body_json'] = json_encode($bodies);
    }

    public function setCtaLabelEnAttribute(?string $value): void
    {
        $labels = $this->cta_label_json ?? [];
        $labels['en'] = $value ?? '';
        if (empty($labels['en']) && empty($labels['nl'] ?? '')) {
            $this->attributes['cta_label_json'] = null;
        } else {
            $this->attributes['cta_label_json'] = json_encode($labels);
        }
    }

    public function setCtaLabelNlAttribute(?string $value): void
    {
        $labels = $this->cta_label_json ?? [];
        $labels['nl'] = $value ?? '';
        if (empty($labels['en'] ?? '') && empty($labels['nl'])) {
            $this->attributes['cta_label_json'] = null;
        } else {
            $this->attributes['cta_label_json'] = json_encode($labels);
        }
    }
}
