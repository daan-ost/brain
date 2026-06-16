<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The sell-engine outcome for one buy-moment (coin, datetime) — produced by engine/src/sell_promising.py
 * over the promising moments. Lets the labeler show the realised P&L next to the buy-quality for ANY
 * promising moment, not just the rule-fires. See [[brain-promising-labeler]] punt 4.
 */
class CoinMomentSell extends Model
{
    protected $guarded = [];

    protected $casts = [
        'datetime' => 'datetime',
        'selling_datetime' => 'datetime',
        'best_sell_datetime' => 'datetime',
        'computed_at' => 'datetime',
        'buy_price' => 'float',
        'selling_price' => 'float',
        'profit_loss' => 'float',
        'hi_pl' => 'float',
        'lo_pl' => 'float',
        'best_sell_price' => 'float',
    ];

    /** The day's sell results keyed by moment ("Y-m-d H:i:s"). One query, reused by the screen. */
    public static function byMoment(int|string $coin, $start, $end): Collection
    {
        return static::query()->where('trading_symbol_id', $coin)
            ->whereBetween('datetime', [$start, $end])->get()
            ->keyBy(fn ($s) => CoinMomentLabel::momentKey($s->datetime));
    }
}
