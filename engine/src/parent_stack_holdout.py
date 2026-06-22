#!/usr/bin/env python3
"""
READ-ONLY: per regime GREEDY 2-3 vorm-subregels stapelen (platte AND, zoals 20-23), eerlijk gevalideerd.
Selectie op TRAIN: voeg steeds de subregel toe die de trades het meest INDIKT terwijl (a) de train-recall
op de groepjes ≥50% blijft en (b) de gem profit_loss niet onder de baseline zakt. Stop bij 3 subregels of
selectiviteit <0,08%. Rapporteer de gestapelde regel op de HOLDOUT (out-of-sample). Alleen harde
profit_loss; geen best_upside.
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
from parent_eval import faithful_trades, trade_stats, fmt_cls, shape
from parent_refine_holdout import shape_arrays, REF_INDS, REF_LB, REF_METRICS
from sell_engine import SellEngine


def discover(symbol, name, K=3, seed=0):
    random.seed(1); np.random.seed(seed)
    A = AsOf(symbol); eng = SellEngine(symbol)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    vecs = [[float(np.mean([A.val(o, t) for t in g])) for o in OSC] for g in groups]
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(StandardScaler().fit_transform(np.array(vecs))).labels_
    cols = asof_arrays(A); tot = len(A.vdt)
    idx_of = {T: i for i, T in enumerate(A.vdt)}
    SH = shape_arrays(A)

    bsamp = [A.vdt[i] for i in random.sample(range(tot), min(8000, tot)) if A.vdt[i].date() >= split]
    bt, _ = faithful_trades(eng, A, bsamp); base_mean = trade_stats(eng, bt, with_gap=False)["mean"]
    print(f"\n################ {name} ({symbol}) — holdout vanaf {split} | baseline gem {base_mean:+.3f}%/trade ################")

    for cl in range(K):
        members = [i for i in range(len(groups)) if lab[i] == cl]
        tr = [i for i in members if groups[i][0].date() < split]
        if len(tr) < 4:
            continue
        bands = {o: (min(v), max(v)) for o in OSC
                 for v in [[A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]]}
        base_surv = project_and(cols, A.vdt, bands, k=3)
        base_trades, _ = faithful_trades(eng, A, base_surv)
        ctr = np.mean([vecs[i] for i in members], axis=0)
        print(f"\n  == regime {cl}: center obv={ctr[0]:.0f} vzo={ctr[1]:.0f} mfi={ctr[2]:.0f} phobos={ctr[3]:.0f} | basis {len(base_trades)} trades ({100*len(base_trades)/tot:.2f}%)")
        if len(base_trades) < 40:
            print("     te weinig basis-trades."); continue

        # train-groep-windows (voor recall) + train-groep-ticks (voor de banden)
        tr_windows = [(groups[i][0] - dt.timedelta(minutes=2), groups[i][-1] + dt.timedelta(minutes=2)) for i in tr]
        tr_ticks = [t for i in tr for t in groups[i]]

        def train_recall(trades):
            ds = sorted(t["buy_dt"] for t in trades if t["buy_dt"].date() < split)
            hit = 0
            for w0, w1 in tr_windows:
                j = bisect.bisect_left(ds, w0)
                if j < len(ds) and ds[j] <= w1:
                    hit += 1
            return hit

        # kandidaat-banden vooraf (uit train-groep-ticks)
        cand = []
        for ind in REF_INDS:
            for lb in REF_LB:
                for m in REF_METRICS:
                    gv = [shape(A, ind, lb, m, t) for t in tr_ticks]
                    gv = [x for x in gv if x is not None and np.isfinite(x)]
                    if len(gv) < 5:
                        continue
                    lo, hi = float(np.percentile(gv, 20)), float(np.percentile(gv, 80))
                    if hi > lo:
                        cand.append((ind, lb, m, lo, hi))

        chosen, mask = [], np.ones(len(base_trades), dtype=bool)
        bt_idx = np.array([idx_of[t["buy_dt"]] for t in base_trades])
        bt_tr = np.array([t["buy_dt"].date() < split for t in base_trades])
        tr_groups = len(tr)
        for step in range(3):
            cur = [base_trades[i] for i in range(len(base_trades)) if mask[i]]
            cur_n = len(cur)
            best = None
            for (ind, lb, m, lo, hi) in cand:
                arr = SH[(ind, lb)][m]
                vals = arr[bt_idx]
                cm = mask & np.isfinite(vals) & (vals >= lo) & (vals <= hi)
                kept = [base_trades[i] for i in range(len(base_trades)) if cm[i]]
                if len(kept) >= cur_n * 0.9 or len(kept) < 40:        # moet echt indikken
                    continue
                ho_n = sum(1 for t in kept if t["buy_dt"].date() >= split)
                if ho_n < 20:
                    continue
                if train_recall(kept) < 0.5 * tr_groups:
                    continue
                tr_pl = [t["pl"] for t in kept if t["buy_dt"].date() < split]
                if not tr_pl or float(np.mean(tr_pl)) < base_mean:
                    continue
                # kies: meest indikkend (laagste trade-count)
                if best is None or len(kept) < best[1]:
                    best = ((ind, lb, m, lo, hi), len(kept), cm)
            if best is None:
                break
            (ind, lb, m, lo, hi), _, cm = best
            chosen.append((ind, lb, m, lo, hi)); mask = cm
            sel = mask.sum() / tot
            if sel < 0.0008:
                break

        if not chosen:
            print("     geen stapeling die indikt zonder de goede groepjes/edge te verliezen."); continue

        # EXACTE holdout-eval: filter survivors op ALLE subregels → dedup
        def passes(t):
            for (ind, lb, m, lo, hi) in chosen:
                v = shape(A, ind, lb, m, t)
                if v is None or not (lo <= v <= hi):
                    return False
            return True
        ref_surv = [t for t in base_surv if passes(t)]
        rtrades, _ = faithful_trades(eng, A, ref_surv)
        ho = [t for t in rtrades if t["buy_dt"].date() >= split]
        st = trade_stats(eng, ho, with_gap=False)
        edge = st["mean"] - base_mean
        tag = "  <== HOLDOUT-edge" if edge > 0.05 and st["n"] >= 20 else ""
        print(f"     gestapeld ({len(chosen)} subregels):")
        for (ind, lb, m, lo, hi) in chosen:
            print(f"        {ind}|L{lb}|{m} ∈ [{lo:.3f}, {hi:.3f}]")
        print(f"     HOLDOUT: {st['n']} trades ({100*st['n']/tot:.3f}% v.d. ticks) | Σ {st['sigma']:+.1f}% | gem {st['mean']:+.3f}%/trade (baseline {base_mean:+.3f}){tag}")
        print(f"     verdeling: {fmt_cls(st['cls'], st['n'])}")


if __name__ == "__main__":
    K = int(sys.argv[1]) if len(sys.argv) > 1 else 3
    discover(2525, "DOGEAI", K=K)
    discover(244, "NOS", K=K)
