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
        'best_upside' => 'float',
        'legacy_profit_loss' => 'float',
    ];

    /**
     * Trade quality from the best available exit (best_upside), NOT our (imperfect) sell.
     * Daan: goed >= 3%, middel 0.5-3%, slecht < 0.5%. Returns [label, css-class].
     */
    public function klasse(): array
    {
        $u = $this->best_upside;
        if ($u === null) return ['—', 'text-slate-500'];
        if ($u >= 3) return ['goed', 'text-emerald-400'];
        if ($u >= 0.5) return ['middel', 'text-orange-400'];
        return ['slecht', 'text-rose-400'];
    }

    public function klasseKey(): string
    {
        $u = $this->best_upside;
        if ($u === null) return 'onbekend';
        if ($u >= 3) return 'goed';
        if ($u >= 0.5) return 'middel';
        return 'slecht';
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CoinPeriod::class, 'period_id');
    }
}
