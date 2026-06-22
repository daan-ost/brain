#!/usr/bin/env python3
"""
READ-ONLY cross-groep gemene-deler (Daans methode, gecorrigeerd):
  - per promising groepje (eerste-3 yes-ticks = de triple) de band [min,max] van de oscillator-
    current_value (obv/vzo/mfi/phobos);
  - zoek per feature de TIGHTSTE cluster van >=3 groepjes (dat is de herhalende gemene deler);
  - projecteer die band over de COMPLETE muntperiode met de 3-opvolgende-ticks cascade -> tel survivors;
  - forward-afloop van survivors (up%/dip% over 60m). Alleen yes-marks zijn grondwaarheid;
    ongelabelde survivors heten NOOIT 'slecht' — we rapporteren hun afloop, niet een oordeel.
"""
import bisect
import datetime as dt
import sys

import numpy as np

from db import brain
from parent_fullperiod import rises

OSC = ("obv-x-value", "vzo", "mfi", "phobos")


class AsOf:
    def __init__(self, symbol):
        self.symbol = symbol
        with brain().cursor() as c:
            self.series = {}
            for ind in OSC + ("volumeud",):
                c.execute("SELECT datetime, value, price FROM indicators WHERE trading_symbol_id=%s "
                          "AND indicator=%s AND value IS NOT NULL ORDER BY datetime", (symbol, ind))
                rs = c.fetchall()
                self.series[ind] = {"dt": [r["datetime"] for r in rs],
                                    "v": [float(r["value"]) for r in rs],
                                    "p": [float(r["price"]) if r["price"] is not None else None for r in rs]}
        self.vdt = self.series["volumeud"]["dt"]
        self.vpx = self.series["volumeud"]["p"]

    def val(self, ind, T):
        s = self.series[ind]
        i = bisect.bisect_right(s["dt"], T)
        return s["v"][i - 1] if i > 0 else None

    def fwd(self, T, minutes=60):
        """max up% en min dip% van de volumeud-prijs over [T, T+minutes] vanaf prijs op/voor T."""
        i = bisect.bisect_right(self.vdt, T)
        if i == 0:
            return None, None
        p0 = self.vpx[i - 1]
        end = T + dt.timedelta(minutes=minutes)
        up = dip = 0.0
        j = i
        while j < len(self.vdt) and self.vdt[j] <= end:
            p = self.vpx[j]
            if p is not None and p0:
                ch = 100 * (p - p0) / p0
                up = max(up, ch); dip = min(dip, ch)
            j += 1
        return up, dip


def per_group_bands(A, groups):
    """per groepje: center (mean) van de triple voor elke oscillator current_value."""
    out = []
    for g in groups:
        rec = {"start": g[0]}
        for ind in OSC:
            vals = [A.val(ind, t) for t in g]
            vals = [v for v in vals if v is not None]
            if vals:
                rec[ind] = (min(vals), max(vals), float(np.mean(vals)))
        out.append(rec)
    return out


def tightest_cluster(centers, min_n=3):
    """vind de smalste band die >=min_n centers omvat (1D, sorteer + sliding window)."""
    cs = sorted(centers)
    best = None
    for i in range(len(cs)):
        j = i + min_n - 1
        if j < len(cs):
            width = cs[j] - cs[i]
            if best is None or width < best[2]:
                best = (cs[i], cs[j], width, j - i + 1)
    # rek op zolang extra centers binnen 1.5x de breedte vallen
    return best


def project(A, lo, hi, ind, k=3):
    """tel ticks waar `ind` current_value in [lo,hi] voor k opvolgende volumeud-ticks; geef survivors (de k-de tick)."""
    s = A.series[ind]; vdt = A.vdt
    # as-of waarde per volumeud-tick
    vals = []
    si = 0
    for T in vdt:
        i = bisect.bisect_right(s["dt"], T)
        vals.append(s["v"][i - 1] if i > 0 else None)
    inb = [(v is not None and lo <= v <= hi) for v in vals]
    survivors = []
    run = 0
    for idx in range(len(inb)):
        run = run + 1 if inb[idx] else 0
        if run >= k:
            survivors.append(vdt[idx])
    return survivors


def main(symbol, name):
    A = AsOf(symbol)
    groups, bad = rises(symbol)
    groups.sort(key=lambda g: g[0])
    bands = per_group_bands(A, groups)
    print(f"\n################  {name} ({symbol}) — {len(groups)} promising groepjes  ################")
    for ind in OSC:
        centers = [b[ind][2] for b in bands if ind in b]
        print(f"\n--- {ind} current_value: per-groep triple-center ---")
        print("  " + "  ".join(f"{c:5.1f}" for c in sorted(centers)))
        cl = tightest_cluster(centers, 3)
        if cl:
            lo, hi, w, n = cl
            # rek de band met de triple-spreiding (gebruik min/max van de groepjes in de cluster)
            members = [b for b in bands if ind in b and lo - 1e-9 <= b[ind][2] <= hi + 1e-9]
            blo = min(b[ind][0] for b in members); bhi = max(b[ind][1] for b in members)
            surv = project(A, blo, bhi, ind, k=3)
            # forward-afloop survivors
            ups, dips = [], []
            for t in surv:
                u, d = A.fwd(t)
                if u is not None:
                    ups.append(u); dips.append(d)
            tot = len(A.vdt)
            med_up = np.median(ups) if ups else 0
            med_dip = np.median(dips) if dips else 0
            deep = np.mean([d < -3 for d in dips]) if dips else 0
            print(f"  tightste cluster: {n} groepjes met center in [{lo:.1f},{hi:.1f}]")
            print(f"  -> projectie-band (triple-min..max van die groepjes): [{blo:.1f},{bhi:.1f}]")
            print(f"  -> survivors (3 opvolgende ticks in band): {len(surv)} van {tot} ticks ({100*len(surv)/tot:.1f}%)")
            print(f"  -> forward 60m: mediaan up={med_up:.2f}%  mediaan dip={med_dip:.2f}%  % dip<-3%={100*deep:.0f}%")


if __name__ == "__main__":
    if len(sys.argv) > 1:
        main(int(sys.argv[1]), sys.argv[1])
    else:
        main(2525, "DOGEAI"); main(244, "NOS")
