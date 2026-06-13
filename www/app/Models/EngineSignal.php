<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One evaluated candidate datetime (volume-gated). passed = the rule fired (a trade).
 */
class EngineSignal extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'datetime' => 'datetime',
        'passed' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EngineRun::class, 'run_id');
    }

    public function subruleValues(): HasMany
    {
        return $this->hasMany(EngineSubruleValue::class, 'signal_id')->orderBy('sort')->orderBy('id');
    }

    public function scopeFired(Builder $query): Builder
    {
        return $query->where('passed', true);
    }
}
