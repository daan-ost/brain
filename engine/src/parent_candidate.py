#!/usr/bin/env python3
"""
KANDIDAAT-signatuur uit de phobos-segment binding-analyse (rule-discovery §10):
  phobos cv in [p10,p90 train]  AND  geen daling /3 (min-dip3 >= p10 train)  AND  kleine stijging
  (change3 >= p10 train).  Drempels uit de TRAIN-labels (pre-registered), projecteer over de hele
periode, trouwe dedup, en breng de PRECISIE erbij. Compact per munt: groepen | goed/middel/slecht | Σ.
"""
import bisect
import datetime as dt
import random

import numpy as np

from calc import window_metrics, calc_percentage
from parent_crossgroup import AsOf
from parent_fullperiod import rises
from parent_eval import faithful_trades, trade_stats, fmt_cls


def phobos_cv(A, T):
    s = A.series["phobos"]; k = bisect.bisect_right(s["dt"], T)
    return s["v"][k - 1] if k > 0 else None


def price3(A, i):
    pr = [p for p in A.vpx[max(0, i - 3):i] if p is not None]
    if len(pr) < 2 or not pr[0]:
        return None, None
    return calc_percentage(pr[0], pr[-1]), 100 * (min(pr) - pr[0]) / pr[0]   # change, min-dip


def run(symbol, name):
    A = AsOf(symbol); from sell_engine import SellEngine; eng = SellEngine(symbol)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    tr = [g for g in groups if g[0].date() < split]
    ho = [g for g in groups if g[0].date() >= split]
    # drempels uit train-groepjes (t2)
    pcv, ch3, md3 = [], [], []
    for g in tr:
        T = g[2]; i = bisect.bisect_right(A.vdt, T)
        pcv.append(phobos_cv(A, T)); c, d = price3(A, i); ch3.append(c); md3.append(d)
    pcv = [x for x in pcv if x is not None]; ch3 = [x for x in ch3 if x is not None]; md3 = [x for x in md3 if x is not None]
    plo, phi = np.percentile(pcv, 10), np.percentile(pcv, 90)
    dip_thr = np.percentile(md3, 10)        # geen daling erger dan dit (houdt ~90% groepen)
    rise_thr = np.percentile(ch3, 10)
    print(f"\n{name}: phobos cv in [{plo:.0f},{phi:.0f}]  &  min-dip3 >= {dip_thr:.2f}%  &  change3 >= {rise_thr:.2f}%")

    # projecteer over alle volumeud-ticks
    fires = []
    for i, T in enumerate(A.vdt):
        v = phobos_cv(A, T)
        if v is None or not (plo <= v <= phi):
            continue
        c, d = price3(A, i)
        if c is None or d < dip_thr or c < rise_thr:
            continue
        fires.append(T)
    trades, _ = faithful_trades(eng, A, fires)
    hot = [t for t in trades if t["buy_dt"].date() >= split]
    st = trade_stats(eng, hot, with_gap=False)
    tot = len(A.vdt)
    sset = sorted(t["buy_dt"] for t in hot); hit = 0
    for g in ho:
        j = bisect.bisect_left(sset, g[0] - dt.timedelta(minutes=2))
        if j < len(sset) and sset[j] <= g[-1] + dt.timedelta(minutes=2):
            hit += 1
    # baseline op holdout-dagen
    random.seed(1)
    bs = [A.vdt[i] for i in random.sample(range(tot), min(6000, tot)) if A.vdt[i].date() >= split]
    bt, _ = faithful_trades(eng, A, bs); bm = trade_stats(eng, bt, with_gap=False)["mean"]
    print(f"{name}: {hit}/{len(ho)} promising groepen | goed {st['cls']['goed']} / middel {st['cls']['middel']} / slecht {st['cls']['slecht']} | "
          f"Σprofit {st['sigma']:+.1f}% | {st['n']} trades ({100*st['n']/tot:.2f}% v.d. ticks) | gem {st['mean']:+.3f}%/trade (baseline {bm:+.3f})")


if __name__ == "__main__":
    run(2525, "DOGEAI")
    run(244, "NOS")
