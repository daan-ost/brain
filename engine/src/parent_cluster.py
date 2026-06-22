#!/usr/bin/env python3
"""
READ-ONLY: bestaat er EEN berekening (van alle 30 metrics x lookbacks x 5 indicatoren) waarbij de
meeste promising groepjes in EEN smalle band landen? Dat is de echte "gemene deler over groepjes".

Per feature: per-groep triple-center; normaliseer met de full-period spreiding; vind de densте
cluster (max #groepjes binnen band = 0.25 x (p90-p10) achtergrond). Rangschik op #groepjes captured.
Een feature is pas een gemene deler als het >> dan ceil(0.6*N) groepjes in een NAUWE band vangt.
"""
import bisect
import datetime as dt
import random
import sys

import numpy as np

from parent_discover import Features, INDICATORS, LOOKBACKS
from parent_fullperiod import rises


def densest(centers, width):
    """max aantal centers binnen een venster van breedte `width`; geef (count, lo, hi)."""
    cs = sorted(centers)
    best = (0, None, None)
    for i in range(len(cs)):
        j = i
        while j + 1 < len(cs) and cs[j + 1] - cs[i] <= width:
            j += 1
        if j - i + 1 > best[0]:
            best = (j - i + 1, cs[i], cs[j])
    return best


def main(symbol, name, seed=0):
    random.seed(seed)
    F = Features(symbol)
    groups, bad = rises(symbol)
    N = len(groups)
    # per-groep triple-center per feature
    group_vecs = [[F.at(t) for t in g] for g in groups]
    # achtergrond-sample voor de schaal (p10-p90)
    s = F.series["volumeud"]
    bg_ticks = [s["dt"][i] for i in random.sample(range(len(s["dt"])), min(2500, len(s["dt"])))]
    bg_vecs = [F.at(t) for t in bg_ticks]

    keys = set(group_vecs[0][0])
    for gv in group_vecs:
        for v in gv:
            keys &= set(v)

    rows = []
    for k in keys:
        centers = []
        ok = True
        for gv in group_vecs:
            vals = [v[k] for v in gv if k in v]
            if len(vals) < len(gv):
                ok = False; break
            centers.append(float(np.mean(vals)))
        if not ok:
            continue
        bg = [v[k] for v in bg_vecs if k in v]
        if len(bg) < 50:
            continue
        p10, p90 = np.percentile(bg, 10), np.percentile(bg, 90)
        rng = p90 - p10
        if rng <= 1e-9:
            continue
        width = 0.25 * rng
        cnt, lo, hi = densest(centers, width)
        rows.append((k, cnt, lo, hi, rng, centers))

    rows.sort(key=lambda r: -r[1])
    need = int(np.ceil(0.6 * N))
    print(f"\n############ {name} ({symbol}) — {N} groepjes; gemene-deler-drempel = {need}/{N} in smalle band ############")
    print(f"{'feature':40s} {'#grp':>4s} {'band (25% bg-range)':>26s}")
    for k, cnt, lo, hi, rng, centers in rows[:20]:
        flag = "  <== KEEPER" if cnt >= need else ""
        print(f"{k:40s} {cnt:3d}/{N} [{lo:9.3f},{hi:9.3f}]{flag}")
    top = rows[0]
    print(f"\n  beste feature vangt {top[1]}/{N} groepjes in een band van 25% van de achtergrond-spreiding.")
    if top[1] < need:
        print(f"  >>> GEEN gemene deler: zelfs de beste berekening vangt < {need}/{N} groepjes. "
              f"De promising-instappen delen GEEN smalle band op welke metric dan ook.")


if __name__ == "__main__":
    if len(sys.argv) > 1:
        main(int(sys.argv[1]), sys.argv[1])
    else:
        main(2525, "DOGEAI"); main(244, "NOS")
