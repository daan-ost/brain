<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One human-readable log line of a routine run ("rule 21: kandidaat X — resultaat: ...").
 * level: info|finding|change|result|error. `data` holds the structured payload (candidates, ratios).
 */
class RoutineRunLog extends Model
{
    protected $table = 'routine_run_log';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
        'rule_number' => 'integer',
        'seq' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(RoutineRun::class, 'routine_run_id');
    }
}
