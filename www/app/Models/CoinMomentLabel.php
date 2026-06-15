<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A hand (or imported-legacy) label on one buy-moment, keyed by (coin, datetime, rule, source).
 * Stored apart from coin_fires so it survives the persist_to_brain re-fire (which deletes fires).
 *
 * decision      = legacy ok_trade: yes / no / no_volume
 * manual_klasse = buy-moment quality override (goed/middel/slecht) on CoinFire::klasseKey()
 * source        = 'manual' (Daan) | 'legacy' (imported wp_trading_simulation.result)
 */
class CoinMomentLabel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'datetime' => 'datetime',
        'set_at' => 'datetime',
    ];

    public const DECISIONS = ['yes', 'no', 'no_volume'];
    public const KLASSES = ['goed', 'middel', 'slecht'];

    /** Map a legacy result (1/2/3) to a klasse. */
    public static function klasseFromLegacy(?int $result): ?string
    {
        return [1 => 'goed', 2 => 'middel', 3 => 'slecht'][$result] ?? null;
    }

    /** The day-keyed map screens use to attach labels in bulk: "Y-m-d H:i:s|rule" => label. */
    public static function mapKey(\DateTimeInterface $dt, int $rule): string
    {
        return $dt->format('Y-m-d H:i:s') . '|' . $rule;
    }

    /**
     * Attach the manual labels (source=manual) to a fires collection in bulk, so CoinFire::klasseKey()
     * applies the override without an N+1. The single place the (datetime, rule) match lives.
     *
     * A label belongs to a (coin, datetime, rule) MOMENT, not to a fire-id: if two fires share that
     * key (e.g. an executed + a shadow on the same tick) they correctly get the same label.
     */
    public static function attachManual(Collection $fires, int|string $coin, $start, $end): void
    {
        if ($fires->isEmpty()) {
            return;
        }
        $labels = static::query()->where('trading_symbol_id', $coin)->where('source', 'manual')
            ->whereBetween('datetime', [$start, $end])->get()
            ->keyBy(fn ($l) => static::mapKey($l->datetime, $l->rule));
        foreach ($fires as $f) {
            $f->manualLabel = $labels->get(static::mapKey($f->datetime, $f->rule));
        }
    }

    /** Attach the manual label to a single fire (detail views), so klasseKey() is correct there too. */
    public static function attachOne(CoinFire $f): ?self
    {
        $f->manualLabel = static::query()->where('trading_symbol_id', $f->trading_symbol_id)
            ->where('datetime', $f->datetime)->where('rule', $f->rule)
            ->where('source', 'manual')->first();
        return $f->manualLabel;
    }
}
