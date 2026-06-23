#!/usr/bin/env python3
"""
shapelet_refine.py — kan een VOLUME-VORM de winnaars van de verliezers scheiden BINNEN de 30-34 trades?

Daans doel: de ontdekte regels (30-34) zijn loser-zwaar (~48-62% slecht). Verbeter ze met een precisie-
filter: kijk naar de GOEDE vs SLECHTE 30-34 trades en zoek een volume-vorm (shapelet op de relvol-reeks
vóór de instap) die winst van verlies onderscheidt. Lukt dat, dan kun je de filter aan 30-34 hangen om de
verliezers te weren — precies "verfijnen om minder slechte trades over te houden".

Klasse: winst (pl>=0) vs verlies (pl<0) van de live executed 30-34 trades. Reeks = relvol (volumeud/
min_volume) over de L ticks vóór de instap. DISCIPLINE: shapelet op de VROEGE trades (train), evalueren op
de LATE trades (apart-gehouden testperiode) + toeval-toets. ALLEEN-LEZEN.

Draaien (vanuit engine/src):  NUMBA_DISABLE_JIT=1 ../.venv/bin/python -m discovery.shapelet_refine
"""
import bisect
import random as _r

import numpy as np

from db import brain
from parent_crossgroup import AsOf
from discovery.data import min_volume
from discovery.shapelet_probe import znorm, sdist, best_split, L, WIDTHS, N_CAND, N_PERM, SEED


def trades(sym):
    with brain().cursor() as c:
        c.execute("SELECT datetime, profit_loss FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND rule IN (30,31,32,33,34) AND is_executed=1 AND profit_loss IS NOT NULL "
                  "ORDER BY datetime", (sym,))
        return [(r["datetime"], float(r["profit_loss"])) for r in c.fetchall()]


def run(sym, nm):
    _r.seed(SEED)
    A = AsOf(sym)
    vb = min_volume(sym)
    s = A.series["volumeud"]
    vdt, vv = s["dt"], np.array(s["v"], float) / vb

    rows = []   # (vorm vóór instap, win-label) op tijd
    for (t, pl) in trades(sym):
        k = bisect.bisect_right(vdt, t)
        if k < L:
            continue
        rows.append((t, vv[k - L:k], 1 if pl >= 0 else 0))
    rows.sort(key=lambda r: r[0])

    mid = len(rows) // 2
    tr, te = rows[:mid], rows[mid:]
    Xtr = [w for (_t, w, _y) in tr]; ytr = np.array([y for (_t, _w, y) in tr])
    Xte = [w for (_t, w, _y) in te]; yte = np.array([y for (_t, _w, y) in te])
    if ytr.sum() == 0 or ytr.sum() == len(ytr):
        print(f"{nm}: te weinig klasse-variatie"); return

    # kandidaat-shapelets uit de WINNENDE train-trades (de vorm die winst markeert)
    cands = []
    for w in WIDTHS:
        for (_t, series, y) in tr:
            if y == 1:
                for i in range(0, len(series) - w + 1, 2):
                    cands.append(znorm(series[i:i + w]))
    _r.shuffle(cands); cands = cands[:N_CAND]

    best = (-1, None, None)
    for sh in cands:
        d = np.array([sdist(sh, x) for x in Xtr])
        ig, thr, _acc = best_split(d, ytr)
        if ig > best[0]:
            best = (ig, sh, thr)
    ig, sh, thr = best

    # op de testperiode: "dichtbij shapelet" = voorspel winst. Meet of dat de slecht% verlaagt.
    dte = np.array([sdist(sh, x) for x in Xte])
    keep = dte <= thr                         # de trades die de filter doorlaat (dichtbij de winst-vorm)
    base_bad = float((yte == 0).mean())       # slecht% van ALLE test-trades (huidige 30-34)
    if keep.sum() == 0:
        print(f"{nm}: filter laat niets door op test"); return
    kept_bad = float((yte[keep] == 0).mean())  # slecht% NA de filter
    drop = (~keep)
    # toeval-toets: schud welke trades 'doorgelaten' worden, hoe vaak is de slecht% even laag?
    rng = np.random.default_rng(SEED + 2)
    m = int(keep.sum())
    perm = np.empty(N_PERM)
    for b in range(N_PERM):
        idx = rng.choice(len(yte), m, replace=False)
        perm[b] = (yte[idx] == 0).mean()
    p = float((perm <= kept_bad).mean())

    print(f"\n=== {nm}: shapelet-filter op {len(rows)} live 30-34 trades (train {len(tr)} / test {len(te)}) ===")
    print(f"  beste winst-vorm: lengte {len(sh)}, train info-gain {ig:.3f}")
    print(f"  TEST: filter laat {m}/{len(te)} trades door | slecht% {100*base_bad:.0f}% -> {100*kept_bad:.0f}% "
          f"na filter | toeval-toets p={p:.3f}")
    behoud_win = int((yte[keep] == 1).sum()); tot_win = int((yte == 1).sum())
    print(f"       winnaars behouden: {behoud_win}/{tot_win} ({100*behoud_win//max(tot_win,1)}%)")
    ok = p < 0.05 and kept_bad < base_bad - 0.03
    print(f"  >>> {'SIGNAAL: vorm-filter verlaagt slecht% significant' if ok else 'geen robuust filter-signaal'}")


def main():
    for sym, nm in [(2525, "DOGEAI"), (244, "NOS")]:
        run(sym, nm)


if __name__ == "__main__":
    main()
