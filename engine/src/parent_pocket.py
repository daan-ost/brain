#!/usr/bin/env python3
"""
PRECISIE-POCKET-zoektocht (rule-discovery §10, insteek #1). Niet "scheid alle groepen", maar: vind per
(indicator × lookback 1-20 × ~30 calcs) het DICHTSTE venster dat ~FRAC van de promising-t2-ticks vangt,
en meet de ACHTERGROND-rate (precisie). Train-venster, holdout-bevestigd. Rapporteer de features die op
BEIDE munten een precieze pocket geven (coin-agnostisch kenmerk, per-munt band). Alleen harde cijfers.
"""
import bisect
import datetime as dt
import random
import sys

import numpy as np

from calc import window_metrics
from parent_crossgroup import AsOf
from parent_fullperiod import rises
from parent_eval import faithful_trades, trade_stats, fmt_cls
from parent_child import FEATS, FIDX, feat_row
from sell_engine import SellEngine

FRAC = 0.40        # vang ~40% van de groepen in de pocket


def densest_window(vals, frac):
    v = np.sort(vals[np.isfinite(vals)])
    if len(v) < 8:
        return None
    k = max(3, int(np.ceil(frac * len(v))))
    if k > len(v):
        return None
    widths = v[k - 1:] - v[:len(v) - k + 1]
    i = int(np.argmin(widths))
    return float(v[i]), float(v[i + k - 1])


def prep(symbol):
    A = AsOf(symbol)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    ho_g = [g for g in groups if g[0].date() >= split]
    in_g = set(t for g in groups for t in g)
    pos_tr = [g[2] for g in groups if g[0].date() < split]
    pos_ho = [g[2] for g in ho_g]
    random.seed(1)
    bgpool = [A.vdt[i] for i in range(len(A.vdt)) if A.vdt[i] not in in_g]
    samp = random.sample(bgpool, min(10000, len(bgpool)))
    bg_tr = [t for t in samp if t.date() < split]; bg_ho = [t for t in samp if t.date() >= split]
    print(f"  {symbol}: {len(pos_tr)} train-pos, {len(pos_ho)} holdout-pos, {len(bg_tr)}/{len(bg_ho)} bg", flush=True)
    return dict(A=A, split=split, ho_g=ho_g,
                Ptr=np.array([feat_row(A, t) for t in pos_tr]),
                Pho=np.array([feat_row(A, t) for t in pos_ho]),
                Btr=np.array([feat_row(A, t) for t in bg_tr]),
                Bho=np.array([feat_row(A, t) for t in bg_ho]))


def pockets(D):
    """per feature: train-venster (dichtste FRAC), bg-rate, holdout-capture + holdout-bg."""
    out = {}
    Ptr, Pho, Btr, Bho = D["Ptr"], D["Pho"], D["Btr"], D["Bho"]
    for fi, f in enumerate(FEATS):
        win = densest_window(Ptr[:, fi], FRAC)
        if win is None:
            continue
        lo, hi = win
        if hi <= lo:
            continue
        def rate(M):
            col = M[:, fi]; ok = np.isfinite(col)
            return float(((col >= lo) & (col <= hi) & ok).sum()) / max(1, len(col))
        out[f] = dict(lo=lo, hi=hi, bg_tr=rate(Btr), cap_ho=rate(Pho), bg_ho=rate(Bho))
    return out


def main():
    print("prep DOGEAI...", flush=True); DD = prep(2525)
    print("prep NOS...", flush=True); DN = prep(244)
    pd, pn = pockets(DD), pockets(DN)
    shared = set(pd) & set(pn)
    # coin-agnostisch: precies op BEIDE (bg_tr<0.5%) én vangt holdout-groepen op beide (cap_ho>=25%)
    keep = []
    for f in shared:
        a, b = pd[f], pn[f]
        if a["bg_tr"] < 0.005 and b["bg_tr"] < 0.005 and a["cap_ho"] >= 0.25 and b["cap_ho"] >= 0.25:
            score = min(a["cap_ho"], b["cap_ho"]) / (max(a["bg_ho"], b["bg_ho"]) + 1e-4)
            keep.append((score, f, a, b))
    keep.sort(key=lambda x: -x[0])
    print(f"\n=== precisie-pockets op BEIDE munten (bg_train<0,5% & holdout-capture>=25% beide) : {len(keep)} ===")
    print(f"{'feature':34s} | DOGEAI cap_ho/bg_ho | NOS cap_ho/bg_ho")
    for score, f, a, b in keep[:15]:
        ind, lb, m = f
        print(f"{ind}|L{lb}|{m:26s} | {100*a['cap_ho']:4.0f}% / {100*a['bg_ho']:.2f}%   | {100*b['cap_ho']:4.0f}% / {100*b['bg_ho']:.2f}%")
    if not keep:
        print(">>> GEEN feature geeft een precieze pocket (bg<0,5%) die op beide munten >=25% groepen vangt.")
        # toon het beste compromis ter info
        best = sorted(((min(pd[f]['cap_ho'], pn[f]['cap_ho']), max(pd[f]['bg_tr'], pn[f]['bg_tr']), f)
                       for f in shared if pd[f]['cap_ho'] > 0 and pn[f]['cap_ho'] > 0), key=lambda x: (-x[0], x[1]))[:8]
        print("  beste compromis (hoogste min-capture):")
        for cap, bg, f in best:
            ind, lb, m = f
            print(f"    {ind}|L{lb}|{m:26s} min-cap_ho {100*cap:.0f}% | max bg_tr {100*bg:.2f}%")


if __name__ == "__main__":
    main()
