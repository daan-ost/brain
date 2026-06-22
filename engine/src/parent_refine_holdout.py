#!/usr/bin/env python3
"""
READ-ONLY: regime + vorm-VERFIJNING met EERLIJKE holdout. Per regime:
  - basis = osc-band uit de train-groepjes (parent-cascade k=3) → survivors → trades (sell-engine);
  - scan vorm-subregels (ind × lookback × metric): band uit TRAIN-groep-ticks, filter de trades,
    SELECTEER de winnaar op de TRAIN-dagen (mean profit_loss, mits hij selectiever maakt);
  - rapporteer de winnaar op de HOLDOUT-dagen (out-of-sample), exact via filter-survivors→dedup.
Alleen harde gerealiseerde profit_loss; geen best_upside.
"""
import bisect
import datetime as dt
import random
import sys

import numpy as np
from sklearn.cluster import KMeans
from sklearn.preprocessing import StandardScaler

from calc import window_metrics
from parent_crossgroup import AsOf, OSC
from parent_fullperiod import rises
from parent_regimes import asof_arrays, project_and
from parent_eval import faithful_trades, trade_stats, fmt_cls, shape

REF_METRICS = ("skewness", "range_percentage", "volatility", "standard_deviation", "consecutive_increases")
REF_INDS = ("volumeud", "phobos", "obv-x-value", "vzo", "mfi")
REF_LB = (10, 20)


def shape_arrays(A):
    """per (ind,lb): {metric: np.array over alle volumeud-ticks} (as-of)."""
    cache = {}
    for ind in REF_INDS:
        s = A.series[ind]
        for lb in REF_LB:
            cols = {m: np.full(len(A.vdt), np.nan) for m in REF_METRICS}
            for n, T in enumerate(A.vdt):
                k = bisect.bisect_right(s["dt"], T); w = s["v"][max(0, k - lb):k][::-1]
                if len(w) >= 2:
                    m = window_metrics(w)
                    for mk in REF_METRICS:
                        v = m.get(mk)
                        if v is not None:
                            cols[mk][n] = v
            cache[(ind, lb)] = cols
    return cache


def discover(symbol, name, K=3, seed=0):
    random.seed(1); np.random.seed(seed)
    A = AsOf(symbol)
    from sell_engine import SellEngine
    eng = SellEngine(symbol)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    vecs = [[float(np.mean([A.val(o, t) for t in g])) for o in OSC] for g in groups]
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(StandardScaler().fit_transform(np.array(vecs))).labels_
    cols = asof_arrays(A); tot = len(A.vdt)
    idx_of = {T: i for i, T in enumerate(A.vdt)}
    SH = shape_arrays(A)

    bsamp = [A.vdt[i] for i in random.sample(range(tot), min(8000, tot)) if A.vdt[i].date() >= split]
    bt, _ = faithful_trades(eng, A, bsamp); bst = trade_stats(eng, bt, with_gap=False)
    print(f"\n################ {name} ({symbol}) — holdout vanaf {split} | baseline gem {bst['mean']:+.3f}%/trade ################")

    for cl in range(K):
        members = [i for i in range(len(groups)) if lab[i] == cl]
        tr = [i for i in members if groups[i][0].date() < split]
        if len(tr) < 4:
            continue
        bands = {o: (min(v), max(v)) for o in OSC
                 for v in [[A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]]}
        base_surv = project_and(cols, A.vdt, bands, k=3)
        base_trades, _ = faithful_trades(eng, A, base_surv)          # 1 sell-engine pass
        # train-ticks van dit regime (voor de refinement-band)
        tr_ticks = [t for i in tr for t in groups[i]]
        ctr = np.mean([vecs[i] for i in members], axis=0)
        print(f"\n  == regime {cl}: center obv={ctr[0]:.0f} vzo={ctr[1]:.0f} mfi={ctr[2]:.0f} phobos={ctr[3]:.0f} | basis {len(base_trades)} trades ({100*len(base_trades)/tot:.2f}%)")

        # scan refinements: kies op TRAIN, approx via base-trades-filter (snel, geen sell-engine in de loop)
        best = None
        for ind in REF_INDS:
            for lb in REF_LB:
                arr = SH[(ind, lb)]
                for m in REF_METRICS:
                    gv = [SH_at(SH, ind, lb, m, t, A) for t in tr_ticks]
                    gv = [x for x in gv if x is not None and np.isfinite(x)]
                    if len(gv) < 5:
                        continue
                    lo, hi = float(np.percentile(gv, 15)), float(np.percentile(gv, 85))
                    if hi <= lo:
                        continue
                    kept = [t for t in base_trades if (lambda v: np.isfinite(v) and lo <= v <= hi)(arr[m][idx_of[t["buy_dt"]]])]
                    tr_k = [t for t in kept if t["buy_dt"].date() < split]
                    ho_k = [t for t in kept if t["buy_dt"].date() >= split]
                    if len(tr_k) < 15 or len(ho_k) < 15:
                        continue
                    sel = len(kept) / tot
                    if sel > 0.005:                      # eis: selectiever dan 0,5% v.d. ticks
                        continue
                    score = float(np.mean([t["pl"] for t in tr_k]))   # selecteer op TRAIN
                    if best is None or score > best["score"]:
                        best = dict(ind=ind, lb=lb, m=m, lo=lo, hi=hi, score=score)
        if not best:
            print("     geen verfijning haalt selectiviteit<0,5% met genoeg trades."); continue

        # EXACTE eval van de winnaar: filter survivors → dedup → holdout
        ind, lb, m, lo, hi = best["ind"], best["lb"], best["m"], best["lo"], best["hi"]
        ref_surv = [t for t in base_surv if (lambda v: v is not None and lo <= v <= hi)(shape(A, ind, lb, m, t))]
        rtrades, art = faithful_trades(eng, A, ref_surv)
        ho = [t for t in rtrades if t["buy_dt"].date() >= split]
        st = trade_stats(eng, ho, with_gap=False)
        edge = st["mean"] - bst["mean"]
        tag = "  <== HOLDOUT-edge vs baseline" if edge > 0.05 and st["n"] >= 20 else ""
        print(f"     beste verfijning: {ind}|L{lb}|{m} ∈ [{lo:.3f},{hi:.3f}]")
        print(f"     HOLDOUT: {st['n']} trades ({100*st['n']/tot:.3f}% v.d. ticks) | Σ {st['sigma']:+.1f}% | gem {st['mean']:+.3f}%/trade (baseline {bst['mean']:+.3f}){tag}")
        print(f"     verdeling: {fmt_cls(st['cls'], st['n'])}")


def SH_at(SH, ind, lb, m, T, A):
    s = A.series[ind]; k = bisect.bisect_right(s["dt"], T); w = s["v"][max(0, k - lb):k][::-1]
    return window_metrics(w).get(m) if len(w) >= 2 else None


if __name__ == "__main__":
    K = int(sys.argv[1]) if len(sys.argv) > 1 else 3
    discover(2525, "DOGEAI", K=K)
    discover(244, "NOS", K=K)
