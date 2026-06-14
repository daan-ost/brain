<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rule-fire = a recorded legacy trade (rule 20/21/22/23) with its promising membership.
 * Lives in the brain DB; produced by engine/src/persist_to_brain.py.
 *
 * result: 1=goed, 2=middel, 3=slecht, null=ongelabeld.
 * in_good_period: the automatable good/bad label (inside a promising period = good).
 */
class CoinFire extends Model
{
    protected $guarded = [];

    protected $casts = [
        'datetime' => 'datetime',
        'selling_datetime' => 'datetime',
        'shadow_parent' => 'datetime',
        'in_good_period' => 'boolean',
        'is_executed' => 'boolean',
        'profit_loss' => 'float',
        'buy_price' => 'float',
        'selling_price' => 'float',
        'legacy_profit_loss' => 'float',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(CoinPeriod::class, 'period_id');
    }
}
