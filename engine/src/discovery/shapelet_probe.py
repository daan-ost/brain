#!/usr/bin/env python3
"""
shapelet_probe.py — vindt een VORM in de (genormaliseerde) volume-reeks die goede koop-momenten markeert.

Daans inzicht: window-metrics (gemiddelde, scheefheid) gooien de VOLGORDE/VORM weg — "volume dipt 2x onder
het gemiddelde en herstelt" is voor een gemiddelde onzichtbaar. Een shapelet = een kort vormpje dat zich
herhaalt vóór goede koop-momenten. Dit is precies wat Daan handmatig deed om volume_3 te vinden, hier
geautomatiseerd: zoek de subsequentie waarvan de afstand tot een reeks goed-van-achtergrond scheidt.

Reeks = relvol (volumeud / min_volume) — Daans "volume als percentage van een getal", coin-agnostisch.
DISCIPLINE: shapelet-zoeken scant een enorme ruimte → toevalstreffers. Daarom: train de shapelet op de
VROEGE helft van de goede momenten, evalueer op de LATE helft (apart-gehouden testperiode) + toeval-toets
(labels schudden). Een shapelet telt alleen als hij OOK op de testperiode scheidt én p<0,05.

ALLEEN-LEZEN. Draaien (vanuit engine/src):  NUMBA_DISABLE_JIT=1 ../.venv/bin/python -m discovery.shapelet_probe
"""
import bisect
import random as _r

import numpy as np

from parent_crossgroup import AsOf
from parent_fullperiod import rises
from discovery.data import min_volume

L = 24            # venster vóór de koop (reekslengte)
WIDTHS = (4, 6, 8)  # shapelet-lengtes
N_CAND = 300      # subsample kandidaat-shapelets (snelheid)
N_BG = 400        # achtergrond-reeksen
N_PERM = 500      # toeval-toets-herhalingen
SEED = 1


def znorm(a):
    a = np.asarray(a, float)
    sd = a.std()
    return (a - a.mean()) / sd if sd > 1e-9 else a - a.mean()


def sdist(shape, series):
    """minimale z-genormaliseerde euclidische afstand van shapelet over de reeks (sliding)."""
    w = len(shape); n = len(series)
    if n < w:
        return np.inf
    best = np.inf
    for i in range(n - w + 1):
        d = np.sum((shape - znorm(series[i:i + w])) ** 2)
        if d < best:
            best = d
    return best


def best_split(dist, y):
    """beste drempel op afstand → scheiding goed(y=1)/achtergrond(y=0). Geef (info_gain, thr, acc)."""
    order = np.argsort(dist)
    ds, ys = dist[order], y[order]
    n = len(y); pos = y.sum(); neg = n - pos

    def ent(p, t):
        if t == 0:
            return 0.0
        a = p / t
        return 0.0 if a in (0, 1) else -(a * np.log2(a) + (1 - a) * np.log2(1 - a))
    base = ent(pos, n)
    best = (-1, None, 0)
    for i in range(1, n):
        if ds[i] == ds[i - 1]:
            continue
        thr = (ds[i] + ds[i - 1]) / 2
        lp = ys[:i].sum(); ln = i - lp
        rp = pos - lp; rn = neg - ln
        ig = base - (i / n) * ent(lp, i) - ((n - i) / n) * ent(rp, n - i)
        # accuratesse van "dichtbij = goed" (kleine afstand → y=1)
        acc = (lp + rn) / n
        if ig > best[0]:
            best = (ig, thr, acc)
    return best


