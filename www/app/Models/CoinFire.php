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
        'best_sell_price' => 'float',
        'best_sell_datetime' => 'datetime',
        'best_upside' => 'float',
        'horizons' => 'array',
        'lowest10' => 'float',
        'legacy_profit_loss' => 'float',
    ];

    /**
     * A hand label (source='manual') attached in bulk by a screen, so klasseKey() can apply the
     * override without an N+1 query. Authoritative store is coin_moment_labels (survives re-fire);
     * the coin_fires.manual_klasse column is a deprecated back-compat cache only.
     */
    public ?CoinMomentLabel $manualLabel = null;

    /**
     * Trade quality (buy-moment, sell-INdependent). A manual label overrides the calculated value.
     * Daan: goed >= 3%, middel 0.5-3%, slecht < 0.5% (on best_upside). Returns [label, css-class].
     */
    public function klasse(): array
    {
        return match ($this->klasseKey()) {
            'goed'   => ['goed',   'text-emerald-400'],
            'middel' => ['middel', 'text-orange-400'],
            'slecht' => ['slecht', 'text-rose-400'],
            default  => ['—',      'text-slate-500'],
        };
    }

    /** Effective buy-quality class: manual label > deprecated column > computed best_upside. */
    public function klasseKey(): string
    {
        $manual = $this->manualLabel?->manual_klasse ?? $this->manual_klasse;
        if ($manual) return $manual;

        return $this->autoKlasseKey();
    }

    /** The pure auto-classification from best_upside, ignoring any manual override. */
    public function autoKlasseKey(): string
    {
        $u = $this->best_upside;
        if ($u === null) return 'onbekend';
        if ($u >= 3) return 'goed';
        if ($u >= 0.5) return 'middel';
        return 'slecht';
    }

    /** The legacy verdict (offline reference), shown as its own column — not folded into klasseKey. */
    public function legacyKlasseKey(): ?string
    {
        return CoinMomentLabel::klasseFromLegacy($this->legacy_result);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CoinPeriod::class, 'period_id');
    }
}
