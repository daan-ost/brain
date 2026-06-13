<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A faithful rule-engine replay over a (symbol, rule, period). See brain/engine + docs/findings.
 */
class EngineRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_from' => 'datetime',
        'period_to' => 'datetime',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(EngineSignal::class, 'run_id');
    }

    public function fires(): HasMany
    {
        return $this->signals()->where('passed', true);
    }
}
