#!/usr/bin/env python3
"""
FUNNEL-METHODIEK (rule-discovery §10). De methodiek = goede segmentaties stapelen en per subregel de
TRECHTER volgen: hoeveel promising groepen hou je (recall, train+holdout) en op hoeveel ticks vuur je
nog (selectiviteit). Greedy: kies steeds de subregel die de tick-fire het meest INDIKT terwijl >=60%
van de huidige groepen blijft. Curated pool (volume → indicator-value → prijs), zoals 20-23 ontstonden.
"""
import bisect
import random
import sys

import numpy as np

from calc import window_metrics, calc_percentage
from parent_crossgroup import AsOf
from parent_fullperiod import rises


def at(A, ind, lb, m, T):
    s = A.series[ind]; k = bisect.bisect_right(s["dt"], T); w = s["v"][max(0, k - lb):k][::-1]
    return window_metrics(w).get(m) if len(w) >= 1 else None


def pfeat(A, T, lb, kind):
    i = bisect.bisect_right(A.vdt, T); pr = [p for p in A.vpx[max(0, i - lb):i] if p is not None]
    if len(pr) < 2 or not pr[0]:
        return None
    if kind == "change":
        return calc_percentage(pr[0], pr[-1])
    if kind == "mindip":
        return 100 * (min(pr) - pr[0]) / pr[0]
    if kind == "consec":
        return window_metrics(pr[::-1]).get("consecutive_increases")


def candidates(A):
    C = []
    for lb in (5, 10, 20):
        C.append((f"volume #neg/{lb}", lambda T, lb=lb: at(A, "volumeud", lb, "count_negative", T)))
        C.append((f"volume std/{lb}", lambda T, lb=lb: at(A, "volumeud", lb, "standard_deviation", T)))
    C.append(("volume now", lambda T: at(A, "volumeud", 1, "current_value", T)))
    for ind in ("obv-x-value", "vzo", "mfi", "phobos"):
        C.append((f"{ind} cv", lambda T, ind=ind: at(A, ind, 1, "current_value", T)))
    for lb in (3, 5, 10):
        C.append((f"prijs change/{lb}", lambda T, lb=lb: pfeat(A, T, lb, "change")))
        C.append((f"prijs mindip/{lb}", lambda T, lb=lb: pfeat(A, T, lb, "mindip")))
    for lb in (5, 10):
        C.append((f"prijs consec/{lb}", lambda T, lb=lb: pfeat(A, T, lb, "consec")))
    return C


def run(symbol, name, recall_floor=0.6):
    A = AsOf(symbol); groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    gtr = [g for g in groups if g[0].date() < split]
    gho = [g for g in groups if g[0].date() >= split]
    random.seed(1)
    tot = len(A.vdt); bg = random.sample(range(tot), min(9000, tot))
    bgtr = [A.vdt[i] for i in bg if A.vdt[i].date() < split]
    bgho = [A.vdt[i] for i in bg if A.vdt[i].date() >= split]
    C = candidates(A)
    # waarden vooraf
    gtr_t = [g[2] for g in gtr]; gho_t = [g[2] for g in gho]
    def vec(fn, ticks):
        return np.array([fn(t) if fn(t) is not None else np.nan for t in ticks])
    cols = []
    for label, fn in C:
        gtrv = vec(fn, gtr_t)
        lo, hi = np.nanpercentile(gtrv, 10), np.nanpercentile(gtrv, 90)
        cols.append((label, lo, hi, gtrv, vec(fn, gho_t), vec(fn, bgtr), vec(fn, bgho)))

    keep_tr = np.ones(len(gtr_t), bool); keep_ho = np.ones(len(gho_t), bool)
    keep_btr = np.ones(len(bgtr), bool); keep_bho = np.ones(len(bgho), bool)
    print(f"\n################ {name} — FUNNEL (start: alle ticks) ################")
    print(f"  start: {len(gtr)} train-grp / {len(gho)} holdout-grp | bg-fire 100%")
    used = set()
    for step in range(8):
        base_btr = keep_btr.sum()
        best = None
        for ci, (label, lo, hi, gtrv, ghov, btrv, bhov) in enumerate(cols):
            if ci in used:
                continue
            for side in ("band", "ge", "le"):
                def msk(v):
                    ok = np.isfinite(v)
                    return ok & ((v >= lo) & (v <= hi) if side == "band" else (v >= lo) if side == "ge" else (v <= hi))
                ntr = (keep_tr & msk(gtrv)).sum()
                if ntr < recall_floor * keep_tr.sum() or ntr < 8:
                    continue
                nbtr = (keep_btr & msk(btrv)).sum()
                if nbtr >= base_btr:
                    continue
                if best is None or nbtr < best[0]:
                    best = (nbtr, ci, side, label, lo, hi)
        if best is None:
            print("  geen subregel houdt >=60% groepen én dikt verder in."); break
        _, ci, side, label, lo, hi = best
        used.add(ci)
        _, _, _, gtrv, ghov, btrv, bhov = cols[ci]
        def msk(v):
            ok = np.isfinite(v)
            return ok & ((v >= lo) & (v <= hi) if side == "band" else (v >= lo) if side == "ge" else (v <= hi))
        keep_tr &= msk(gtrv); keep_ho &= msk(ghov); keep_btr &= msk(btrv); keep_bho &= msk(bhov)
        cond = f"in[{lo:.2f},{hi:.2f}]" if side == "band" else (f">={lo:.2f}" if side == "ge" else f"<={hi:.2f}")
        print(f"  +{label} {cond:18s} | train-grp {keep_tr.sum():3d}/{len(gtr)} ({100*keep_tr.sum()/len(gtr):.0f}%) "
              f"| holdout-grp {keep_ho.sum():2d}/{len(gho)} ({100*keep_ho.sum()/len(gho):.0f}%) "
              f"| bg-fire {100*keep_btr.mean():.2f}% (ho {100*keep_bho.mean():.2f}%)")
        if keep_btr.mean() < 0.001:
            print("  -> bg-fire <0,1% (20-23-schaal) bereikt."); break


if __name__ == "__main__":
    run(2525, "DOGEAI"); run(244, "NOS")
