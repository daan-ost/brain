<?php

namespace App\Models\Trading;

use Illuminate\Database\Eloquent\Model;

/**
 * READ-ONLY view on the legacy wp_trading_symbols (coins). Never written to.
 */
class TradingSymbol extends Model
{
    protected $connection = 'bot_signals';

    protected $table = 'wp_trading_symbols';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected static function booted(): void
    {
        $readonly = fn () => throw new \RuntimeException('bot_signals is read-only');
        static::saving($readonly);
        static::deleting($readonly);
    }
}
