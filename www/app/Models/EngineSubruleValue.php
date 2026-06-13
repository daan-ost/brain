<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subrule value computed for one candidate (the "gevonden waardes").
 * passed: true=green, false=red, null=not evaluated.
 */
class EngineSubruleValue extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'passed' => 'boolean',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(EngineSignal::class, 'signal_id');
    }
}
