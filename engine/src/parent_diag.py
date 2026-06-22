#!/usr/bin/env python3
"""READ-ONLY diagnostiek: plafond-recall, relatieve-obv, en multivariate conjunctie (beide munten)."""
import bisect
import datetime as dt
import random
import sys

import numpy as np

from parent_discover import Features, manual_labels
from parent_fullperiod import rises


def diag(symbol, seed=0):
    random.seed(seed)
    F = Features(symbol)
    groups, bad = rises(symbol)
    groups.sort(key=lambda g: g[0])
    mid = len(groups) // 2
    train, hold = groups[:mid], groups[mid:]
    train_ticks = [t for g in train for t in g]
    s = F.series["volumeud"]
    bg_ticks = [s["dt"][i] for i in random.sample(range(len(s["dt"])), min(4000, len(s["dt"])))]
    vt = {t: F.at(t) for t in train_ticks}
    vh = {t: F.at(t) for g in hold for t in g}
    vb = {t: F.at(t) for t in bad}
    vbg = {t: F.at(t) for t in bg_ticks}
    keys = set(vt[train_ticks[0]])
    for t in train_ticks[1:]:
        keys &= set(vt[t])

    def crec(rs, vecs, k, lo, hi):
        return (sum(1 for g in rs if all((k in vecs[t]) and lo <= vecs[t][k] <= hi for t in g))
                / len(rs)) if rs else 0.0

    print(f"=== symbol {symbol} — PLAFOND: top features op holdout-cascade-recall (geen gate) ===")
    rows = []
    for k in keys:
        gv = [vt[t][k] for t in train_ticks]
        lo, hi = float(np.percentile(gv, 10)), float(np.percentile(gv, 90))
        if hi <= lo:
            continue
        rho = crec(hold, vh, k, lo, hi)
        rtr = crec(train, vt, k, lo, hi)
        bg = np.mean([lo <= vbg[t][k] <= hi for t in bg_ticks if k in vbg[t]])
        bd = np.mean([lo <= vb[t][k] <= hi for t in bad if k in vb[t]]) if bad else 1.0
        rows.append((k, lo, hi, rtr, rho, bg, bd))
    for k, lo, hi, rtr, rho, bg, bd in sorted(rows, key=lambda r: -r[4])[:12]:
        print(f"  {k:38s} [{lo:8.2f},{hi:8.2f}] rTR={100*rtr:3.0f} rHO={100*rho:3.0f} bg={100*bg:3.0f}% bad={100*bd:3.0f}%")

    # ---- relatieve obv: obv_now - mediaan(obv laatste W ticks); en obv-percentiel in laatste W ----
    od = F.series["obv-x-value"]
    odt, oval = od["dt"], od["v"]
    def obv_rel(T, W=60):
        i = bisect.bisect_right(odt, T)
        win = oval[max(0, i - W):i]
        if len(win) < 5:
            return None, None
        med = float(np.median(win))
        pr = float(np.mean([1 if x <= win[-1] else 0 for x in win]))  # percentiel-rang van nu
        return win[-1] - med, pr
    print(f"\n=== symbol {symbol} — RELATIEVE obv (nu - mediaan laatste 60) + percentiel-rang ===")
    for label, ticks in [("GOED(train+hold)", train_ticks + [t for g in hold for t in g]),
                         ("SLECHT", bad), ("ACHTERGROND", bg_ticks)]:
        rels = [obv_rel(t)[0] for t in ticks]; rels = [x for x in rels if x is not None]
        prs = [obv_rel(t)[1] for t in ticks]; prs = [x for x in prs if x is not None]
        if rels:
            print(f"  {label:18s} n={len(rels):4d}  obv-rel p25={np.percentile(rels,25):6.1f} p50={np.percentile(rels,50):6.1f} p75={np.percentile(rels,75):6.1f} | pct-rang p50={np.percentile(prs,50):.2f}")

    # ---- multivariate conjunctie: obv in [40,48] AND vzo<0 AND price stijgt over 3 ticks ----
    def conj(T):
        f = F.at(T)
        obv = f.get("obv-x-value|L1|current_value")
        vzo = f.get("vzo|L1|current_value")
        pr3 = f.get("price|L3|diff_previous_value")
        if obv is None or vzo is None or pr3 is None:
            return False
        return (40 <= obv <= 48) and (vzo < 0) and (pr3 > 0)
    print(f"\n=== symbol {symbol} — CONJUNCTIE: obv∈[40,48] & vzo<0 & price3>0 (cascade over 3 ticks) ===")
    rho = sum(1 for g in hold if all(conj(t) for t in g)) / len(hold)
    rtr = sum(1 for g in train if all(conj(t) for t in g)) / len(train)
    bg = np.mean([conj(t) for t in bg_ticks])
    bd = np.mean([conj(t) for t in bad]) if bad else 0.0
    print(f"  recall train={100*rtr:.0f}%  holdout={100*rho:.0f}%  achtergrond-fire={100*bg:.1f}%  slecht-fire={100*bd:.1f}%")


if __name__ == "__main__":
    for sym in (int(sys.argv[1]),) if len(sys.argv) > 1 else (2525, 244):
        diag(sym); print()
