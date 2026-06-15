<?php

namespace App\Livewire\Routines;

use App\Models\RoutineRun;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Routines — the run journal of the automation chain (engine/src/routines.py). Shows each run and
 * its human-readable log lines ("rule 21: kandidaat X — resultaat: ..."). Reads brain (routine_runs
 * + routine_run_log). "Nu draaien" triggers an analysis-only run (seconds, applies nothing).
 */
#[Layout('layouts.trading')]
class Index extends Component
{
    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function runNow(): void
    {
        $this->error = null;
        $engine = realpath(base_path('../engine'));
        $result = Process::path($engine.'/src')->timeout(120)->run([
            $engine.'/.venv/bin/python', 'routines.py', '--no-rebuild', '--trigger', 'manual',
        ]);
        if (! $result->successful()) {
            $this->error = 'Run mislukt: '.trim($result->errorOutput() ?: $result->output());
        }
    }

    public function render()
    {
        $runs = RoutineRun::with('logs')->orderByDesc('id')->limit(20)->get();

        return view('livewire.routines.index', compact('runs'));
    }
}
