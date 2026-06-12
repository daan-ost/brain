<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Trial = 'trial';
    case Pending = 'pending';
    case PastDue = 'past_due';

    public function isActive(): bool
    {
        return in_array($this, [self::Active, self::Trial]);
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isPastDue(): bool
    {
        return $this === self::PastDue;
    }

    public function isEnded(): bool
    {
        return in_array($this, [self::Canceled, self::Expired, self::Inactive]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Canceled => 'Canceled',
            self::Expired => 'Expired',
            self::Trial => 'Trial',
            self::Pending => 'Pending',
            self::PastDue => 'Past Due',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trial => 'info',
            self::Inactive => 'gray',
            self::Canceled => 'warning',
            self::Expired => 'danger',
            self::Pending => 'warning',
            self::PastDue => 'danger',
        };
    }

    /**
     * Get all status options for forms/filters
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->toArray();
    }

    public static function activeStatuses(): array
    {
        return [self::Active, self::Trial];
    }
}
