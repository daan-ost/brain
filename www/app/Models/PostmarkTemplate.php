<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostmarkTemplate extends Model
{
    protected $fillable = [
        'postmark_id',
        'name',
        'alias',
        'subject',
        'html_body',
        'text_body',
        'template_type',
        'layout_template_alias',
        'active',
        'postmark_metadata',
    ];

    protected $casts = [
        'active' => 'boolean',
        'postmark_metadata' => 'array',
    ];

    /**
     * Get the associated layout template
     */
    public function layoutTemplate(): BelongsTo
    {
        return $this->belongsTo(PostmarkLayoutTemplate::class, 'layout_template_alias', 'alias');
    }

    /**
     * Scope for active templates
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for standard templates
     */
    public function scopeStandard($query)
    {
        return $query->where('template_type', 'Standard');
    }

    /**
     * Scope for layout templates
     */
    public function scopeLayout($query)
    {
        return $query->where('template_type', 'Layout');
    }

    /**
     * Check if template is a layout template
     */
    public function isLayout(): bool
    {
        return $this->template_type === 'Layout';
    }

    /**
     * Check if template uses a layout
     */
    public function hasLayout(): bool
    {
        return ! empty($this->layout_template_alias);
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
     * Get template type badge HTML for Backpack
     */
    public function getTypeBadgeAttribute(): string
    {
        $badgeClass = $this->template_type === 'Layout' ? 'primary' : 'info';

        return "<span class='badge badge-{$badgeClass}'>{$this->template_type}</span>";
    }
}
