"""
Align legacy datetimes to our volumeud tick grid.

WHY they differ: in the LIVE system the rule-engine runs continuously on incoming indicators; after a
positive signal it waits **exactly 5 seconds** (to see if another indicator arrives) and only then
records the buy. So a legacy buy datetime = the signal tick + 5s. An exact-datetime join against our
ticks/fires therefore misses ~100% of them. To map a legacy datetime back to its signal moment,
SUBTRACT 5s; the tick-snap is a safety net for jitter / older offset eras.

Example: legacy 16:24:01 → signal tick 16:23:56.
"""
import bisect
from datetime import timedelta

# The live rule-engine's post-signal wait before recording the buy (see module docstring).
LIVE_SIGNAL_DELAY = timedelta(seconds=5)


def snap_to_tick(dt, ticks, tol_sec=60):
    """Nearest tick datetime to `dt` within tol_sec (preceding or following); else `dt` unchanged.
    `ticks` must be sorted ascending."""
    if not ticks:
        return dt
    i = bisect.bisect_right(ticks, dt)
    cands = []
    if i > 0:
        cands.append(ticks[i - 1])
    if i < len(ticks):
        cands.append(ticks[i])
    best = min(cands, key=lambda t: abs((t - dt).total_seconds()))
    return best if abs((best - dt).total_seconds()) <= tol_sec else dt


def align_legacy_dt(dt, ticks, tol_sec=60):
    """Map a legacy buy datetime back to its signal tick: subtract the 5s live wait, then snap to the
    nearest tick (within tol_sec) to absorb any jitter."""
    return snap_to_tick(dt - LIVE_SIGNAL_DELAY, ticks, tol_sec)
