<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use App\Models\Traits\HasLicenseStatus;
use App\Models\Traits\HasProviderAccessors;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLicense extends Model
{
    use HasFactory;
    use HasLicenseStatus;
    use HasProviderAccessors;

    /**
     * @deprecated Use LicenseStatus enum instead
     */
    const STATUS_ACTIVE = 'active';

    /**
     * @deprecated Use LicenseStatus enum instead
     */
    const STATUS_INACTIVE = 'inactive';

    /**
     * @deprecated Use LicenseStatus enum instead
     */
    const STATUS_CANCELED = 'canceled';

    /**
     * @deprecated Use LicenseStatus enum instead
     */
    const STATUS_EXPIRED = 'expired';

    /**
     * @deprecated Use LicenseStatus enum instead
     */
    const STATUS_TRIAL = 'trial';

    /**
     * @deprecated Use LicenseStatus::options() instead
     */
    const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_INACTIVE => 'Inactive',
        self::STATUS_CANCELED => 'Canceled',
        self::STATUS_EXPIRED => 'Expired',
        self::STATUS_TRIAL => 'Trial',
    ];

    /**
     * Minimum expected duration (in days) per billing_cycle.
     * A license that closed before reaching this threshold is flagged as
     * premature_expiry. Used by both the model accessor and the admin
     * Filament tooltip — keep them in sync.
     */
    public const PREMATURE_EXPIRY_THRESHOLDS = [
        'yearly' => 350,
        '6month' => 175,
        'monthly' => 28,
        'weekly' => 6,
    ];

    protected $fillable = [
        'user_id',
        'license_id',
        'price_at_purchase',
        'currency_at_purchase',
        'status',
        'starts_at',
        'ends_at',
        'last_credit_reset_at',
        'price_change_notified_at',
        'source',
        'external_ref',
        'mollie_subscription_id',
        'mollie_customer_id',
        'payment_provider',
        'provider_subscription_id',
        'provider_customer_id',
        'is_current',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'last_credit_reset_at' => 'datetime',
        'price_change_notified_at' => 'datetime',
        'is_current' => 'boolean',
        'price_at_purchase' => 'decimal:2',
    ];

    /**
     * Get the user that owns the license
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the license details
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Scope to get active licenses
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }

    /**
     * Scope to get current licenses
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * Check if this is a onetime license
     */
    public function isOnetime(): bool
    {
        return $this->license?->tier === 'onetime';
    }

    /**
     * Detect if this license was closed (canceled/expired) significantly
     * earlier than expected for its billing_cycle. Used by admin diagnostics
     * to surface bugs like the 2026-05 incident where yearly subscriptions
     * got ends_at = starts_at + 1 month.
     */
    public function getIsPrematureExpiryAttribute(): bool
    {
        if (! in_array($this->status, [self::STATUS_CANCELED, self::STATUS_EXPIRED], true)) {
            return false;
        }

        if (! $this->ends_at || ! $this->starts_at || ! $this->license) {
            return false;
        }

        $threshold = self::PREMATURE_EXPIRY_THRESHOLDS[$this->license->billing_cycle] ?? null;
        if ($threshold === null) {
            return false;
        }

        $days = (int) round($this->starts_at->diffInDays($this->ends_at, true));

        return $days < $threshold;
    }

    /**
     * Human-readable duration label between starts_at and ends_at.
     * Returns 'ongoing' for active licenses without ends_at, '—' when unknown.
     */
    public function getDurationLabelAttribute(): string
    {
        if (! $this->starts_at) {
            return '—';
        }

        if (! $this->ends_at) {
            return 'ongoing';
        }

        $days = (int) round($this->starts_at->diffInDays($this->ends_at, true));

        if ($days >= 30) {
            $months = (int) floor($days / 30);

            return "{$months} mnd";
        }

        return "{$days} dgn";
    }
}
