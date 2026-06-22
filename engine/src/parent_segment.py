#!/usr/bin/env python3
"""
SEGMENTATIE van de promising groepen (rule-discovery §10, gecorrigeerde insteek). GÉÉN achtergrond:
segmenteer de groepen zélf. Per (indicator × lookback 1-20 × ~30 calcs) ÉN prijs-features (stijging%/
dip% over lookback) zoek het dichtste venster dat ~FRAC van de groepen BINDT, en meet hoe TIGHT dat is
(een echt sub-cluster vs uniform). Rapporteer: hoeveel segmentaties binden 10-25% strak, op welke
berekening, en welke op BEIDE munten. Daarna gaan we per segment verfijnen.
"""
import bisect
import sys

import numpy as np

from calc import window_metrics, WINDOW_METRIC_KEYS, calc_percentage
from parent_crossgroup import AsOf
from parent_fullperiod import rises

INDS = ("obv-x-value", "vzo", "mfi", "phobos", "volumeud")
LBS = tuple(range(1, 21))
MKEYS = tuple(WINDOW_METRIC_KEYS)
PRICEF = ("p_change", "p_maxrise", "p_mindip", "p_range")
FEATS = [(ind, lb, m) for ind in INDS for lb in LBS for m in MKEYS] + \
        [("price", lb, pf) for lb in LBS for pf in PRICEF]
FRAC = 0.20


def feat_row(A, T):
    row = {}
    for ind in INDS:
        s = A.series[ind]; k = bisect.bisect_right(s["dt"], T)
        for lb in LBS:
            w = s["v"][max(0, k - lb):k][::-1]
            if len(w) >= 2:
                mm = window_metrics(w)
                for m in MKEYS:
                    v = mm.get(m)
                    if v is not None and np.isfinite(v):
                        row[(ind, lb, m)] = v
    i = bisect.bisect_right(A.vdt, T)
    for lb in LBS:
        pr = [p for p in A.vpx[max(0, i - lb):i] if p is not None]   # oudste..nieuwste
        if len(pr) >= 2:
            old = pr[0]
            if old:
                row[("price", lb, "p_change")] = calc_percentage(old, pr[-1])
                row[("price", lb, "p_maxrise")] = 100 * (max(pr) - old) / old
                row[("price", lb, "p_mindip")] = 100 * (min(pr) - old) / old
                row[("price", lb, "p_range")] = 100 * (max(pr) - min(pr)) / old
    return row


def natural_split(vals):
    """grootste GAP in de groep-verdeling die 10-40% afsplitst → een echte segmentatie (threshold)."""
    v = np.sort(np.array(vals)); n = len(v)
    if np.unique(np.round(v, 8)).size < 12:               # degeneratie (count-metric op 0) overslaan
        return None
    spread = v[-1] - v[0]
    if spread <= 0:
        return None
    gaps = np.diff(v)
    best = None
    for i in range(1, n):
        flo = i / n
        if not (0.10 <= flo <= 0.40 or 0.10 <= 1 - flo <= 0.40):
            continue
        g = gaps[i - 1] / spread
        if best is None or g > best[0]:
            best = (g, float((v[i - 1] + v[i]) / 2), flo, float(v[i - 1]), float(v[i]))
    if best is None or best[0] < 0.08:                    # gap < 8% v.d. spreiding = geen echte split
        return None
    g, thr, flo, a, b = best
    minority = min(flo, 1 - flo)
    side = "<" if flo <= 0.5 else ">"
    return dict(gap=g, thr=thr, minority=minority, side=side)


def seg_for(A):
    groups, _ = rises(A.symbol)
    rows = [feat_row(A, g[2]) for g in groups]
    out = {}
    for f in FEATS:
        vals = [r[f] for r in rows if f in r]
        if len(vals) < 0.6 * len(rows):
            continue
        s = natural_split(vals)
        if s:
            out[f] = s
    return out


def main():
    sd, sn = seg_for(AsOf(2525)), seg_for(AsOf(244))
    for name, s in (("DOGEAI", sd), ("NOS", sn)):
        strong = sorted(((v["gap"], f, v) for f, v in s.items()), key=lambda x: -x[0])
        print(f"\n=== {name}: {len(strong)} natuurlijke segmentaties (gap splitst 10-40% af) — top 12 ===")
        for g, f, v in strong[:12]:
            ind, lb, m = f
            print(f"  {ind}|L{lb}|{m:24s} : segment = waarde {v['side']} {v['thr']:.2f}  ({100*v['minority']:.0f}% groepen, gap={g:.2f})")
    shared = sorted(((min(sd[f]["gap"], sn[f]["gap"]), f) for f in (set(sd) & set(sn))), key=lambda x: -x[0])
    print(f"\n=== CROSS-COIN: {len(shared)} features met een natuurlijke split op BEIDE munten — top 15 ===")
    for g, f in shared[:15]:
        ind, lb, m = f
        print(f"  {ind}|L{lb}|{m:22s} | DOGEAI {sd[f]['side']}{sd[f]['thr']:.2f} ({100*sd[f]['minority']:.0f}%) | NOS {sn[f]['side']}{sn[f]['thr']:.2f} ({100*sn[f]['minority']:.0f}%)")


if __name__ == "__main__":
    main()
