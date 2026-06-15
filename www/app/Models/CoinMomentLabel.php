<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
