"""
READ-ONLY — 'stoplicht' per coin: groen / oranje / rood op basis van de RELATIEVE beweeglijkheid.

Idee (Daan): we zoeken VOLATILE trades. Een coin koelt af; zet hem dan op pauze en roteer naar
een beweeglijker coin. Het stoplicht hoeft de winst niet vooruit te voorspellen — het hoeft alleen
te zeggen 'deze coin is afgekoeld t.o.v. zijn eigen beste dagen'.

Maat: 7-daags beweeglijkheid (% momenten met >=2% stijging binnen 1u) gedeeld door de coin's eigen
30-daags piek. Relatief, dus schaal-vrij — werkt op elk coin-niveau.
  groen  >= 60% van eigen piek
  oranje 40-60%
  rood   < 40%
Print de kleur-overgangen naast de maand-winst.
"""
import sys
import numpy as np
import pandas as pd
from coin_activity_daily import daily, trade_pl_daily

GREEN, RED = 0.60, 0.40


def stoplicht(sym):
    d = daily(sym)
    mov = d["up2_pct_7d"]
    piek30 = mov.rolling(30, min_periods=7).max()
    ratio = mov / piek30
    kleur = pd.Series(np.where(ratio >= GREEN, "groen",
                      np.where(ratio >= RED, "oranje", "rood")), index=d.index)
    kleur = kleur.where(ratio.notna())
    return pd.DataFrame({"mov7d": mov.round(1), "piek30": piek30.round(1),
                         "ratio": ratio.round(2), "kleur": kleur})


if __name__ == "__main__":
    for sym in (sys.argv[1:] or ["DOGEAI", "NOS"]):
        sl = stoplicht(sym)
        t = trade_pl_daily(sym)
        # maandwinst erbij
        plm = t["pl"].groupby(t.index.to_period("M")).sum()
        print(f"\n===== {sym}: stoplicht-overgangen =====")
        prev = None
        for dt, row in sl.iterrows():
            k = row["kleur"]
            if pd.isna(k):
                continue
            if k != prev:
                print(f"  {dt.date()}  -> {k.upper():6}  (beweeglijkheid {row['mov7d']}, "
                      f"{int(row['ratio']*100) if pd.notna(row['ratio']) else '?'}% van 30d-piek {row['piek30']})")
                prev = k
        print(f"  maandwinst: " + "  ".join(f"{p}:{v:+.0f}%" for p, v in plm.items()))
