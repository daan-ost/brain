#!/usr/bin/env python3
"""
RULE 30 — CHILD-rule builder. Vanaf t2 (de 3e tick van elk promising groepje) de VOLLE ~30 berekeningen
x lookback 1-20, en greedy een platte AND van subregels (zoals 20-23) die de promising-t2-ticks scheidt
van de achtergrond → van firehose naar 20-23-schaal, landend op de echte stijgingen.

Eerlijk: subregels gekozen op de TRAIN-dagen; gerapporteerd op de HOLDOUT (de holdout-split is de
overfit-toets — generaliseert de scheiding naar ongeziene dagen?). Alleen harde profit_loss.
Compact format per munt: N/M promising groepen | goed/middel/slecht | Σprofit.
"""
import bisect
import datetime as dt
import random
import sys

import numpy as np

from calc import window_metrics, WINDOW_METRIC_KEYS
from parent_crossgroup import AsOf
from parent_fullperiod import rises
from parent_eval import faithful_trades, trade_stats, fmt_cls
from sell_engine import SellEngine

INDS = ("volumeud", "phobos", "obv-x-value", "vzo", "mfi")
LBS = tuple(range(1, 21))
METRICS = tuple(WINDOW_METRIC_KEYS)
FEATS = [(ind, lb, m) for ind in INDS for lb in LBS for m in METRICS]
FIDX = {f: i for i, f in enumerate(FEATS)}


def feat_row(A, T):
    row = np.full(len(FEATS), np.nan)
    for ind in INDS:
        s = A.series[ind]; k = bisect.bisect_right(s["dt"], T)
        for lb in LBS:
            w = s["v"][max(0, k - lb):k][::-1]
            if len(w) >= 2:
                mm = window_metrics(w)
                for m in METRICS:
                    v = mm.get(m)
                    if v is not None and np.isfinite(v):
                        row[FIDX[(ind, lb, m)]] = v
    return row


def build(symbol, name, K=3, seed=0):
    random.seed(1); np.random.seed(seed)
    A = AsOf(symbol); eng = SellEngine(symbol); tot = len(A.vdt)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    tr_groups = [g for g in groups if g[0].date() < split]
    ho_groups = [g for g in groups if g[0].date() >= split]
    in_group = set(t for g in groups for t in g)

    pos_tr = [g[2] for g in tr_groups]                       # t2 = 3e tick van elk train-groepje
    bg_pool = [A.vdt[i] for i in range(tot) if A.vdt[i].date() < split and A.vdt[i] not in in_group]
    bg_tr = random.sample(bg_pool, min(6000, len(bg_pool)))
    print(f"  {name}: {len(pos_tr)} train-positives (t2), {len(bg_tr)} achtergrond-ticks", flush=True)

    P = np.array([feat_row(A, t) for t in pos_tr])
    B = np.array([feat_row(A, t) for t in bg_tr])

    # greedy AND: kies de subregel die de meeste achtergrond wegfiltert, mits train-recall >= 50%
    pos_ok = np.ones(len(P), dtype=bool); bg_ok = np.ones(len(B), dtype=bool)
    chosen = []
    for step in range(10):
        base_pos = pos_ok.sum(); base_bg = bg_ok.sum()
        best = None
        for fi, f in enumerate(FEATS):
            pv = P[:, fi]; bv = B[:, fi]
            ok = np.isfinite(pv)
            if ok.sum() < 0.6 * len(P):
                continue
            lo, hi = np.nanpercentile(pv, 5), np.nanpercentile(pv, 95)
            if hi <= lo:
                continue
            for side, cond_p, cond_b in (
                ("band", (pv >= lo) & (pv <= hi), (bv >= lo) & (bv <= hi)),
                ("ge", pv >= lo, bv >= lo),
                ("le", pv <= hi, bv <= hi)):
                npos = (pos_ok & cond_p & np.isfinite(pv)).sum()
                if npos < 0.5 * len(P):
                    continue
                nbg = (bg_ok & cond_b & np.isfinite(bv)).sum()
                removed = base_bg - nbg
                if removed <= 0:
                    continue
                # score: meeste achtergrond weg per verloren positive
                lost = base_pos - npos
                score = removed / (lost + 1)
                if best is None or score > best[0]:
                    thr = (lo, hi) if side == "band" else (lo if side == "ge" else hi)
                    best = (score, fi, f, side, thr, cond_p, cond_b, npos, nbg)
        if best is None:
            break
        _, fi, f, side, thr, cond_p, cond_b, npos, nbg = best
        pos_ok = pos_ok & cond_p & np.isfinite(P[:, fi])
        bg_ok = bg_ok & cond_b & np.isfinite(B[:, fi])
        chosen.append((f, side, thr))
        if bg_ok.sum() <= max(3, 0.002 * len(B)) * 1:        # achtergrond ~weg
            if bg_ok.sum() / len(B) < 0.01:
                break

    print(f"  {name}: rule 30 child = {len(chosen)} subregels | train-recall {100*pos_ok.mean():.0f}% | achtergrond-fire {100*bg_ok.mean():.2f}%", flush=True)
    for (ind, lb, m), side, thr in chosen:
        cond = f"in [{thr[0]:.3f},{thr[1]:.3f}]" if side == "band" else (f">= {thr:.3f}" if side == "ge" else f"<= {thr:.3f}")
        print(f"        {ind}|L{lb}|{m} {cond}")

    # projecteer over de hele periode (alleen de gekozen subregels) → fire-ticks
    def passes(T):
        for (ind, lb, m), side, thr in chosen:
            s = A.series[ind]; k = bisect.bisect_right(s["dt"], T); w = s["v"][max(0, k - lb):k][::-1]
            if len(w) < 2:
                return False
            v = window_metrics(w).get(m)
            if v is None or not np.isfinite(v):
                return False
            if side == "band" and not (thr[0] <= v <= thr[1]):
                return False
            if side == "ge" and v < thr:
                return False
            if side == "le" and v > thr:
                return False
        return True
    fires = [T for T in A.vdt if passes(T)]
    trades, _ = faithful_trades(eng, A, fires)
    ho = [t for t in trades if t["buy_dt"].date() >= split]
    st = trade_stats(eng, ho, with_gap=False)
    sset = sorted(t["buy_dt"] for t in ho); hit = 0
    for g in ho_groups:
        j = bisect.bisect_left(sset, g[0] - dt.timedelta(minutes=2))
        if j < len(sset) and sset[j] <= g[-1] + dt.timedelta(minutes=2):
            hit += 1
    print(f"\n{name}: {hit}/{len(ho_groups)} promising groepen | goed {st['cls']['goed']} / middel {st['cls']['middel']} / slecht {st['cls']['slecht']} | "
          f"Σprofit {st['sigma']:+.1f}% | {st['n']} trades ({100*st['n']/tot:.3f}% v.d. ticks) | gem {st['mean']:+.3f}%/trade")


if __name__ == "__main__":
    build(2525, "DOGEAI")
    build(244, "NOS")
