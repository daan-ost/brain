<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseNotification extends Model
{
    // Notification types
    const TYPE_EXPIRY_7_DAYS = 'expiry_7_days';

    const TYPE_EXPIRY_1_DAY = 'expiry_1_day';

    const TYPE_RENEWAL_7_DAYS = 'renewal_7_days';

    const TYPE_LOW_CREDITS = 'low_credits';

    const TYPE_INVOICE_RENEWAL_30_DAYS = 'invoice_renewal_30_days';

    const TYPE_INVOICE_RENEWAL_7_DAYS = 'invoice_renewal_7_days';

    protected $fillable = [
        'user_license_id',
        'organization_license_id',
        'notification_type',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * Get the user license for this notification
     */
    public function userLicense(): BelongsTo
    {
        return $this->belongsTo(UserLicense::class);
    }

    /**
     * Get the organization license for this notification
     */
    public function organizationLicense(): BelongsTo
    {
        return $this->belongsTo(OrganizationLicense::class);
    }

    /**
     * Check if a notification of this type was sent recently
     */
    public static function wasRecentlySent(
        ?int $userLicenseId,
        ?int $organizationLicenseId,
        string $notificationType,
        int $withinDays = 30
    ): bool {
        $query = self::where('notification_type', $notificationType)
            ->where('sent_at', '>=', now()->subDays($withinDays));

        if ($userLicenseId) {
            $query->where('user_license_id', $userLicenseId);
        }

        if ($organizationLicenseId) {
            $query->where('organization_license_id', $organizationLicenseId);
        }

        return $query->exists();
    }

    /**
     * Record that a notification was sent
     */
    public static function recordSent(
        ?int $userLicenseId,
        ?int $organizationLicenseId,
        string $notificationType
    ): self {
        return self::create([
            'user_license_id' => $userLicenseId,
            'organization_license_id' => $organizationLicenseId,
            'notification_type' => $notificationType,
            'sent_at' => now(),
        ]);
    }
}
