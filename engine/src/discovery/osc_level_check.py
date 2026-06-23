#!/usr/bin/env python3
"""
osc_level_check.py — haalt een oscillator-NIVEAU (currentvalue) eigenlijk slechte trades weg? (Daans vraag)

De discovery-engine kijkt alleen naar VORM-metrics, niet naar het absolute niveau (currentvalue) van een
indicator. Bij 20-23 was juist het niveau (bv. "lage phobos") de belangrijkste eerste verfijning. Maar de
bereiken zijn breed (phobos -60..59, mfi 0..100). Vraag: scheidt een currentvalue-band winnaars van
verliezers, of zit alles door elkaar?

Test op de HUIDIGE live rule-30/31 trades (gepoold + per munt): split winst (pl>=0) vs verlies (pl<0),
en zoek per oscillator de drempel die de MEESTE verliezers weert per opgeofferde winnaar. Als de beste cut
verliezers en winnaars even hard raakt → geen meerwaarde (Daans hypothese). ALLEEN-LEZEN.

Draaien (vanuit engine/src):  ../.venv/bin/python -m discovery.osc_level_check
"""
import bisect

import numpy as np

from db import brain
from parent_crossgroup import AsOf

OSC = ["mfi", "phobos", "obv-x-value", "vzo"]   # begrensde/genormaliseerde indicatoren (geen volumeud)
RULES = (30, 31)


def trades(sym):
    with brain().cursor() as c:
        c.execute("SELECT datetime, profit_loss FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND rule IN (30,31) AND is_executed=1 AND profit_loss IS NOT NULL ORDER BY datetime", (sym,))
        return [(r["datetime"], float(r["profit_loss"])) for r in c.fetchall()]


def osc_at(A, ind, t):
    s = A.series.get(ind)
    if not s:
        return np.nan
    k = bisect.bisect_right(s["dt"], t)
    return float(s["v"][k - 1]) if k > 0 else np.nan


def best_cut(vals, pls):
    """Beste enkele drempel (ge of le): maximaliseer (verliezers weg) terwijl winnaars zoveel mogelijk
    blijven. Score = #verliezers-geweerd - #winnaars-geweerd (netto goede trades behouden)."""
    fin = np.isfinite(vals)
    vals, pls = vals[fin], pls[fin]
    loss = pls < 0
    n_loss, n_win = int(loss.sum()), int((~loss).sum())
    if n_loss == 0 or n_win == 0:
        return None
    best = None
    for thr in np.quantile(vals, np.linspace(0.05, 0.95, 19)):
        for side in ("ge", "le"):
            keep = vals >= thr if side == "ge" else vals <= thr   # trades die de band DOORLATEN
            l_weg = n_loss - int(loss[keep].sum())                # verliezers buiten de band = geweerd
            w_weg = n_win - int((~loss)[keep].sum())
            score = l_weg - w_weg
            if best is None or score > best[0]:
                best = (score, side, float(thr), l_weg, n_loss, w_weg, n_win)
    return best


def run(label, rows, A_map):
    vals_by = {o: [] for o in OSC}
    pls = []
    for sym, (t, pl) in rows:
        pls.append(pl)
        for o in OSC:
            vals_by[o].append(osc_at(A_map[sym], o, t))
    pls = np.array(pls)
    n_loss = int((pls < 0).sum())
    print(f"\n=== {label}: {len(pls)} trades ({n_loss} verliezers, {len(pls)-n_loss} winst≥0) ===")
    print(f"  {'oscillator':<13s} {'gem WIN':>8s} {'gem VERL':>8s}  beste-cut: verliezers-weg / winnaars-weg")
    for o in OSC:
        v = np.array(vals_by[o])
        fin = np.isfinite(v)
        win_m = np.nanmean(v[fin & (pls >= 0)]) if (fin & (pls >= 0)).any() else np.nan
        los_m = np.nanmean(v[fin & (pls < 0)]) if (fin & (pls < 0)).any() else np.nan
        bc = best_cut(v, pls)
        if bc:
            _s, side, thr, l_weg, nl, w_weg, nw = bc
            tag = f"{side} {thr:.1f}: {l_weg}/{nl} verl weg ({100*l_weg//nl}%), {w_weg}/{nw} win weg ({100*w_weg//nw}%)"
        else:
            tag = "(te weinig data)"
        print(f"  {o:<13s} {win_m:8.1f} {los_m:8.1f}  {tag}")


def main():
    coins = [(2525, "DOGEAI"), (244, "NOS")]
    A_map = {sym: AsOf(sym) for sym, _ in coins}
    pooled = []
    for sym, nm in coins:
        rows = [(sym, tp) for tp in trades(sym)]
        pooled += rows
        run(nm, rows, A_map)
    run("GEPOOLD (beide munten = de coin-agnostische toets)", pooled, A_map)
    print("\n  Lees: als 'gem WIN' ≈ 'gem VERL' en de beste cut even veel winnaars als verliezers weert,")
    print("  dan scheidt het niveau niets → geen meerwaarde als gedeelde currentvalue-band.")


if __name__ == "__main__":
    main()
