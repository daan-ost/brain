#!/usr/bin/env python3
"""
READ-ONLY cross-coin dry-run: pas een VASTE rule (op munt A gevonden) toe op munt B en rapporteer in
de standaardvorm op ALLEEN harde sell-engine-cijfers (geen best_upside). Toetst of rule 31 ook op
DOGEAI werkt, en of de schaal-invariante skewness-kern transfereert.
"""
import bisect

import numpy as np

from parent_crossgroup import AsOf, OSC
from parent_regimes import asof_arrays, project_and
from parent_eval import shape, faithful_trades, trade_stats, fmt_cls
from parent_fullperiod import rises
from sell_engine import SellEngine

# rule 31 zoals gevonden op NOS regime 2:
R31_OSC = {"obv-x-value": (61, 72), "vzo": (11, 88), "mfi": (44, 93), "phobos": (-49, 49)}
R31_REF = ("phobos", 10, "skewness", 0.578, 1.175)


def skew_array(A, ind, L, metric):
    from calc import window_metrics
    s = A.series[ind]; out = np.full(len(A.vdt), np.nan)
    for n, T in enumerate(A.vdt):
        i = bisect.bisect_right(s["dt"], T); w = s["v"][max(0, i - L):i][::-1]
        if len(w) >= 2:
            v = window_metrics(w).get(metric)
            if v is not None:
                out[n] = v
    return out


def cascade_band(arr, vdt, lo, hi, k=3):
    inb = (arr >= lo) & (arr <= hi)
    surv, run = [], 0
    for i in range(len(vdt)):
        run = run + 1 if inb[i] else 0
        if run >= k:
            surv.append(vdt[i])
    return surv


def evaluate(A, name, label, osc, ref):
    eng = SellEngine(A.symbol)
    if osc:
        cols = asof_arrays(A)
        surv = project_and(cols, A.vdt, osc, k=3)
        ind, L, m, lo, hi = ref
        surv = [t for t in surv if (lambda v: v is not None and lo <= v <= hi)(shape(A, ind, L, m, t))]
    else:
        ind, L, m, lo, hi = ref
        surv = cascade_band(skew_array(A, ind, L, m), A.vdt, lo, hi, k=3)
    trades, art = faithful_trades(eng, A, surv)
    st = trade_stats(eng, trades)
    allg, _ = rises(A.symbol); sset = sorted(surv); hit = 0
    import datetime as dt
    for g in allg:
        j = bisect.bisect_left(sset, g[0] - dt.timedelta(minutes=2))
        if j < len(sset) and sset[j] <= g[-1] + dt.timedelta(minutes=2):
            hit += 1
    print(f"\n===== {name}: {label} =====")
    print(f"  raakt {hit}/{len(allg)} groepjes | {st['n']} trades ({100*st['n']/len(A.vdt):.2f}% v.d. ticks) | "
          f"Σ {st['sigma']:+.1f}% | gem {st['mean']:+.3f}%/trade")
    print(f"  verdeling: {fmt_cls(st['cls'], st['n'])} | best-sell-gap {st['gap']:+.2f}%/trade")


if __name__ == "__main__":
    NOS = AsOf(244); DOG = AsOf(2525)
    evaluate(NOS, "NOS", "rule 31 VOL (sanity, eigen munt)", R31_OSC, R31_REF)
    evaluate(DOG, "DOGEAI", "rule 31 VOL (cross-coin: osc-band + skew)", R31_OSC, R31_REF)
    evaluate(DOG, "DOGEAI", "rule 31 SKEW-KERN (alleen phobos-skew)", None, R31_REF)
    evaluate(NOS, "NOS", "rule 31 SKEW-KERN (alleen phobos-skew)", None, R31_REF)
