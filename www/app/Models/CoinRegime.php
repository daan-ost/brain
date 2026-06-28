<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CoinRegime extends Model
{
    protected $table = 'coin_regime';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'rolling_result' => 'float',
        'computed_at' => 'datetime',
    ];

    public static function isActive(int $coinId, $dt): bool
    {
        return ! static::where('trading_symbol_id', $coinId)
            ->where('state', 'inactive')
            ->whereRaw('? >= period_from AND ? < period_to + INTERVAL 1 DAY', [$dt, $dt])
            ->exists();
    }

    /**
     * Scope: filter coin_fires (of een vergelijkbare tabel met trading_symbol_id + datetime)
     * op alleen trades die NIET in een inactief interval vallen. Zelfde semantiek als de Python
     * regime.active_sql_clause(): een munt zonder coin_regime-rijen telt als volledig actief.
     */
    public static function scopeActiveOnly(Builder $query, string $symCol = 'coin_fires.trading_symbol_id', string $dtCol = 'coin_fires.datetime'): Builder
    {
        return $query->whereNotExists(function ($sub) use ($symCol, $dtCol) {
            $sub->selectRaw('1')
                ->from('coin_regime')
                ->whereColumn('coin_regime.trading_symbol_id', $symCol)
                ->where('coin_regime.state', 'inactive')
                ->whereRaw("{$dtCol} >= coin_regime.period_from AND {$dtCol} < coin_regime.period_to + INTERVAL 1 DAY");
        });
    }
}
