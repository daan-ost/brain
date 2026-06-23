#!/usr/bin/env python3
"""
consensus_test.py — verhoogt CONSENSUS van de discovery-rules de precisie? (Daans insteek)

De live coin_fires van 30-34 overlappen niet (gap-fill dedupliceert), dus consensus is daar niet af te
lezen. Hier evalueren we ONGEPOORT per tick via de vectorized discovery-mask (numpy, identiek aan het
live-pad — getrouwheid was 100%): hoeveel van de rules 30-34 vuren op datzelfde moment. Dan sell-simulatie
(rule-20-gedrag) per fire-tick met single-position dedup, gegroepeerd op consensus-niveau k = #rules dat
samen vuurt. Vraag: daalt slecht% / stijgt gem profit met k?

ALLEEN-LEZEN. Draaien (vanuit engine/src):  ../.venv/bin/python -m discovery.consensus_test
"""
import bisect
import json
import os

import numpy as np

from discovery.data import build_matrix

RULES = (30, 31, 32, 33, 34)   # alle discovery-rules (33 inactief, maar cache-json bestaat)
PL_CAP = 200.0
CACHE = os.path.join(os.path.dirname(__file__), ".cache")


def load_subrules(rule):
    fn = "pooled_rule.json" if rule == 30 else f"pooled_rule_{rule}.json"
    data = json.load(open(os.path.join(CACHE, fn)))
    return [(c, s, lo, hi) for (c, s, lo, hi) in data["subrules"]]


def run(sym, nm):
    dd = build_matrix(sym, nm, verbose=False)
    masks, active = {}, []
    for r in RULES:
        try:
            masks[r] = dd.mask(load_subrules(r))
            active.append(r)
        except FileNotFoundError:
            pass
    k_arr = np.sum([masks[r] for r in active], axis=0)     # consensus-niveau per tick
    fire_idx = np.flatnonzero(k_arr >= 1)

    # single-position dedup: open op de eerste fire-tick, sla over tot de verkoop; consensus-k van de
    # trade = max k over de fire-ticks binnen het instap-venster [t, sell].
    trades = []
    open_until = None
    vdt, vpx = dd.vdt, dd.A.vpx
    for ii in fire_idx:
        t = vdt[ii]
        if open_until is not None and t <= open_until:
            continue
        buy = vpx[ii]
        if not buy or buy <= 0:
            continue
        r = dd.eng.sell(t, buy, 20)
        if r is None or abs(r["profit_loss"]) > PL_CAP:
            continue
        sd = r["selling_date"]
        open_until = sd
        hi = bisect.bisect_right(vdt, sd)
        kmax = int(k_arr[ii:hi].max()) if hi > ii else int(k_arr[ii])
        trades.append((kmax, float(r["profit_loss"]), t))

    print(f"\n=== {nm}: {len(trades)} trades (single-position) | rules {active} ===")
    print(f"  {'k':>5s} {'trades':>7s} {'slecht%':>8s} {'goed%':>6s} {'gem pl':>8s} {'Σ pl':>8s}")
    for k in range(1, len(active) + 1):
        sub = [pl for (kk, pl, _t) in trades if kk == k]
        if sub:
            n = len(sub); sl = sum(1 for p in sub if p < 0); gd = sum(1 for p in sub if p >= 3)
            print(f"  {('='+str(k)):>5s} {n:7d} {100*sl/n:7.0f}% {100*gd/n:5.0f}% {sum(sub)/n:+8.2f} {sum(sub):+8.0f}")
    print("  -- cumulatief (koop alleen bij minstens k rules) --")
    for k in range(1, len(active) + 1):
        sub = [pl for (kk, pl, _t) in trades if kk >= k]
        if sub:
            n = len(sub); sl = sum(1 for p in sub if p < 0); gd = sum(1 for p in sub if p >= 3)
            print(f"  {('>='+str(k)):>5s} {n:7d} {100*sl/n:7.0f}% {100*gd/n:5.0f}% {sum(sub)/n:+8.2f} {sum(sub):+8.0f}")

    # ---- TOEVAL-TOETS: is de k>=2-selectie echt beter dan een WILLEKEURIGE greep van dezelfde grootte? ----
    allpl = np.array([pl for (_k, pl, _t) in trades])
    sel2 = np.array([pl for (kk, pl, _t) in trades if kk >= 2])
    if len(sel2) >= 10 and len(allpl) > len(sel2):
        m = len(sel2)
        obs_bad = float((sel2 < 0).mean()); obs_mean = float(sel2.mean())
        rng = np.random.default_rng(1)
        rb = np.empty(5000); rm = np.empty(5000)
        for b in range(5000):
            samp = allpl[rng.choice(len(allpl), m, replace=False)]
            rb[b] = (samp < 0).mean(); rm[b] = samp.mean()
        p_bad = float((rb <= obs_bad).mean())      # kans dat random ≥ even weinig verliezers geeft
        p_mean = float((rm >= obs_mean).mean())     # kans dat random ≥ even hoge gem winst geeft
        print(f"  toeval-toets k>=2 ({m} trades): slecht% {100*obs_bad:.0f}% p={p_bad:.3f} | "
              f"gem {obs_mean:+.2f} p={p_mean:.3f}  (p<0,05 = echte selectie, geen toeval)")
        # ---- TIJD-SPLIT: houdt de k>=2-winst stand in een latere periode? ----
        ts = sorted(t for (_k, _pl, t) in trades)
        mid = ts[len(ts) // 2]
        for lbl, lo, hiB in [("vroeg", None, mid), ("laat", mid, None)]:
            s = [pl for (kk, pl, t) in trades if kk >= 2 and (lo is None or t >= lo) and (hiB is None or t < hiB)]
            if s:
                n = len(s); sl = sum(1 for p in s if p < 0)
                print(f"     {lbl}: {n} k>=2-trades, slecht {100*sl/n:.0f}%, gem {sum(s)/n:+.2f}")


def main():
    for sym, nm in [(2525, "DOGEAI"), (244, "NOS")]:
        run(sym, nm)


if __name__ == "__main__":
    main()
