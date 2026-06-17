<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One tick of the sell trail for a trade (coin, datetime) — produced by engine/src/sell_ticks.py
 * via SellEngine.sell(trace=True). Per tick: what each mechanism wanted (minimum_price floor,
 * lock_price ratchet, rule101_mult) and the resulting stoploss_price. Lets a screen show the full
 * SL trail of a trade and the max price reached during the hold. See [[brain-engine]] sell model.
 */
class CoinSellTick extends Model
{
    protected $guarded = [];

    protected $casts = [
        'datetime' => 'datetime',
        'tick_datetime' => 'datetime',
        'minutes_in_trade' => 'float',
        'marketprice' => 'float',
        'profit' => 'float',
        'highest_profit' => 'float',
        'minimum_price' => 'float',
        'lock_price' => 'float',
        'rule101_mult' => 'float',
        'stoploss_price' => 'float',
        'selling_price' => 'float',
    ];

    /** The full SL trail of one trade, oldest tick first. */
    public static function trail(int|string $coin, $buyMoment): Collection
    {
        return static::query()->where('trading_symbol_id', $coin)
            ->where('datetime', $buyMoment)->orderBy('tick_datetime')->get();
    }
}
