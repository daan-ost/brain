#!/usr/bin/env python3
"""
READ-ONLY beslissende check: verslaan de verfijnde survivors een ZELFDE-DAG baseline?
(Anders is de 'edge' gewoon dat ze op stijgende dagen vuren.) Per (regime, refinement):
survivor up/dip vs de mediane up/dip van ALLE ticks op dezelfde dagen.
"""
import bisect
import random
import sys

import numpy as np
from sklearn.cluster import KMeans
from sklearn.preprocessing import StandardScaler

from calc import window_metrics
from parent_crossgroup import AsOf, OSC
from parent_regimes import asof_arrays, project_and, fwd_stats
from parent_fullperiod import rises


def regime_split(A, K=3, seed=0):
    groups, _ = rises(A.symbol)
    vecs = [[float(np.mean([A.val(ind, t) for t in g])) for ind in OSC] for g in groups]
    Xs = StandardScaler().fit_transform(np.array(vecs))
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(Xs).labels_
    return groups, lab


def shape_band(A, ind, L, metric, ticks):
    vals = []
    for t in ticks:
        s = A.series[ind]; i = bisect.bisect_right(s["dt"], t)
        w = s["v"][max(0, i - L):i][::-1]
        if len(w) >= 2:
            v = window_metrics(w).get(metric)
            if v is not None:
                vals.append(v)
    return (float(np.percentile(vals, 10)), float(np.percentile(vals, 90))) if len(vals) >= 3 else None


def shape_ok(A, ind, L, metric, t, lo, hi):
    s = A.series[ind]; i = bisect.bisect_right(s["dt"], t)
    w = s["v"][max(0, i - L):i][::-1]
    if len(w) < 2:
        return False
    v = window_metrics(w).get(metric)
    return v is not None and lo <= v <= hi


def check(A, name, cl, ind, L, metric, K=3):
    groups, lab = regime_split(A, K)
    idx = [i for i in range(len(groups)) if lab[i] == cl]
    dates = sorted({groups[i][0].date() for i in idx})
    split = dates[len(dates) // 2]
    tr = [i for i in idx if groups[i][0].date() < split]
    bands = {}
    for o in OSC:
        allv = [A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]
        bands[o] = (min(allv), max(allv))
    cols = asof_arrays(A)
    surv = [t for t in project_and(cols, A.vdt, bands, k=3) if t.date() >= split]
    rb = shape_band(A, ind, L, metric, [t for i in tr for t in groups[i]])
    if rb is None:
        print(f"  {name} r{cl} {ind}|L{L}|{metric}: geen band"); return
    lo, hi = rb
    final = [t for t in surv if shape_ok(A, ind, L, metric, t, lo, hi)]
    st = fwd_stats(A, final)
    if not st:
        print(f"  {name} r{cl} {ind}|L{L}|{metric}: <5 survivors"); return
    # zelfde-dag baseline
    days = sorted({t.date() for t in final})
    same = [A.vdt[i] for i in range(len(A.vdt)) if A.vdt[i].date() in set(days)]
    if len(same) > 6000:
        same = random.sample(same, 6000)
    sb = fwd_stats(A, same)
    edge = st['up'] - sb['up']
    tag = "  <== ECHTE intraday-edge" if edge > 0.3 and st['dip'] >= sb['dip'] else "  (geen edge: = de dagen zelf)"
    print(f"  {name} r{cl} {ind}|L{L}|{metric}: surv={st['n']} op {len(days)} dagen")
    print(f"     survivors    up={st['up']:.2f}% dip={st['dip']:.2f}% %dip<-5%={st['deep5']:.0f}")
    print(f"     zelfde-dag   up={sb['up']:.2f}% dip={sb['dip']:.2f}% %dip<-5%={sb['deep5']:.0f}   -> edge up {edge:+.2f}%{tag}")


if __name__ == "__main__":
    random.seed(0); np.random.seed(0)
    DOG = AsOf(2525); NOS = AsOf(244)
    print("=== ZELFDE-DAG baseline check ===")
    check(NOS, "NOS", 2, "volumeud", 5, "standard_deviation")
    check(NOS, "NOS", 2, "phobos", 10, "skewness")
    check(NOS, "NOS", 2, "volumeud", 20, "skewness")
    check(DOG, "DOGEAI", 1, "volumeud", 20, "skewness")
    check(DOG, "DOGEAI", 1, "volumeud", 20, "range_percentage")
