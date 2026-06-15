<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of the automation routine-chain (engine/src/routines.py). Lives in the brain DB.
 * Has many log lines; the /routines screen shows them grouped per run.
 */
class RoutineRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'run_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'n_routines' => 'integer',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(RoutineRunLog::class)->orderBy('seq');
    }

    public function durationSeconds(): ?int
    {
        return $this->finished_at ? $this->started_at->diffInSeconds($this->finished_at) : null;
    }
}
