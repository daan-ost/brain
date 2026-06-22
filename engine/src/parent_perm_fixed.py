#!/usr/bin/env python3
"""
PRE-REGISTERED permutatie-test (GEEN scan). Vaste hypothese: oversold-regime + EEN subregel
`volumeud|L20|standard_deviation >= p10(train-good-ticks)`. De drempel komt uit de labels, niet uit
het optimaliseren van de winst → geen multiple-testing-straf. Statistiek = holdout-mean profit_loss
van de geselecteerde trades; null = shuffle de holdout-winst over de oversold-pool en herbereken de
mean over dezelfde selectie. p = fractie null >= echt.
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
from parent_spoor1 import lshape
from sell_engine import SellEngine

IND, LB, MET = "volumeud", 20, "standard_deviation"


def test(symbol, name, K=3, seed=0, N=2000):
    random.seed(1); np.random.seed(seed)
    A = AsOf(symbol); eng = SellEngine(symbol)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    vecs = [[float(np.mean([A.val(o, t) for t in g])) for o in OSC] for g in groups]
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(StandardScaler().fit_transform(np.array(vecs))).labels_
    cl = min(range(K), key=lambda c: np.mean([vecs[i][2] for i in range(len(vecs)) if lab[i] == c] or [99]))
    cols = asof_arrays(A); tot = len(A.vdt)
    members = [i for i in range(len(groups)) if lab[i] == cl]
    tr = [i for i in members if groups[i][0].date() < split]
    bands = {o: (min(v), max(v)) for o in OSC
             for v in [[A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]]}
    base_surv = project_and(cols, A.vdt, bands, k=3)
    base_trades, _ = faithful_trades(eng, A, base_surv)

    # vaste drempel uit de train-good-ticks (labels), niet uit de winst
    gv = [lshape(A, IND, LB, MET, t) for i in tr for t in groups[i]]
    gv = [x for x in gv if x is not None and np.isfinite(x)]
    thr = float(np.percentile(gv, 10))

    pls = np.array([t["pl"] for t in base_trades])
    is_ho = np.array([t["buy_dt"].date() >= split for t in base_trades])
    sel = np.array([(lambda v: v is not None and v >= thr)(lshape(A, IND, LB, MET, t["buy_dt"])) for t in base_trades])

    pool_ho = pls[is_ho]                       # alle oversold-trades in holdout
    sel_ho = sel & is_ho
    real = float(pls[sel_ho].mean()); k = int(sel_ho.sum())
    # null: shuffle de holdout-winst, herbereken mean over dezelfde k posities
    ho_pls = pls[is_ho].copy()
    sel_within_ho = sel[is_ho]
    nulls = np.empty(N)
    for j in range(N):
        np.random.shuffle(ho_pls)
        nulls[j] = ho_pls[sel_within_ho].mean()
    p = float((nulls >= real).mean())
    # compact resultaat (vast format): promising groepen geraakt | goed/middel/slecht | Σprofit
    sel_trades = [base_trades[i] for i in range(len(base_trades)) if (sel & is_ho)[i]]
    spl = np.array([t["pl"] for t in sel_trades]) if sel_trades else np.array([0.0])
    g = int((spl >= 3).sum()); md = int(((spl >= 0) & (spl < 3)).sum()); bd = int((spl < 0).sum())
    allg, _ = rises(symbol)
    ho_groups = [gg for gg in allg if gg[0].date() >= split]
    sset = sorted(t["buy_dt"] for t in sel_trades); hit = 0
    for gg in ho_groups:
        j = bisect.bisect_left(sset, gg[0] - dt.timedelta(minutes=2))
        if j < len(sset) and sset[j] <= gg[-1] + dt.timedelta(minutes=2):
            hit += 1
    print(f"{name}: {hit}/{len(ho_groups)} promising groepen | goed {g} / middel {md} / slecht {bd} | "
          f"Σprofit {spl.sum():+.1f}% | gem {real:+.3f}%/trade (p={p:.3f})")


if __name__ == "__main__":
    test(244, "NOS")
    test(2525, "DOGEAI")
