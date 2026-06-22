#!/usr/bin/env python3
"""
READ-ONLY: stap 5 = VERFIJNEN met holdout. Neem per regime de oscillator-band uit de VROEGE groepjes,
voeg 1 vorm/lookback-subregel toe (skewness/range/volatility/std/consecutive/diff), afgeleid op de
VROEGE groepjes, en meet of de survivors in de LATE (holdout) periode de muntbaseline verslaan
(hogere up%, dip niet slechter). Zo niet -> verfijnen overfit en redt het regime niet.
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
from parent_regimes import asof_arrays, project_and, fwd_stats
from parent_fullperiod import rises

REF_METRICS = ("skewness", "range_percentage", "volatility", "standard_deviation",
               "consecutive_increases", "diff_previous_value", "reversal_count")
REF_LB = (5, 10, 20)
REF_INDS = ("obv-x-value", "vzo", "mfi", "phobos", "volumeud")


def shape_all(A, ind, L, T):
    """alle window_metrics in EEN call (cache-vriendelijk)."""
    s = A.series[ind]
    i = bisect.bisect_right(s["dt"], T)
    vals = s["v"][max(0, i - L):i][::-1]
    if len(vals) < 2:
        return {}
    return window_metrics(vals)


def main(symbol, name, K=3, seed=0):
    random.seed(seed); np.random.seed(seed)
    A = AsOf(symbol)
    groups, bad = rises(symbol)
    vecs = []
    for g in groups:
        vecs.append([float(np.mean([A.val(ind, t) for t in g])) for ind in OSC])
    Xs = StandardScaler().fit_transform(np.array(vecs))
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(Xs).labels_

    cols = asof_arrays(A); vdt = A.vdt
    base = fwd_stats(A, [vdt[i] for i in random.sample(range(len(vdt)), min(4000, len(vdt)))])
    print(f"\n############ {name} ({symbol}) — VERFIJNEN met holdout (K={K}) ############")
    print(f"baseline: up={base['up']:.2f}% dip={base['dip']:.2f}% %dip<-5%={base['deep5']:.0f}")

    for cl in range(K):
        idx = [i for i in range(len(groups)) if lab[i] == cl]
        dates = sorted({groups[i][0].date() for i in idx})
        if len(idx) < 4 or len(dates) < 2:
            continue
        # train = vroege helft van de dagen, holdout = late helft
        split = dates[len(dates) // 2]
        tr = [i for i in idx if groups[i][0].date() < split]
        ho = [i for i in idx if groups[i][0].date() >= split]
        if not tr or not ho:
            continue
        # oscillator-band uit TRAIN
        bands = {}
        for ind in OSC:
            allv = [A.val(ind, t) for i in tr for t in groups[i] if A.val(ind, t) is not None]
            bands[ind] = (min(allv), max(allv))
        surv = project_and(cols, vdt, bands, k=3)
        sd = [t for t in surv if t.date() >= split]   # holdout-periode survivors
        base_ho = fwd_stats(A, sd)
        print(f"\n-- regime {cl}: {len(idx)} grp ({len(tr)} train / {len(ho)} holdout), split {split}")
        if not base_ho:
            print("   geen holdout-survivors"); continue
        print(f"   BASIS (alleen oscillator-band): holdout-survivors={base_ho['n']} up={base_ho['up']:.2f}% dip={base_ho['dip']:.2f}% %dip<-5%={base_ho['deep5']:.0f}", flush=True)
        # sample holdout-survivors voor de refinement-evaluatie
        sd_eval = sd if len(sd) <= 2500 else random.sample(sd, 2500)
        results = []
        for ind in REF_INDS:
            for L in REF_LB:
                # cache: alle metrics op train-groep-ticks en op holdout-survivors, EEN window_metrics-call per tick
                gm = [shape_all(A, ind, L, t) for i in tr for t in groups[i]]
                sm = [(t, shape_all(A, ind, L, t)) for t in sd_eval]
                for metric in REF_METRICS:
                    gv = [d[metric] for d in gm if metric in d]
                    if len(gv) < 3:
                        continue
                    lo, hi = float(np.percentile(gv, 10)), float(np.percentile(gv, 90))
                    if hi <= lo:
                        continue
                    keep = [t for (t, d) in sm if metric in d and lo <= d[metric] <= hi]
                    if len(keep) < 5:
                        continue
                    st = fwd_stats(A, keep)
                    if st:
                        results.append((f"{ind.split('-')[0]}|L{L}|{metric}", st['up'], st['dip'], st['deep5'], st['n']))
        # beste refinements op holdout-up%
        results.sort(key=lambda r: -r[1])
        print(f"   beste verfijningen op HOLDOUT (up% vs baseline {base['up']:.2f}%):")
        for nm, up, dip, d5, n in results[:5]:
            tag = "  <== verslaat baseline" if up > base['up'] + 0.3 and dip >= base['dip'] else ""
            print(f"     {nm:34s} hold-surv={n:4d} up={up:.2f}% dip={dip:.2f}% %dip<-5%={d5:.0f}{tag}")
        if not results or results[0][1] <= base['up'] + 0.3:
            print("     >>> GEEN verfijning verslaat de baseline op holdout.")


if __name__ == "__main__":
    if len(sys.argv) > 1:
        main(int(sys.argv[1]), sys.argv[1])
    else:
        main(2525, "DOGEAI"); main(244, "NOS")
