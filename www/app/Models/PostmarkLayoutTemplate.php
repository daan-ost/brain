<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostmarkLayoutTemplate extends Model
{
    protected $fillable = [
        'postmark_id',
        'name',
        'alias',
        'html_body',
        'active',
        'postmark_metadata',
    ];

    protected $casts = [
        'active' => 'boolean',
        'postmark_metadata' => 'array',
    ];

    /**
     * Get templates using this layout
     */
    public function templates(): HasMany
    {
        return $this->hasMany(PostmarkTemplate::class, 'layout_template_alias', 'alias');
    }

    /**
     * Scope for active layout templates
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Check if layout has content placeholder
     */
    public function hasContentPlaceholder(): bool
    {
        return str_contains($this->html_body ?? '', '{{{@content}}}');
    }

    /**
     * Get status badge HTML for Backpack
     */
    public function getStatusBadgeAttribute(): string
    {
        $badgeClass = $this->active ? 'success' : 'secondary';
        $text = $this->active ? 'Active' : 'Inactive';

        return "<span class='badge badge-{$badgeClass}'>{$text}</span>";
    }

    /**
     * Get dependent templates count
     */
    public function getDependentCountAttribute(): int
    {
        return $this->templates()->count();
    }

    /**
     * Check if layout can be deleted (no dependent templates)
     */
    public function canDelete(): bool
    {
        return $this->dependent_count === 0;
    }

    /**
     * Validate layout template HTML
     */
    public function validateHtmlBody(): array
    {
        $errors = [];

        if (empty($this->html_body)) {
            $errors[] = 'HTML body is required';
        }

        if (! $this->hasContentPlaceholder()) {
            $errors[] = 'HTML body must contain exactly one {{{@content}}} placeholder';
        }

        // Check for multiple content placeholders
        $placeholderCount = substr_count($this->html_body ?? '', '{{{@content}}}');
        if ($placeholderCount > 1) {
            $errors[] = 'HTML body must contain exactly one {{{@content}}} placeholder, found '.$placeholderCount;
        }

        return $errors;
    }
}
