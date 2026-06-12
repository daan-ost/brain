<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageCategory extends Model
{
    protected $fillable = [
        'name_en',
        'name_nl',
        'slug',
        'settings_json',
        'is_visible',
        'order',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'is_visible' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the threads in this category
     */
    public function threads(): HasMany
    {
        return $this->hasMany(MessageThread::class, 'category_id');
    }

    /**
     * Get the localized name based on current locale
     */
    public function getNameAttribute(): string
    {
        $locale = app()->getLocale();

        return $locale === 'nl' ? $this->name_nl : $this->name_en;
    }

    /**
     * Scope to only visible categories
     */
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    /**
     * Scope to order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Get a setting from settings_json
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings_json, $key, $default);
    }
}
