<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case ChargedBack = 'charged_back';
    case InvoiceRequested = 'invoice_requested';

    /**
     * Check if status represents a paid state
     */
    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Check if status represents a pending state
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Initiated, self::Pending]);
    }

    /**
     * Check if status represents a failed/terminal state
     */
    public function isFailed(): bool
    {
        return in_array($this, [
            self::Failed,
            self::Canceled,
            self::Expired,
            self::Refunded,
            self::ChargedBack,
        ]);
    }

    /**
     * Check if status is invoice requested
     */
    public function isInvoiceRequested(): bool
    {
        return $this === self::InvoiceRequested;
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Canceled => 'Canceled',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
            self::ChargedBack => 'Charged Back',
            self::InvoiceRequested => 'Invoice Requested',
        };
    }

    /**
     * Get badge color for UI
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Initiated, self::Pending, self::InvoiceRequested => 'warning',
            self::Failed, self::Canceled, self::Expired => 'danger',
            self::Refunded, self::ChargedBack => 'secondary',
        };
    }
}
