"""
READ-ONLY — backtest van kandidaat STOP/PAUZE-regels op beide coins.

Model: maandelijkse herbeoordeling. Elke maand zegt de regel DOOR of PAUZE op basis van
een ruw-data signaal. Een PAUZE betekent: die maand niet handelen (winst van die maand
wordt niet gemaakt — vermeden als slecht, gemist als goed).

We tellen per coin:
  behouden_pl  = Σ sum_pl over DOOR-maanden (wat je overhoudt)
  vermeden     = Σ sum_pl over gepauzeerde SLECHTE maanden (terecht weggelaten)
  gemist       = Σ sum_pl over gepauzeerde GOEDE maanden (ten onrechte weggelaten — de prijs)
  baseline     = Σ sum_pl over alle maanden (handel altijd)

Twee timings:
  gelijktijdig : signaal[M] beoordeelt maand M   (herkent de regel de slechte maand?)
  vooruit      : signaal[M-1] beoordeelt maand M (voorspelt de vorige maand de volgende?)
"""
import numpy as np
import pandas as pd
from coin_activity import analyse, grondwaarheid

GOED = 10.0    # maand 'goed' als Σwinst >= 10%
SLECHT = 5.0   # maand 'slecht' als Σwinst <= 5%


def build(sym):
    sig = analyse(sym)
    gw = grondwaarheid(sym)
    sig["trades"] = sig["ym"].map(lambda y: gw.get(y, {}).get("trades", 0))
    sig["sum_pl"] = sig["ym"].map(lambda y: float(gw.get(y, {}).get("sum_pl", 0) or 0))
    sig["avg_pl"] = sig["ym"].map(lambda y: float(gw.get(y, {}).get("avg_pl", 0) or 0))
    sig = sig[sig["trades"] > 0].reset_index(drop=True)
    return sig


def backtest(sig, signal_col, thr, timing="gelijktijdig", rel=False):
    s = sig[signal_col].astype(float).copy()
    if rel:
        s = s / s.median()           # relatief t.o.v. de coin's eigen mediaan
    if timing == "vooruit":
        s = s.shift(1)               # gebruik vorige maand
    door = (s >= thr) | s.isna()     # eerste maand (geen vorige) = altijd DOOR
    behouden = sig.loc[door, "sum_pl"].sum()
    paused = sig.loc[~door]
    vermeden = paused.loc[paused["sum_pl"] <= SLECHT, "sum_pl"].sum()
    gemist = paused.loc[paused["sum_pl"] >= GOED, "sum_pl"].sum()
    n_paused = (~door).sum()
    return dict(behouden=behouden, vermeden=vermeden, gemist=gemist,
                baseline=sig["sum_pl"].sum(), n_paused=int(n_paused), n_door=int(door.sum()))


if __name__ == "__main__":
    coins = {s: build(s) for s in ["DOGEAI", "NOS"]}
    # kandidaat-regels: (kolom, drempel-lijst, relatief?)
    cands = [
        ("up3", [12, 14, 16], False),
        ("up2", [22, 25, 28], False),
        ("day_range_pct", [11, 12, 13], False),
        ("vf1_per_dag", [25, 40], False),
        ("up3", [0.4, 0.5, 0.6], True),   # relatief t.o.v. eigen mediaan
    ]
    for timing in ["gelijktijdig", "vooruit"]:
        print(f"\n############ TIMING: {timing} ############")
        for col, thrs, rel in cands:
            for thr in thrs:
                tag = f"{col}{'(rel)' if rel else ''} < {thr}"
                print(f"\n  regel: PAUZE als {tag}")
                for sym, sig in coins.items():
                    r = backtest(sig, col, thr, timing, rel)
                    print(f"    {sym:7} baseline Σ={r['baseline']:7.1f} → behouden Σ={r['behouden']:7.1f}  "
                          f"| vermeden(slecht) {r['vermeden']:6.1f}  gemist(goed) {r['gemist']:6.1f}  "
                          f"| pauze {r['n_paused']}/{r['n_paused']+r['n_door']} mnd")
