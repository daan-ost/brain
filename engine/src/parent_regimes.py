#!/usr/bin/env python3
"""
READ-ONLY: regime-clustering van promising groepjes -> MEERDERE kandidaat-rules (zoals 20-23).
Cluster de groepjes op hun oscillator-vector [obv,vzo,mfi,phobos] (triple-center, gestandaardiseerd),
en projecteer per cluster de AND-band over de complete periode (3-opvolgende-ticks cascade).
Scheidsrechter = forward-afloop van survivors vs muntbaseline (alleen yes-marks zijn grondwaarheid;
ongelabelde survivors krijgen geen oordeel, alleen hun afloop).
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


def asof_arrays(A):
    """per volumeud-tick de as-of waarde van elke oscillator (vectoriseerbaar voor projectie)."""
    vdt = A.vdt
    cols = {}
    for ind in OSC:
        s = A.series[ind]; sd = s["dt"]; sv = s["v"]
        arr = np.full(len(vdt), np.nan)
        for n, T in enumerate(vdt):
            i = bisect.bisect_right(sd, T)
            if i > 0:
                arr[n] = sv[i - 1]
        cols[ind] = arr
    return cols


def project_and(cols, vdt, bands, k=3):
    """3 opvolgende volumeud-ticks waar ALLE oscillatoren in hun band zitten -> survivors (k-de tick)."""
    inb = np.ones(len(vdt), dtype=bool)
    for ind, (lo, hi) in bands.items():
        inb &= (cols[ind] >= lo) & (cols[ind] <= hi)
    surv = []
    run = 0
    for i in range(len(vdt)):
        run = run + 1 if inb[i] else 0
        if run >= k:
            surv.append(vdt[i])
    return surv


def fwd_stats(A, ticks):
    ups, dips = [], []
    for t in ticks:
        u, d = A.fwd(t)
        if u is not None:
            ups.append(u); dips.append(d)
    if not ups:
        return None
    return dict(n=len(ups), up=float(np.median(ups)), dip=float(np.median(dips)),
               deep5=100 * float(np.mean([d < -5 for d in dips])),
               deep3=100 * float(np.mean([d < -3 for d in dips])),
               win3=100 * float(np.mean([u >= 3 for u in ups])))


def main(symbol, name, K=3, seed=0):
    random.seed(seed); np.random.seed(seed)
    A = AsOf(symbol)
    groups, bad = rises(symbol)
    # per-groep center-vector
    vecs, gcenters = [], []
    for g in groups:
        row = []
        for ind in OSC:
            vals = [A.val(ind, t) for t in g]
            row.append(float(np.mean([v for v in vals if v is not None])))
        vecs.append(row)
    X = np.array(vecs)
    Xs = StandardScaler().fit_transform(X)
    km = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(Xs)
    lab = km.labels_

    cols = asof_arrays(A)
    vdt = A.vdt
    base = fwd_stats(A, [vdt[i] for i in random.sample(range(len(vdt)), min(4000, len(vdt)))])
    print(f"\n############ {name} ({symbol}) — {len(groups)} groepjes, K={K} regimes ############")
    print(f"baseline (random ticks): up={base['up']:.2f}% dip={base['dip']:.2f}% %dip<-5%={base['deep5']:.0f} %dip<-3%={base['deep3']:.0f} %up>=3%={base['win3']:.0f}")
    for cl in range(K):
        idx = [i for i in range(len(groups)) if lab[i] == cl]
        if not idx:
            continue
        # AND-band = hull van de triple-ticks van de cluster-leden
        bands = {}
        for ind in OSC:
            allv = []
            for i in idx:
                allv += [A.val(ind, t) for t in groups[i] if A.val(ind, t) is not None]
            bands[ind] = (min(allv), max(allv))
        surv = project_and(cols, vdt, bands, k=3)
        st = fwd_stats(A, surv)
        ctr = X[idx].mean(axis=0)
        print(f"\n-- regime {cl}: {len(idx)} groepjes | center obv={ctr[0]:.0f} vzo={ctr[1]:.0f} mfi={ctr[2]:.0f} phobos={ctr[3]:.0f}")
        print(f"   dagen: {sorted({groups[i][0].date().isoformat() for i in idx})}")
        print(f"   AND-band: " + "  ".join(f"{ind.split('-')[0]}[{lo:.0f},{hi:.0f}]" for ind, (lo, hi) in bands.items()))
        if st:
            tag = ""
            if st['up'] > base['up'] + 0.3 and st['deep5'] <= base['deep5']:
                tag = "  <== beter dan baseline"
            print(f"   survivors: {st['n']} ({100*st['n']/len(vdt):.1f}%) | up={st['up']:.2f}% dip={st['dip']:.2f}% %dip<-5%={st['deep5']:.0f} %dip<-3%={st['deep3']:.0f} %up>=3%={st['win3']:.0f}{tag}")
        else:
            print(f"   survivors: 0")


if __name__ == "__main__":
    args = sys.argv[1:]
    if args:
        main(int(args[0]), args[0], K=int(args[1]) if len(args) > 1 else 3)
    else:
        for K in (2, 3):
            main(2525, "DOGEAI", K=K); main(244, "NOS", K=K)
