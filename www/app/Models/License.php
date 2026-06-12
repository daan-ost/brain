<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class License extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'tier',
        'amount',
        'upcoming_amount',
        'currency',
        'billing_cycle',
        'credits',
        'upcoming_credits',
        'credit_reset_interval',
        'price_effective_from',
        'period',
        'json_restrictions',
        'ordering',
        'valid_from',
        'valid_until',
        'active',
        'payment_provider',
        'stripe_product_id',
        'stripe_price_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'upcoming_amount' => 'decimal:2',
        'active' => 'boolean',
        'period' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'price_effective_from' => 'date',
        'json_restrictions' => 'array',
    ];

    /**
     * Mutator: Convert JSON string to array when saving
     */
    public function setJsonRestrictionsAttribute($value)
    {
        // If it's already an array, just store it
        if (is_array($value)) {
            $this->attributes['json_restrictions'] = json_encode($value);

            return;
        }

        // If it's a string, validate and store
        if (is_string($value)) {
            // Empty string = null
            if (trim($value) === '') {
                $this->attributes['json_restrictions'] = null;

                return;
            }

            // Try to decode to validate JSON
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->attributes['json_restrictions'] = $value;
            } else {
                // Invalid JSON - store as null or throw exception
                $this->attributes['json_restrictions'] = null;
            }

            return;
        }

        // Default: null
        $this->attributes['json_restrictions'] = null;
    }

    /**
     * Get all user licenses for this license
     */
    public function userLicenses(): HasMany
    {
        return $this->hasMany(UserLicense::class);
    }

    /**
     * Get all organization licenses for this license
     */
    public function organizationLicenses(): HasMany
    {
        return $this->hasMany(OrganizationLicense::class);
    }

    /**
     * Scope to get active licenses
     */
    public function scopeActive($query)
    {
        return $query->where('active', true)
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
    }

    /**
     * Check if this license is currently active and valid
     */
    public function isActive(): bool
    {
        if (! $this->active) {
            return false;
        }

        $now = now();

        if ($this->valid_from && $this->valid_from->gt($now)) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->lt($now)) {
            return false;
        }

        return true;
    }

    /**
     * Get upload limits for a specific conversion type
     */
    public function getUploadLimits(?string $conversionType = null): array
    {
        $restrictions = $this->json_restrictions ?? [];
        $uploadLimits = $restrictions['upload_limits'] ?? [];

        // Check for conversion-specific limits first
        if ($conversionType && isset($uploadLimits['per_conversion'][$conversionType])) {
            $conversionLimits = $uploadLimits['per_conversion'][$conversionType];
            // Merge with global limits as defaults
            $globalLimits = $uploadLimits['global'] ?? [];

            return array_merge($globalLimits, $conversionLimits);
        }

        // Return global limits or empty array
        return $uploadLimits['global'] ?? [];
    }

    /**
     * Get feature restrictions
     */
    public function getFeatureRestrictions(): array
    {
        $restrictions = $this->json_restrictions ?? [];

        return $restrictions['feature_restrictions'] ?? [];
    }

    /**
     * Check if a feature is allowed for this license
     */
    public function isFeatureAllowed(string $feature): bool
    {
        $featureRestrictions = $this->getFeatureRestrictions();

        return $featureRestrictions[$feature] ?? false;
    }

    /**
     * Get default free user limits (when no license or restrictions)
     */
    public static function getDefaultFreeUserLimits(): array
    {
        return [
            'max_files' => 5,
            'max_total_size' => 25 * 1024 * 1024, // 25MB
            'max_pages' => 100,
            'max_file_size' => 25 * 1024 * 1024, // 25MB per file
        ];
    }

    /**
     * Cache key for guest limits
     */
    public const GUEST_LIMITS_CACHE_KEY = 'license:guest_limits';

    /**
     * Cache TTL for guest limits (1 hour)
     */
    public const GUEST_LIMITS_CACHE_TTL = 3600;

    /**
     * Get default guest limits (most restrictive)
     * Uses free-eur license as source, with hardcoded fallback.
     * Results are cached for 1 hour.
     */
    public static function getDefaultGuestLimits(): array
    {
        return Cache::remember(self::GUEST_LIMITS_CACHE_KEY, self::GUEST_LIMITS_CACHE_TTL, function () {
            // Try to get limits from free-eur license
            $freeEurLicense = static::where('slug', 'free-eur')
                ->where('active', true)
                ->first();

            if ($freeEurLicense) {
                $limits = $freeEurLicense->getUploadLimits();
                if (! empty($limits)) {
                    return $limits;
                }
            }

            // Fallback to hardcoded defaults if free-eur not found or has no limits
            return [
                'max_files' => 3,
                'max_total_size' => 10 * 1024 * 1024, // 10MB
                'max_pages' => 50,
                'max_file_size' => 10 * 1024 * 1024, // 10MB per file
            ];
        });
    }

    /**
     * Clear the guest limits cache.
     * Call this when free-eur license is modified.
     */
    public static function clearGuestLimitsCache(): void
    {
        Cache::forget(self::GUEST_LIMITS_CACHE_KEY);
    }

    /**
     * Get human-readable validity text based on period in days
     */
    public function getValidityText(): string
    {
        $period = $this->period ?? 180;
        $months = intval($period / 30);

        if ($months >= 12) {
            $years = intval($months / 12);

            return $years === 1 ? '1 '.__('pricing.year') : "{$years} ".__('pricing.years');
        }

        if ($months >= 1) {
            return $months === 1 ? '1 '.__('pricing.month') : "{$months} ".__('pricing.months');
        }

        return "{$period} ".__('pricing.days');
    }

    /**
     * Static helper for getting validity text from a period value
     */
    public static function formatValidityPeriod(?int $period): string
    {
        $period = $period ?? 180;
        $months = intval($period / 30);

        if ($months >= 12) {
            $years = intval($months / 12);

            return $years === 1 ? '1 '.__('pricing.year') : "{$years} ".__('pricing.years');
        }

        if ($months >= 1) {
            return $months === 1 ? '1 '.__('pricing.month') : "{$months} ".__('pricing.months');
        }

        return "{$period} ".__('pricing.days');
    }
}
