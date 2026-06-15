"""
Align legacy datetimes to our volumeud tick grid. Legacy buys were placed a fixed ~5 seconds AFTER
the signal tick (simulate_buy.php added seconds), so an exact-datetime join against our ticks/fires
misses ~100% of them. Snap a legacy datetime to the nearest tick within a tolerance so the legacy
result/label lands on the real moment (e.g. 16:24:01 → 16:23:56).
"""
import bisect


def snap_to_tick(dt, ticks, tol_sec=90):
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
