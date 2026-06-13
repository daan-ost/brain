<?php

namespace App\Models\Trading;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * READ-ONLY view on legacy wp_trading_simulation (the found/labeled trades). Never written to.
 *
 * result: 1=goed, 2=middel, 3=slecht, NULL=ongelabeld.
 */
class TradingSimulation extends Model
{
    protected $connection = 'bot_signals';

    protected $table = 'wp_trading_simulation';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $casts = [
        'datetime' => 'datetime',
        'selling_date' => 'datetime',
        'price' => 'float',
        'selling_price' => 'float',
        'profit_loss' => 'float',
        'result' => 'integer',
        'rule' => 'integer',
        'trading_symbol_id' => 'integer',
    ];

    public const RESULTS = [1 => 'Goed', 2 => 'Middel', 3 => 'Slecht'];

    protected static function booted(): void
    {
        $readonly = fn () => throw new \RuntimeException('bot_signals is read-only');
        static::saving($readonly);
        static::deleting($readonly);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'trading_symbol_id', 'ID');
    }

    public function resultLabel(): ?string
    {
        return self::RESULTS[$this->result] ?? null;
    }
}