def run(sym, nm):
    rng = np.random.default_rng(SEED); _r.seed(SEED)
    A = AsOf(sym)
    vb = min_volume(sym)
    s = A.series["volumeud"]
    vdt, vv = s["dt"], np.array(s["v"], float) / vb     # relvol-reeks
    grp, _bad = rises(sym)
    grp.sort(key=lambda g: g[0])

    def window_at(t):
        k = bisect.bisect_right(vdt, t)
        return vv[k - L:k] if k >= L else None

    # goede reeksen (één per rise, op tijd gesorteerd) + achtergrond
    good = [(g[0], window_at(g[0])) for g in grp]
    good = [(t, w) for (t, w) in good if w is not None and len(w) == L]
    bg_idx = rng.choice(range(L, len(vdt)), size=min(N_BG, len(vdt) - L), replace=False)
    bg = [vv[i - L:i] for i in bg_idx]

    # tijd-split op de goede reeksen: vroege helft = train, late helft = test
    mid = len(good) // 2
    train_g = [w for (_t, w) in good[:mid]]
    test_g = [w for (_t, w) in good[mid:]]
    nb = len(bg) // 2
    train_bg, test_bg = bg[:nb], bg[nb:]

    Xtr = train_g + train_bg
    ytr = np.array([1] * len(train_g) + [0] * len(train_bg))
    Xte = test_g + test_bg
    yte = np.array([1] * len(test_g) + [0] * len(test_bg))

    # kandidaat-shapelets: subsequenties uit de TRAIN goede reeksen
    cands = []
    for w in WIDTHS:
        for series in train_g:
            for i in range(0, len(series) - w + 1, 2):
                cands.append(znorm(series[i:i + w]))
    _r.shuffle(cands)
    cands = cands[:N_CAND]

    # selecteer de beste shapelet op TRAIN (information gain)
    best = (-1, None, None, None)   # ig, shapelet, thr, train_acc
    for sh in cands:
        dtr = np.array([sdist(sh, x) for x in Xtr])
        ig, thr, acc = best_split(dtr, ytr)
        if ig > best[0]:
            best = (ig, sh, thr, acc)
    ig, sh, thr, tr_acc = best

    # evalueer op de APART-GEHOUDEN testperiode
    dte = np.array([sdist(sh, x) for x in Xte])
    pred = (dte <= thr).astype(int)        # dichtbij de shapelet → "goed"
    te_acc = (pred == yte).mean()
    # precisie/recall van "goed" op test
    tp = int(((pred == 1) & (yte == 1)).sum()); fp = int(((pred == 1) & (yte == 0)).sum())
    fn = int(((pred == 1) & (yte == 1)).sum() == 0)  # placeholder
    prec = tp / (tp + fp) if (tp + fp) else 0.0
    base_rate = yte.mean()                 # aandeel goed in test (nullijn-precisie)

    # toeval-toets: schud de testlabels, hoe vaak haalt random ≥ deze test-accuratesse?
    rngp = np.random.default_rng(SEED + 1)
    perm = np.array([(rngp.permutation(yte) == pred).mean() for _ in range(N_PERM)])
    p = float((perm >= te_acc).mean())

    print(f"\n=== {nm}: shapelet-proef op relvol-reeks (L={L}, breedtes {WIDTHS}) ===")
    print(f"  goede momenten {len(good)} (train {len(train_g)} / test {len(test_g)}), achtergrond {len(bg)}")
    print(f"  beste shapelet: lengte {len(sh)}, train info-gain {ig:.3f}, train-acc {tr_acc:.2f}")
    print(f"  APART-GEHOUDEN test: acc {te_acc:.2f} (nullijn {max(base_rate,1-base_rate):.2f}) | "
          f"precisie 'goed' {prec:.2f} (basis {base_rate:.2f}) | toeval-toets p={p:.3f}")
    verdict = "SIGNAAL (test-acc boven nullijn én p<0,05)" if (p < 0.05 and te_acc > max(base_rate, 1 - base_rate) + 0.02) \
        else "geen robuust signaal"
    print(f"  >>> {verdict}")


def main():
    for sym, nm in [(2525, "DOGEAI"), (244, "NOS")]:
        run(sym, nm)


if __name__ == "__main__":
    main()
