#!/usr/bin/env python3
"""
READ-ONLY discovery met ECHTE tijd-holdout op de uitgebreide labels. Per munt:
  - groepeer yes-marks in rises, split de dagen op de mediaan (vroege helft = TRAIN, late = HOLDOUT);
  - cluster de groepjes in K regimes (KMeans op de 4-oscillator-vector);
  - per regime: osc AND-band uit de TRAIN-groepjes, projecteer met k=3 parent-cascade, dedup via de
    ECHTE sell-engine, en evalueer ALLEEN de HOLDOUT-trades (buy-datum >= split);
  - alles op harde gerealiseerde profit_loss (geen best_upside). Vergelijk met de muntbaseline op
    dezelfde holdout-dagen en met de 20-23-richtlijn.
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
from parent_eval import faithful_trades, trade_stats, fmt_cls
from sell_engine import SellEngine

BAR = {2525: "DOGEAI 20-23: 0,01-0,08% v.d. ticks, ~+2%/trade, slecht ~28-45%",
       244: "NOS 20-23: 0,02-0,06% v.d. ticks, ~+1,9%/trade, slecht ~9-40%"}


def discover(symbol, name, K=3, seed=0):
    random.seed(1); np.random.seed(seed)
    A = AsOf(symbol); eng = SellEngine(symbol)
    groups, _ = rises(symbol)
    groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups})
    split = days[len(days) // 2]
    vecs = [[float(np.mean([A.val(o, t) for t in g])) for o in OSC] for g in groups]
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(StandardScaler().fit_transform(np.array(vecs))).labels_
    cols = asof_arrays(A); tot = len(A.vdt)

    # baseline op HOLDOUT-dagen
    bsamp = [A.vdt[i] for i in random.sample(range(tot), min(8000, tot)) if A.vdt[i].date() >= split]
    bt, _ = faithful_trades(eng, A, bsamp)
    bst = trade_stats(eng, bt, with_gap=False)

    print(f"\n################ {name} ({symbol}) — {len(groups)} groepjes, holdout vanaf {split} ################")
    print(f"  richtlijn: {BAR[symbol]}")
    print(f"  HOLDOUT-baseline (willekeurig): {bst['n']} trades | gem {bst['mean']:+.3f}%/trade | {fmt_cls(bst['cls'], bst['n'])}")

    test_groups = [g for i, g in enumerate(groups) if g[0].date() >= split]
    for cl in range(K):
        idx = [i for i in range(len(groups)) if lab[i] == cl]
        tr = [i for i in idx if groups[i][0].date() < split]
        te = [i for i in idx if groups[i][0].date() >= split]
        if len(tr) < 3:
            continue
        bands = {o: (min(v), max(v)) for o in OSC
                 for v in [[A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]]}
        surv = project_and(cols, A.vdt, bands, k=3)
        trades, art = faithful_trades(eng, A, surv)
        test_trades = [t for t in trades if t["buy_dt"].date() >= split]
        st = trade_stats(eng, test_trades, with_gap=False)
        # recall op holdout-groepjes
        sset = sorted(t for t in surv if t.date() >= split); hit = 0
        for g in [groups[i] for i in te]:
            j = bisect.bisect_left(sset, g[0] - dt.timedelta(minutes=2))
            if j < len(sset) and sset[j] <= g[-1] + dt.timedelta(minutes=2):
                hit += 1
        ctr = np.mean([vecs[i] for i in idx], axis=0)
        edge = st["mean"] - bst["mean"]
        tag = "  <== HOLDOUT-edge" if edge > 0.05 and st["n"] >= 20 else ""
        print(f"\n  -- regime {cl}: {len(tr)} train / {len(te)} holdout groepjes | center obv={ctr[0]:.0f} vzo={ctr[1]:.0f} mfi={ctr[2]:.0f} phobos={ctr[3]:.0f}")
        print(f"     band: " + " ".join(f"{o.split('-')[0]}[{a:.0f},{b:.0f}]" for o, (a, b) in bands.items()))
        print(f"     HOLDOUT: raakt {hit}/{len(te)} groepjes | {st['n']} trades ({100*st['n']/tot:.2f}%) | Σ {st['sigma']:+.1f}% | gem {st['mean']:+.3f}%/trade (baseline {bst['mean']:+.3f}){tag}")
        print(f"     verdeling: {fmt_cls(st['cls'], st['n'])}")


if __name__ == "__main__":
    K = int(sys.argv[1]) if len(sys.argv) > 1 else 3
    discover(2525, "DOGEAI", K=K)
    discover(244, "NOS", K=K)
