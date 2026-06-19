"""
READ-ONLY — evaluatie van de korte-termijn gate (vervolg op gate_window.py).

Drie beslissende tests:
1. LEK-CHECK: globale Cohen's d vs BINNEN-MAAND d. Trek per maand het gemiddelde van de feature af
   (de-mean) en herbereken d. Stort d in -> de feature codeerde vooral 'vroege vs late maand'
   (= het maand-stop signaal vermomd), geen echt korte-termijn signaal. Blijft d overeind ->
   het scheidt goede van slechte trades ook BINNEN dezelfde periode (echte gate-waarde).
2. GATE-test: drempel op DOGEAI, good_keep / bad_drop.
3. CROSS-COIN: dezelfde drempel ongewijzigd op NOS.
"""
import numpy as np
import pandas as pd
from gate_window import build_matrix, cohens_d, gate_test, best_threshold, SYM


def demean_by_month(df, feat):
    v = df[feat].astype(float)
    return v - df.groupby("ym")[feat].transform("mean")


def within_month_d(df, feat):
    """Cohen's d goed vs slecht NA per-maand de-mean (maand-effect verwijderd)."""
    dm = demean_by_month(df, feat)
    g = dm[df.label == "goed"].values
    b = dm[df.label == "slecht"].values
    return cohens_d(g, b)


if __name__ == "__main__":
    dfs = {sym: build_matrix(sid) for sym, sid in SYM.items()}
    base = dfs["DOGEAI"]
    featcols = [c for c in base.columns if c not in ("label", "best_upside", "pl", "ym")]

    # ---- 1. LEK-CHECK: globaal vs binnen-maand d ----
    g = base[base.label == "goed"]; b = base[base.label == "slecht"]
    rows = []
    for fc in featcols:
        d_glob = cohens_d(g[fc].values.astype(float), b[fc].values.astype(float))
        d_within = within_month_d(base, fc)
        if not np.isnan(d_glob):
            rows.append((fc, d_glob, d_within))
    rk = pd.DataFrame(rows, columns=["feature", "d_globaal", "d_binnenmaand"])
    rk["behoud_%"] = (rk["d_binnenmaand"].abs() / rk["d_globaal"].abs() * 100).round(0)
    rk = rk.reindex(rk["d_binnenmaand"].abs().sort_values(ascending=False).index)
    print("==== LEK-CHECK: features met het sterkste BINNEN-MAAND scheidend vermogen ====")
    print("(d_globaal hoog maar d_binnenmaand laag = maand-proxy, geen echte gate)")
    print(rk.head(16).to_string(index=False,
          formatters={"d_globaal": "{:.2f}".format, "d_binnenmaand": "{:.2f}".format}))

    # ---- 2+3. GATE-test op de beste BINNEN-MAAND features, DOGEAI + cross-coin NOS ----
    top = rk.head(6)["feature"].tolist()
    print("\n==== GATE-test (drempel gekalibreerd op DOGEAI, good_keep>=0.90) ====")
    for feat in top:
        bt = best_threshold(base, feat, min_keep=0.90)
        if not bt:
            print(f"\n  {feat}: geen drempel met good_keep>=0.90 en bad_drop>0")
            continue
        bd, gk, thr, side = bt
        print(f"\n  feature: {feat}   gate AAN als waarde {'>=' if side=='hoog' else '<='} {thr:.3f}")
        for sym, df in dfs.items():
            gk2, bd2, gkeep, bdrop, ng, nb = gate_test(df, feat, thr, side)
            print(f"    {sym:7} good_keep={gk2*100:5.1f}% ({gkeep}/{ng} goede behouden)   "
                  f"bad_drop={bd2*100:5.1f}% ({bdrop}/{nb} slechte voorkomen)")
