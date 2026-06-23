#!/usr/bin/env python3
"""
negvol_check.py — scheidt RECENTE VERKOOPDRUK (negatief volume) winst van verlies? (Daans idee)

min_volume = gemiddeld POSITIEF volumeud; de huidige volume-functie normaliseert daarop. Daans idee:
spiegel dat voor de NEGATIEVE kant — gem_neg = gemiddeld negatief volumeud (coin-eigen basislijn) — en
maak een schaal-invariante maat van de recente verkoopdruk: gem van de laatste N negatieve volumeud-ticks
gedeeld door gem_neg. Intuïtie: koop niet vlak na een abnormaal grote verkoopgolf (relneg >> 1).

Toets (zoals osc_level_check): op de live ontdekte-rule trades (30-34), splits winst (pl>=0) vs verlies
(pl<0); scheidt de maat ze? Zo niet → niet bouwen. ALLEEN-LEZEN.

Draaien (vanuit engine/src):  ../.venv/bin/python -m discovery.negvol_check
"""
import bisect

import numpy as np

from db import brain
from parent_crossgroup import AsOf

NWIN = 5    # "afgelopen 5 negatieve volume-ticks" (Daans voorbeeld)


def trades(sym):
    with brain().cursor() as c:
        c.execute("SELECT datetime, profit_loss FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND rule IN (30,31,32,33,34) AND is_executed=1 AND profit_loss IS NOT NULL "
                  "ORDER BY datetime", (sym,))
        return [(r["datetime"], float(r["profit_loss"])) for r in c.fetchall()]


def run(sym, nm):
    A = AsOf(sym)
    s = A.series.get("volumeud")
    dts, vals = s["dt"], s["v"]
    neg = [v for v in vals if v < 0]
    gem_neg = sum(neg) / len(neg) if neg else -1.0     # coin-eigen basislijn (negatief getal)

    rows = []
    for (t, pl) in trades(sym):
        k = bisect.bisect_right(dts, t)
        w = vals[max(0, k - 40):k]                      # ruim venster, pak de laatste NWIN negatieve eruit
        negs = [v for v in w if v < 0][-NWIN:]
        if not negs:
            continue
        relneg = (sum(negs) / len(negs)) / gem_neg      # >1 = recente verkoopdruk groter dan typisch
        rows.append((relneg, pl))

    if not rows:
        print(f"{nm}: geen trades"); return
    rn = np.array([r[0] for r in rows]); pls = np.array([r[1] for r in rows])
    win, los = rn[pls >= 0], rn[pls < 0]
    print(f"\n=== {nm}: {len(rows)} ontdekte-rule trades | gem_neg basislijn {gem_neg:.0f} ===")
    print(f"  relneg (recente verkoopdruk / typisch):  gem WIN {win.mean():.2f}  vs  gem VERL {los.mean():.2f}")
    # beste enkele drempel: weert verliezers terwijl winnaars blijven?
    best = None
    for thr in np.quantile(rn, np.linspace(0.1, 0.9, 17)):
        for side in ("ge", "le"):
            keep = rn >= thr if side == "ge" else rn <= thr
            nloss = int((pls < 0).sum()); nwin = int((pls >= 0).sum())
            l_weg = nloss - int((pls[keep] < 0).sum()); w_weg = nwin - int((pls[keep] >= 0).sum())
            score = l_weg - w_weg
            if best is None or score > best[0]:
                best = (score, side, thr, l_weg, nloss, w_weg, nwin)
    _s, side, thr, l_weg, nl, w_weg, nw = best
    print(f"  beste cut: relneg {side} {thr:.2f} → {l_weg}/{nl} verl weg ({100*l_weg//nl}%), "
          f"{w_weg}/{nw} win weg ({100*w_weg//nw}%)  [scheidt als verl%% >> win%%]")


def main():
    for sym, nm in [(2525, "DOGEAI"), (244, "NOS")]:
        run(sym, nm)


if __name__ == "__main__":
    main()
