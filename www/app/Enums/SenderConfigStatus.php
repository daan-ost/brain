<?php

namespace App\Enums;

enum SenderConfigStatus: string
{
    case Active = 'active';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PendingVerification => 'Pending Verification',
            self::Verified => 'Verified',
            self::Failed => 'Failed',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingVerification => 'warning',
            self::Verified => 'success',
            self::Failed => 'danger',
        };
    }
}
