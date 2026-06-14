#!/usr/bin/env python3
"""
Clean up the raw promising moments into PERIODS.

During one rise, promising() fires on minute after minute (04:01, 04:03, 04:04, ...) —
they all see the same coming move and overlap. This collapses those into one period and
picks the single best entry per period.

Scan: evaluate promising() at every volumeud datetime in [from, to]; keep verdict=="buy".
Cluster: a new period starts when the gap to the previous promising moment exceeds
`gap_minutes`. Best entry per period = the moment with the highest available upside
(`highest`), ties broken by earliest.

READ-ONLY on bot_signals.
Usage: cluster_promising.py [symbol_id] [from] [to] [gap_minutes]
       defaults: 2525  <full range>  15
"""
import bisect
import datetime as _dt
import sys
from datetime import timedelta

from config import FORWARD_MINUTES
from promising import PromisingEngine


def _parse(s):
    return _dt.datetime.strptime(s, "%Y-%m-%d %H:%M:%S") if len(s) > 10 else _dt.datetime.strptime(s, "%Y-%m-%d")


def scan_periods(eng, frm=None, to=None, gap_minutes=FORWARD_MINUTES):
    """Return clustered promising periods. Each period is a list of
    (datetime, highest, lowest_10, highest_dt) tuples (verdict=='buy' moments).

    Overlap removal: a new trade can't start while one is still running, so moments
    within `gap_minutes` (= the 1-hour trade horizon) of the previous belong to the
    SAME opportunity. The best entry per period is the highest-upside moment."""
    DT = eng.DT
    lo = bisect.bisect_left(DT, _parse(frm)) if frm else 0
    hi = bisect.bisect_right(DT, _parse(to)) if to else len(DT)
    moments = []
    for i in range(lo, hi):
        p = eng.promising(DT[i])
        if p and p["verdict"] == "buy":
            moments.append((DT[i], p["highest"], p["lowest_10"], p["highest_dt"]))
    periods, cur = [], []
    for m in moments:
        if cur and (m[0] - cur[-1][0]).total_seconds() > gap_minutes * 60:
            periods.append(cur); cur = []
        cur.append(m)
    if cur:
        periods.append(cur)
    return periods, moments, (lo, hi)


def best_entry(period):
    """Best entry in a period = max available upside, ties broken by earliest."""
    return max(period, key=lambda m: (m[1], -m[0].timestamp()))


if __name__ != "__main__":
    pass  # importable; CLI below
else:
    SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    FROM = sys.argv[2] if len(sys.argv) > 2 else None
    TO = sys.argv[3] if len(sys.argv) > 3 else None
    GAP = int(sys.argv[4]) if len(sys.argv) > 4 else FORWARD_MINUTES

    eng = PromisingEngine(SYM, "asc")
    DT = eng.DT
    periods, moments, (lo, hi) = scan_periods(eng, FROM, TO, GAP)

    print(f"=== cluster_promising — symbol {SYM}, grid {DT[lo] if lo<hi else '-'}..{DT[hi-1] if hi>lo else '-'}, gap={GAP}m ===")
    print(f"raw promising moments: {len(moments)}  ->  periods: {len(periods)}"
          + (f"  ({len(moments)/len(periods):.1f} moments/period)" if periods else ""))
    print("-" * 92)
    print(f"{'period from':<21}{'period to':<21}{'best entry':<21}{'best up%':>9}{'dip%':>7}{'#mom':>6}")
    for per in periods:
        best = best_entry(per)
        print(f"{str(per[0][0]):<21}{str(per[-1][0]):<21}{str(best[0]):<21}{best[1]:>9.2f}{best[2]:>7.2f}{len(per):>6}")
    print("-" * 92)
    if periods:
        ups = [max(per, key=lambda m: m[1])[1] for per in periods]
        print(f"periods: {len(periods)} | avg best-upside {sum(ups)/len(ups):.2f}% | "
              f"max {max(ups):.2f}% | periods with >5% {sum(u>5 for u in ups)}")
    eng.close()
