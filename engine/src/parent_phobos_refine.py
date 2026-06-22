#!/usr/bin/env python3
"""
PHOBOS-SEGMENT verfijning (rule-discovery §10). (1) scherp het phobos-segment op CURRENT_VALUE met de
80-20-regel (de band die ~80% van de groepen bindt). (2) zoek dáárbinnen wat de groep verder BINDT —
descriptief, beginnend met volume (aantal negatief volume, % stijging t2 t.o.v. t0) + continue stijging.
Géén achtergrond — we kijken puur wat dit groepje bindt.
"""
import bisect
import numpy as np

from calc import window_metrics, calc_percentage
from parent_crossgroup import AsOf
from parent_fullperiod import rises


def at(A, ind, lb, m, T):
    s = A.series[ind]; k = bisect.bisect_right(s["dt"], T); w = s["v"][max(0, k - lb):k][::-1]
    return window_metrics(w).get(m) if len(w) >= 1 else None


def price_at(A, T):
    i = bisect.bisect_right(A.vdt, T)
    return A.vpx[i - 1] if i > 0 else None


def binds(vals, frac=0.6):
    """tightste band die `frac` v.d. groepen bindt; geef (lo,hi, breedte/spreiding)."""
    v = np.sort(np.array([x for x in vals if x is not None and np.isfinite(x)]))
    n = len(v)
    if n < 10:
        return None
    k = max(3, int(np.ceil(frac * n)))
    w = v[k - 1:] - v[:n - k + 1]
    i = int(np.argmin(w))
    spread = np.percentile(v, 95) - np.percentile(v, 5)
    return v[i], v[i + k - 1], (v[i + k - 1] - v[i]) / spread if spread > 0 else 9


def main():
    for sym, name in ((2525, "DOGEAI"), (244, "NOS")):
        A = AsOf(sym); groups, _ = rises(sym)
        # (1) phobos current_value 80-20
        pcv = [(g, at(A, "phobos", 1, "current_value", g[2])) for g in groups]
        pcv = [(g, v) for g, v in pcv if v is not None]
        vals = np.array([v for _, v in pcv])
        lo, hi = np.percentile(vals, 10), np.percentile(vals, 90)
        print(f"\n################ {name}: phobos current_value @ t2 ################")
        print(f"  verdeling: p10={lo:.1f} p25={np.percentile(vals,25):.1f} p50={np.percentile(vals,50):.1f} p75={np.percentile(vals,75):.1f} p90={hi:.1f}")
        print(f"  80%-segment (phobos cv in [{lo:.1f}, {hi:.1f}]) bindt {int(0.8*len(vals))} van {len(vals)} groepen")
        seg = [g for g, v in pcv if lo <= v <= hi]        # de 80%-segment-groepen

        # (2) wat bindt dit segment verder? — descriptief, user-genoemde kandidaten
        def col(fn):
            return np.array([x for x in (fn(g) for g in seg) if x is not None and np.isfinite(x)])
        print(f"  -- wat bindt het 80%-segment ({len(seg)} groepen)? p10/p50/p90 + binding-band (60%) --")

        cands = []
        # VOLUME: aantal negatief volumeud over lookback
        for lb in (3, 5, 10, 20):
            cands.append((f"volume: #neg volumeud /{lb}", lambda g, lb=lb: at(A, "volumeud", lb, "count_negative", g[2])))
        cands.append(("volume: volumeud now (teken/grootte)", lambda g: at(A, "volumeud", 1, "current_value", g[2])))
        # PRIJS: stijging t2 t.o.v. t0 (de mini-move van het groepje)
        def rise_t0t2(g):
            p0, p2 = price_at(A, g[0]), price_at(A, g[2])
            return calc_percentage(p0, p2) if p0 and p2 else None
        cands.append(("prijs: % stijging t2 vs t0", rise_t0t2))
        # PRIJS: stijging + geen daling over lookback (continue stijging)
        for lb in (3, 5, 10):
            cands.append((f"prijs: % change /{lb}", lambda g, lb=lb: pchange(A, g[2], lb)))
            cands.append((f"prijs: min-dip% /{lb} (geen daling=~0)", lambda g, lb=lb: pmindip(A, g[2], lb)))
        for lb in (5, 10):
            cands.append((f"prijs: consec-increases /{lb}", lambda g, lb=lb: pconsec(A, g[2], lb)))

        rows = []
        for label, fn in cands:
            c = col(fn)
            if len(c) < 0.5 * len(seg):
                continue
            b = binds(c, 0.6)
            tight = b[2] if b else 9
            rows.append((tight, label, np.percentile(c, 10), np.percentile(c, 50), np.percentile(c, 90), b))
        rows.sort()
        for tight, label, p10, p50, p90, b in rows:
            mark = "  <== STRAK" if tight < 0.35 else ""
            band = f"[{b[0]:.2f},{b[1]:.2f}]" if b else "-"
            print(f"    {label:36s} p10/p50/p90 = {p10:7.2f}/{p50:7.2f}/{p90:7.2f} | 60% in {band} (tight={tight:.2f}){mark}")


def pchange(A, T, lb):
    i = bisect.bisect_right(A.vdt, T); pr = [p for p in A.vpx[max(0, i - lb):i] if p is not None]
    return calc_percentage(pr[0], pr[-1]) if len(pr) >= 2 and pr[0] else None


def pmindip(A, T, lb):
    i = bisect.bisect_right(A.vdt, T); pr = [p for p in A.vpx[max(0, i - lb):i] if p is not None]
    return 100 * (min(pr) - pr[0]) / pr[0] if len(pr) >= 2 and pr[0] else None


def pconsec(A, T, lb):
    i = bisect.bisect_right(A.vdt, T); pr = [p for p in A.vpx[max(0, i - lb):i] if p is not None][::-1]
    return window_metrics(pr).get("consecutive_increases") if len(pr) >= 2 else None


if __name__ == "__main__":
    main()
