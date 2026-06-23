#!/usr/bin/env python3
"""
volume3_baseline.py — wat haalt de HUIDIGE volume-functie (check_volumeud_3, rule 20-23) qua precisie?

IJkpunt voor de shapelet-proef: meet op exact dezelfde test-set (rise-starts = goede momenten + random
achtergrond, seed 1, zoals shapelet_probe) hoe goed volume_3 de goede koop-momenten markeert. Precisie =
van de momenten waar volume_3=True, welk deel is een echt goed moment (rise); recall = welk deel van de
rises triggert. Per rule (eigen min_volume + settings). ALLEEN-LEZEN.

Draaien (vanuit engine/src):  ../.venv/bin/python -m discovery.volume3_baseline
"""
import bisect
from datetime import timedelta

import numpy as np

from db import brain
from parent_crossgroup import AsOf
from parent_fullperiod import rises
from volume import check_volumeud_3, volume_settings

N_BG = 400
SEED = 1
RULES = (20, 21, 22, 23)


def vol_rows(s, T, minutes=60):
    """newest-first volume-rows in de laatste `minutes` vóór T (zoals RuleEngine._vol_rows)."""
    i = bisect.bisect_right(s["dt"], T)
    cut = T - timedelta(minutes=minutes)
    out, j = [], i - 1
    while j >= 0 and s["dt"][j] >= cut:
        out.append({"datetime": s["dt"][j], "value": s["v"][j], "price": s["p"][j]})
        j -= 1
    return out


def run(sym, nm):
    rng = np.random.default_rng(SEED)
    A = AsOf(sym)
    s = A.series["volumeud"]
    vdt = s["dt"]
    grp, _bad = rises(sym)
    grp.sort(key=lambda g: g[0])
    good_ts = [g[0] for g in grp]
    bg_idx = rng.choice(range(60, len(vdt)), size=min(N_BG, len(vdt) - 60), replace=False)
    bg_ts = [vdt[i] for i in bg_idx]

    # min_volume per rule (coin_rule_settings)
    with brain().cursor() as c:
        c.execute("SELECT rule_number, min_volume FROM coin_rule_settings WHERE trading_symbol_id=%s", (sym,))
        mv = {r["rule_number"]: float(r["min_volume"]) if r["min_volume"] is not None else 1e12 for r in c.fetchall()}

    moments = [(t, 1) for t in good_ts] + [(t, 0) for t in bg_ts]
    base = sum(g for _t, g in moments) / len(moments)
    print(f"\n=== {nm}: volume_3 op {len(good_ts)} rises + {len(bg_ts)} achtergrond (basis-rate goed {base:.2f}) ===")
    print(f"  {'rule':>4s} {'min_vol':>10s} {'True':>5s} {'waarvan goed':>12s} {'precisie':>9s} {'recall':>7s}")
    for rule in RULES:
        m = mv.get(rule, 1e12)
        vs = volume_settings(rule)
        fired = [(g) for (t, g) in moments if check_volumeud_3(vol_rows(s, t), m, vs)]
        n_true = len(fired)
        n_good = sum(fired)
        prec = n_good / n_true if n_true else 0.0
        recall = n_good / len(good_ts) if good_ts else 0.0
        print(f"  {rule:>4d} {m:10.0f} {n_true:5d} {n_good:12d} {prec:8.2f}{'' :1s} {recall:6.2f}")
    print(f"  (shapelet-proef ter vergelijking: precisie 'goed' 0,40 vs basis {base:.2f} op DOGEAI)")


def main():
    for sym, nm in [(2525, "DOGEAI"), (244, "NOS")]:
        run(sym, nm)


if __name__ == "__main__":
    main()
