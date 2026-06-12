<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use App\Models\Traits\HasLicenseStatus;
use App\Models\Traits\HasProviderAccessors;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationLicense extends Model
{
    use BelongsToOrganization;
    use HasFactory;
    use HasLicenseStatus;
    use HasProviderAccessors;

    protected $fillable = [
        'organization_id',
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
        'billing_method',
        'payment_status',
        'paid_at',
        'invoice_number',
        'invoice_due_date',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'last_credit_reset_at' => 'datetime',
        'price_change_notified_at' => 'datetime',
        'is_current' => 'boolean',
        'paid_at' => 'datetime',
        'invoice_due_date' => 'date',
        'price_at_purchase' => 'decimal:2',
    ];

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
     * Get the order that created this license (if any). Provider-aware:
     * matcht zowel legacy mollie_payment_id als nieuwe provider_payment_id.
     *
     * NB: dit is een accessor, geen Eloquent relatie — `with('order')` werkt niet.
     */
    public function getOrderAttribute(): ?Order
    {
        if (! $this->external_ref) {
            return null;
        }

        return $this->orderForExternalRef()->first();
    }

    /**
     * Match orders that point to this license via any provider/payment/subscription ID column.
     */
    private function orderForExternalRef()
    {
        return Order::query()->where(function ($q) {
            $q->where('mollie_payment_id', $this->external_ref)
                ->orWhere('provider_payment_id', $this->external_ref)
                ->orWhere('mollie_subscription_id', $this->external_ref)
                ->orWhere('provider_subscription_id', $this->external_ref);
        });
    }

    /**
     * Organization licenses only consider 'active' status as active (no trial)
     */
    protected function getActiveStatuses(): array
    {
        return ['active'];
    }

    /**
     * Scope to get invoice licenses
     */
    public function scopeInvoice($query)
    {
        return $query->where('billing_method', 'invoice');
    }

    /**
     * Scope to get pending licenses
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get paid invoice licenses
     */
    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    /**
     * Scope to get unpaid invoice licenses
     */
    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'unpaid');
    }

    /**
     * Check if this is an invoice license
     */
    public function isInvoice(): bool
    {
        return $this->billing_method === 'invoice';
    }

    /**
     * Check if this license is pending activation
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the invoice is paid
     */
    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    /**
     * Activate the license
     */
    public function activate(): bool
    {
        $this->status = 'active';
        $saved = $this->save();

        // Generate invoice if there's an associated order
        if ($saved && $this->external_ref) {
            $order = $this->orderForExternalRef()->first();
            if ($order && ! $order->invoice_number) {
                try {
                    $invoiceService = app(\App\Services\InvoiceGenerationService::class);
                    $invoiceService->generateInvoice($order);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to generate invoice on license activation', [
                        'license_id' => $this->id,
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $saved;
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid(): bool
    {
        $this->payment_status = 'paid';
        $this->paid_at = now();

        return $this->save();
    }

    /**
     * Generate invoice number in format INV-{YEAR}-{SEQUENCE}
     */
    public static function generateInvoiceNumber(): string
    {
        $year = now()->year;

        // Get the latest invoice number for current year
        $latestInvoice = static::where('invoice_number', 'like', "INV-{$year}-%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($latestInvoice) {
            // Extract sequence number and increment
            $sequence = intval(substr($latestInvoice->invoice_number, -3)) + 1;
        } else {
            // First invoice of the year
            $sequence = 1;
        }

        return sprintf('INV-%d-%03d', $year, $sequence);
    }

    /**
     * Create invoice license
     */
    public static function createInvoiceLicense(array $data): self
    {
        $dueDays = (int) config('app.invoice_default_due_days', 14);

        $invoiceData = array_merge($data, [
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'invoice_number' => static::generateInvoiceNumber(),
            'invoice_due_date' => now()->addDays($dueDays)->toDateString(),
        ]);

        return static::create($invoiceData);
    }
}
