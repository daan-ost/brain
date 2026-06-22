#!/usr/bin/env python3
"""
READ-ONLY full-period generalisatie-test van de "gemene deler"-methode, met tijd-holdout.

Methode (apples-to-apples met de dag-ontdekking):
  - groepeer ALLE handmatige yes-labels in rises (gap>5min => nieuwe rise), neem de eerste 3 ticks.
  - split rises op datum-mediaan: vroege helft = TRAIN, late helft = HOLDOUT.
  - per feature: zet de band [p10,p90] over de TRAIN goede-ticks, en meet:
      recall_train   = % TRAIN-rises waarvan alle 3 begin-ticks in de band (cascade-eis)
      recall_holdout = % HOLDOUT-rises idem  (generaliseert het?)
      bg_rate        = % achtergrond-ticks in de band (selectiviteit; lager=beter)
      bad_rate       = % no-ticks in de band (uitsluiting; lager=beter)
  - een feature is een KEEPER als recall_holdout hoog blijft EN bg_rate laag EN bad_rate laag.
"""
import bisect
import datetime as dt
import random
import sys

import numpy as np

from parent_discover import Features, manual_labels


def rises(symbol, n=3, gap_min=5):
    lab = manual_labels(symbol, dt.datetime(2000, 1, 1), dt.datetime(2100, 1, 1))
    yes = sorted(t for t, d in lab.items() if d == "yes")
    out, cur = [], []
    for y in yes:
        if cur and (y - cur[-1]).total_seconds() > gap_min * 60:
            out.append(cur); cur = []
        cur.append(y)
    if cur:
        out.append(cur)
    groups = [g[:n] for g in out if len(g) >= n]
    bad = sorted(t for t, d in lab.items() if d in ("no", "no_volume"))
    return groups, bad


def screen(symbol, seed=0):
    random.seed(seed)
    F = Features(symbol)
    groups, bad = rises(symbol)
    if len(groups) < 6:
        print(f"symbol {symbol}: te weinig rises ({len(groups)}) voor holdout"); return
    # split op mediaan-datum van de rise-start
    groups.sort(key=lambda g: g[0])
    mid = len(groups) // 2
    train, hold = groups[:mid], groups[mid:]
    print(f"symbol {symbol}: {len(groups)} rises (>=3 yes) | train={len(train)} hold={len(hold)} | bad-ticks={len(bad)}")
    print(f"  train periode: {train[0][0]:%Y-%m-%d} .. {train[-1][0]:%Y-%m-%d}")
    print(f"  hold  periode: {hold[0][0]:%Y-%m-%d} .. {hold[-1][0]:%Y-%m-%d}")

    train_ticks = [t for g in train for t in g]
    # achtergrond-sample
    s = F.series["volumeud"]
    bg_idx = random.sample(range(len(s["dt"])), min(4000, len(s["dt"])))
    bg_ticks = [s["dt"][i] for i in bg_idx]

    vt = {t: F.at(t) for t in train_ticks}
    vh = {t: F.at(t) for g in hold for t in g}
    vb = {t: F.at(t) for t in bad}
    vbg = {t: F.at(t) for t in bg_ticks}

    keys = set(vt[train_ticks[0]])
    for t in train_ticks[1:]:
        keys &= set(vt[t])

    def cascade_recall(rs, vecs, k, lo, hi):
        ok = 0
        for g in rs:
            if all((k in vecs[t]) and lo <= vecs[t][k] <= hi for t in g):
                ok += 1
        return ok / len(rs) if rs else 0.0

    rows = []
    for k in keys:
        gv = [vt[t][k] for t in train_ticks]
        lo, hi = float(np.percentile(gv, 10)), float(np.percentile(gv, 90))
        if hi <= lo:
            continue
        rtr = cascade_recall(train, vt, k, lo, hi)
        rho = cascade_recall(hold, vh, k, lo, hi)
        bg = np.mean([lo <= vbg[t][k] <= hi for t in bg_ticks if k in vbg[t]]) if bg_ticks else 1.0
        bd = np.mean([lo <= vb[t][k] <= hi for t in bad if k in vb[t]]) if bad else 1.0
        rows.append((k, lo, hi, rtr, rho, bg, bd))

    # keepers: generaliseert (holdout recall >=60%), selectief (bg<=25%), sluit slecht uit (bad<=25%)
    keep = [r for r in rows if r[4] >= 0.60 and r[5] <= 0.25 and r[6] <= 0.25]
    # dedupe per (indicator,metric) op laagste bg
    best = {}
    for r in keep:
        ind, lb, metric = r[0].split("|")
        key2 = (ind, metric)
        if key2 not in best or r[5] < best[key2][5]:
            best[key2] = r
    ranked = sorted(best.values(), key=lambda r: (-r[4], r[5]))
    print(f"  features bij alle train-ticks: {len(keys)} | keepers (holdout-recall>=60%, bg<=25%, bad<=25%): {len(best)}")
    print(f"  {'feature':40s} {'band':>22s} {'rTR':>5s} {'rHO':>5s} {'bg%':>5s} {'bad%':>5s}")
    for k, lo, hi, rtr, rho, bg, bd in ranked[:25]:
        print(f"  {k:40s} [{lo:8.3f},{hi:8.3f}] {100*rtr:4.0f} {100*rho:4.0f} {100*bg:4.0f} {100*bd:4.0f}")
    if not ranked:
        print("  >>> GEEN enkele feature generaliseert met holdout-recall>=60% + selectief + sluit-slecht-uit.")
    return ranked


if __name__ == "__main__":
    for sym in (int(sys.argv[1]),) if len(sys.argv) > 1 else (2525, 244):
        screen(sym)
        print()
