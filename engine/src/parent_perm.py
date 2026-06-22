#!/usr/bin/env python3
"""
PERMUTATIE-TEST tegen multiple-testing: is de spoor-1 holdout-edge echt of selectie-toeval?
Shuffle de profit_loss over de basis-trades (binnen train en binnen holdout apart → distributies blijven),
draai DEZELFDE greedy one-sided stacking N keer, en meet hoe vaak het toeval een holdout-mean ≥ de echte
haalt. p = fractie null-runs ≥ echt. p klein (<0,05) = echte edge; p groot = de edge is scan-artefact.
"""
import bisect
import datetime as dt
import random
import sys

import numpy as np
from sklearn.cluster import KMeans
from sklearn.preprocessing import StandardScaler

from parent_crossgroup import AsOf, OSC
from parent_fullperiod import rises
from parent_regimes import asof_arrays, project_and
from parent_eval import faithful_trades, trade_stats
from parent_spoor1 import arrays1, lshape, LB1, METRICS1, INDS1
from sell_engine import SellEngine


def greedy(pls, cand, is_ho, is_tr, gid, ng, base_mean, tot, n_trades, max_sub=4):
    mask = np.ones(n_trades, dtype=bool)
    for _ in range(max_sub):
        cur_n = int(mask.sum()); best = None
        for (ind, lb, m, side, thr, vals) in cand:
            cm = mask & np.isfinite(vals) & ((vals >= thr) if side == "ge" else (vals <= thr))
            n = int(cm.sum())
            if n >= cur_n * 0.95 or n < 40:
                continue
            if int((cm & is_ho).sum()) < 15:
                continue
            if np.unique(gid[cm & (gid >= 0)]).size < 0.5 * ng:
                continue
            trp = pls[cm & is_tr]
            if trp.size == 0 or float(trp.mean()) < base_mean:
                continue
            if best is None or n < best[1]:
                best = (cm, n)
        if best is None:
            break
        mask = best[0]
        if mask.sum() / tot < 0.0005:
            break
    return mask


def perm_test(symbol, name, K=3, seed=0, N=300):
    random.seed(1); np.random.seed(seed)
    A = AsOf(symbol); eng = SellEngine(symbol)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    vecs = [[float(np.mean([A.val(o, t) for t in g])) for o in OSC] for g in groups]
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(StandardScaler().fit_transform(np.array(vecs))).labels_
    cl = min(range(K), key=lambda c: np.mean([vecs[i][2] for i in range(len(vecs)) if lab[i] == c] or [99]))
    cols = asof_arrays(A); tot = len(A.vdt); idx_of = {T: i for i, T in enumerate(A.vdt)}
    bsamp = [A.vdt[i] for i in random.sample(range(tot), min(8000, tot)) if A.vdt[i].date() >= split]
    bt, _ = faithful_trades(eng, A, bsamp); base_mean = trade_stats(eng, bt, with_gap=False)["mean"]
    members = [i for i in range(len(groups)) if lab[i] == cl]
    tr = [i for i in members if groups[i][0].date() < split]
    bands = {o: (min(v), max(v)) for o in OSC
             for v in [[A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]]}
    base_surv = project_and(cols, A.vdt, bands, k=3)
    base_trades, _ = faithful_trades(eng, A, base_surv)
    n_tr = len(base_trades)
    SH = arrays1(A)
    bt_idx = np.array([idx_of[t["buy_dt"]] for t in base_trades])
    pls = np.array([t["pl"] for t in base_trades])
    dates = [t["buy_dt"] for t in base_trades]
    is_ho = np.array([d.date() >= split for d in dates]); is_tr = ~is_ho
    tr_windows = [(groups[i][0] - dt.timedelta(minutes=2), groups[i][-1] + dt.timedelta(minutes=2)) for i in tr]
    gid = np.full(n_tr, -1)
    for ti, d in enumerate(dates):
        if d.date() < split:
            for gi, (w0, w1) in enumerate(tr_windows):
                if w0 <= d <= w1:
                    gid[ti] = gi; break
    ng = len(tr)
    tr_ticks = [t for i in tr for t in groups[i]]
    cand = []
    for ind in INDS1:
        for lb in LB1:
            for m in METRICS1:
                gv = [lshape(A, ind, lb, m, t) for t in tr_ticks]
                gv = [x for x in gv if x is not None and np.isfinite(x)]
                if len(gv) < 8:
                    continue
                vals = SH[(ind, lb)][m][bt_idx]
                cand.append((ind, lb, m, "ge", float(np.percentile(gv, 10)), vals))
                cand.append((ind, lb, m, "le", float(np.percentile(gv, 90)), vals))

    real_mask = greedy(pls, cand, is_ho, is_tr, gid, ng, base_mean, tot, n_tr)
    real_stat = float(pls[real_mask & is_ho].mean()) if (real_mask & is_ho).any() else 0.0
    real_n = int((real_mask & is_ho).sum())

    tr_idx = np.where(is_tr)[0]; ho_idx = np.where(is_ho)[0]
    nulls = []
    for _ in range(N):
        p = pls.copy()
        p[tr_idx] = np.random.permutation(pls[tr_idx])
        p[ho_idx] = np.random.permutation(pls[ho_idx])
        m = greedy(p, cand, is_ho, is_tr, gid, ng, base_mean, tot, n_tr)
        nulls.append(float(p[m & is_ho].mean()) if (m & is_ho).any() else 0.0)
    nulls = np.array(nulls)
    pval = float((nulls >= real_stat).mean())
    print(f"\n==== {name} oversold-rule — permutatie-test (N={N}) ====")
    print(f"  ECHTE holdout-mean: {real_stat:+.3f}%/trade ({real_n} trades) | baseline {base_mean:+.3f}%")
    print(f"  NULL (geshuffeld): mean {nulls.mean():+.3f}% | p50 {np.percentile(nulls,50):+.3f}% | p95 {np.percentile(nulls,95):+.3f}% | max {nulls.max():+.3f}%")
    print(f"  p-waarde (fractie null ≥ echt): {pval:.3f}  -> {'ECHTE edge (p<0,05)' if pval < 0.05 else 'NIET significant — scan-artefact' if pval > 0.1 else 'grensgeval'}")


if __name__ == "__main__":
    perm_test(244, "NOS")
    perm_test(2525, "DOGEAI")
