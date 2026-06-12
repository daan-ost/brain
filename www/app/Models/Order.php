<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'payer_type',
        'payer_id',
        'license_id',
        'type',
        'currency',
        'net_amount',
        'tax_amount',
        'gross_amount',
        'country',
        'vat_id',
        'status',
        'mollie_payment_id',
        'mollie_customer_id',
        'mollie_subscription_id',
        'payment_provider',
        'provider_payment_id',
        'provider_customer_id',
        'provider_subscription_id',
        'provider_invoice_id',
        'billing_snapshot',
        'meta',
        'invoice_number',
        'invoice_file_path',
        'invoice_date',
        'paid_at',
        'payment_method',
    ];

    protected $casts = [
        'net_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'billing_snapshot' => 'array',
        'meta' => 'array',
        'invoice_date' => 'datetime',
        'paid_at' => 'datetime',
        'status' => OrderStatus::class,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->id)) {
                $order->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the license details
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Get the payer (user or organization)
     */
    public function payer()
    {
        if ($this->payer_type === 'user') {
            return $this->belongsTo(User::class, 'payer_id');
        }

        return $this->belongsTo(Organization::class, 'payer_id');
    }

    /**
     * Get the user payer
     */
    public function userPayer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    /**
     * Get the organization payer
     */
    public function organizationPayer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'payer_id');
    }

    /**
     * Check if order is paid
     */
    public function isPaid(): bool
    {
        return $this->status?->isPaid() ?? false;
    }

    /**
     * Check if order is pending payment
     */
    public function isPending(): bool
    {
        return $this->status?->isPending() ?? false;
    }

    /**
     * Check if order failed
     */
    public function isFailed(): bool
    {
        return $this->status?->isFailed() ?? false;
    }

    /**
     * Check if order is invoice requested
     */
    public function isInvoiceRequested(): bool
    {
        return $this->status?->isInvoiceRequested() ?? false;
    }

    /**
     * Get VAT rate as percentage
     */
    public function getVatRateAttribute(): float
    {
        if ($this->net_amount <= 0) {
            return 0;
        }

        return ($this->tax_amount / $this->net_amount) * 100;
    }

    /**
     * Check if this order has VAT applied
     */
    public function hasVat(): bool
    {
        return $this->tax_amount > 0;
    }

    /**
     * Get formatted amount for display
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->gross_amount, 2).' '.strtoupper($this->currency);
    }

    /**
     * Resolve which payment provider this order belongs to.
     * Works on both legacy (mollie_*_id only) and post-Stripe-migration
     * (payment_provider + provider_*_id columns) database schemas.
     */
    public function getPaymentProviderAttribute(): ?string
    {
        if (! empty($this->attributes['payment_provider'] ?? null)) {
            return $this->attributes['payment_provider'];
        }

        if (! empty($this->attributes['mollie_payment_id'] ?? null)
            || ! empty($this->attributes['mollie_customer_id'] ?? null)
            || ! empty($this->attributes['mollie_subscription_id'] ?? null)
        ) {
            return 'mollie';
        }

        return null;
    }

    public function getProviderPaymentIdAttribute(): ?string
    {
        return $this->attributes['provider_payment_id']
            ?? $this->attributes['mollie_payment_id']
            ?? null;
    }

    public function getProviderCustomerIdAttribute(): ?string
    {
        return $this->attributes['provider_customer_id']
            ?? $this->attributes['mollie_customer_id']
            ?? null;
    }

    public function getProviderSubscriptionIdAttribute(): ?string
    {
        return $this->attributes['provider_subscription_id']
            ?? $this->attributes['mollie_subscription_id']
            ?? null;
    }

    public function getProviderDashboardUrlAttribute(): ?string
    {
        $id = $this->provider_payment_id;
        if (! $id) {
            return null;
        }

        return match ($this->payment_provider) {
            'mollie' => "https://my.mollie.com/dashboard/payments/{$id}",
            'stripe' => "https://dashboard.stripe.com/payments/{$id}",
            default => null,
        };
    }

    /**
     * Scope to get orders by payer
     */
    public function scopeByPayer($query, string $payerType, int $payerId)
    {
        return $query->where('payer_type', $payerType)
            ->where('payer_id', $payerId);
    }

    /**
     * Scope to get paid orders
     */
    public function scopePaid($query)
    {
        return $query->where('status', OrderStatus::Paid);
    }

    /**
     * Scope to get pending orders
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', [OrderStatus::Pending, OrderStatus::Initiated]);
    }
}
