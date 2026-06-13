<?php

namespace App\Http\Controllers;

use App\Models\EngineRun;
use App\Models\EngineSignal;
use Illuminate\Http\Request;

/**
 * Trading-engine screens (admin): browse the faithful rule-engine replay —
 * found trades (fires) and, per trade, every subrule value (green/red).
 */
class EngineController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function index(Request $request)
    {
        $this->guard();

        $runs = EngineRun::orderByDesc('id')->get();
        $run = $request->filled('run')
            ? $runs->firstWhere('id', (int) $request->integer('run'))
            : $runs->first();

        $fires = $run
            ? $run->fires()->orderBy('datetime')->paginate(50)->withQueryString()
            : null;

        return view('engine.index', compact('runs', 'run', 'fires'));
    }

    public function signal(EngineSignal $signal)
    {
        $this->guard();

        $signal->load(['subruleValues', 'run']);

        return view('engine.signal', compact('signal'));
    }
}
