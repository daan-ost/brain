"""
READ-ONLY — loont de korte-termijn gate in ECHTE winst, en houdt hij stand out-of-time?

1. P&L-impact: pas de gate toe als filter. Zonder gate (alle trades) vs met gate (alleen 'aan').
   Toon Σprofit_loss, aantal verliezers (pl<0), gemiddelde pl van behouden vs voorkomen trades.
2. Combinatie: twee gates ge-AND (div_pricevol EN posrange) — voorkomt dat meer slecht bij
   behoud van goed?
3. TIJDS-HOLDOUT: kalibreer de drempel op de eerste helft van DOGEAI, test op de tweede helft
   (out-of-time) + op heel NOS (out-of-coin). De eerlijkste test.
"""
import numpy as np
import pandas as pd
from gate_window import build_matrix, gate_test, best_threshold, SYM


def pnl_impact(df, mask_aan, name):
    aan = df[mask_aan]; uit = df[~mask_aan]
    tot = df["pl"].sum(); tot_aan = aan["pl"].sum()
    print(f"  {name}")
    print(f"    zonder gate : {len(df):3} trades  Σpl={tot:8.1f}  verliezers(pl<0)={int((df.pl<0).sum())}  gem={df.pl.mean():.3f}")
    print(f"    met gate AAN: {len(aan):3} trades  Σpl={tot_aan:8.1f}  verliezers={int((aan.pl<0).sum())}  gem={aan.pl.mean():.3f}")
    print(f"    UIT (vermeden): {len(uit):3} trades  Σpl={uit['pl'].sum():8.1f}  verliezers={int((uit.pl<0).sum())}  gem={uit.pl.mean() if len(uit) else 0:.3f}")
    print(f"    -> Σpl {tot:.1f} → {tot_aan:.1f} ({tot_aan-tot:+.1f})  | verliezers {int((df.pl<0).sum())} → {int((aan.pl<0).sum())}")


def apply_gate(df, feat, thr, side):
    v = df[feat].values
    aan = (v >= thr) if side == "hoog" else (v <= thr)
    return aan & ~np.isnan(v)


if __name__ == "__main__":
    dfs = {sym: build_matrix(sid) for sym, sid in SYM.items()}
    base = dfs["DOGEAI"]

    FEAT, SIDE = "div_pricevol_W45", "hoog"
    bt = best_threshold(base, FEAT, min_keep=0.90)
    bd, gk, thr, side = bt
    print(f"=== 1. P&L-IMPACT — gate: {FEAT} {'>=' if side=='hoog' else '<='} {thr:.3f} (op heel DOGEAI gekalibreerd) ===\n")
    for sym, df in dfs.items():
        aan = apply_gate(df, FEAT, thr, side)
        pnl_impact(df, aan, sym)
        print()

    # ---- 2. COMBINATIE: div_pricevol AND posrange ----
    print("=== 2. COMBINATIE div_pricevol_W45 EN price_W60_posrange (beide gates AAN) ===\n")
    bt2 = best_threshold(base, "price_W60_posrange", min_keep=0.95)
    _, _, thr2, side2 = bt2
    for sym, df in dfs.items():
        a1 = apply_gate(df, FEAT, thr, side)
        a2 = apply_gate(df, "price_W60_posrange", thr2, side2)
        aan = a1 & a2
        g = df.label.values == "goed"; b = df.label.values == "slecht"
        print(f"  {sym:7} good_keep={(aan&g).sum()/g.sum()*100:5.1f}%  bad_drop={(b&~aan).sum()/b.sum()*100:5.1f}%  "
              f"Σpl {df.pl.sum():.1f}→{df[aan].pl.sum():.1f}  verliezers {int((df.pl<0).sum())}→{int((df[aan].pl<0).sum())}")

    # ---- 3. TIJDS-HOLDOUT op DOGEAI ----
    print("\n=== 3. TIJDS-HOLDOUT: drempel op 1e helft DOGEAI, test op 2e helft + NOS ===\n")
    base_sorted = base.sort_values("ym").reset_index(drop=True)
    half = len(base_sorted) // 2
    train, test = base_sorted.iloc[:half], base_sorted.iloc[half:]
    bt_tr = best_threshold(train, FEAT, min_keep=0.90)
    if bt_tr:
        bdh, gkh, thrh, sideh = bt_tr
        print(f"  drempel uit 1e helft DOGEAI ({train.ym.min()}..{train.ym.max()}): {FEAT} >= {thrh:.3f}")
        for naam, df in [("DOGEAI 1e helft (in-sample)", train),
                         ("DOGEAI 2e helft (out-of-time)", test),
                         ("NOS (out-of-coin)", dfs["NOS"])]:
            gk2, bd2, gkeep, bdrop, ng, nb = gate_test(df, FEAT, thrh, sideh)
            print(f"    {naam:32} good_keep={gk2*100:5.1f}% ({gkeep}/{ng})  bad_drop={bd2*100:5.1f}% ({bdrop}/{nb})")
