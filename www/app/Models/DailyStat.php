<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DailyStat extends Model
{
    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'date',
        'revenue',
        'orders_count',
        'avg_order_value',
        'revenue_eur',
        'revenue_usd',
        'orders_eur',
        'orders_usd',
        'revenue_by_license',
        'orders_by_license',
        'orders_by_tier',
        'new_licenses',
        'expired_licenses',
        'invoice_requested_count',
        'new_users',
        'email_confirmed',
        'logins',
        'active_users',
        'plans_views',
        'checkout_started',
        'checkout_payment_initiated',
        'credits_purchased_events',
        'upgrade_modal_shown',
        'credits_received',
        'credits_spent',
        'pageviews',
        'pageviews_google',
        'pageviews_direct',
        'generated_at',
    ];

    protected $casts = [
        'date'              => 'date',
        'revenue'           => 'decimal:2',
        'avg_order_value'   => 'decimal:2',
        'revenue_eur'       => 'decimal:2',
        'revenue_usd'       => 'decimal:2',
        'revenue_by_license' => 'array',
        'orders_by_license'  => 'array',
        'orders_by_tier'     => 'array',
        'generated_at'      => 'datetime',
    ];

    /** Rows in date range, ordered ascending. */
    public function scopeInRange(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('date');
    }

    /** Credits net (received minus spent). */
    public function getCreditsNetAttribute(): int
    {
        return $this->credits_received - $this->credits_spent;
    }

    /** Checkout-to-purchase conversion rate (%). */
    public function getCheckoutConversionAttribute(): float
    {
        if ($this->checkout_started === 0) {
            return 0.0;
        }

        return round(($this->credits_purchased_events / $this->checkout_started) * 100, 1);
    }
}
