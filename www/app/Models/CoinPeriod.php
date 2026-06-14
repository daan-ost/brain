<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promising period (good-trade window) for a coin — one best entry per rise.
 * Lives in the brain DB; produced by engine/src/persist_to_brain.py.
 */
class CoinPeriod extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_from' => 'datetime',
        'period_to' => 'datetime',
        'best_entry' => 'datetime',
        'peak_datetime' => 'datetime',
        'best_upside' => 'float',
        'best_lowest10' => 'float',
    ];

    public function fires(): HasMany
    {
        return $this->hasMany(CoinFire::class, 'period_id');
    }
}
