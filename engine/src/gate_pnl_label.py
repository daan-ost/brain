"""
READ-ONLY — de gate getest tegen het ECHTE doel: winnaars behouden, verliezers voorkomen.

Vorige stap (gate_pnl.py) toonde: een gate op best_upside-KANS levert netto winst IN, want de
sell-engine perst uit lage-kans trades toch winst. Daan's 'goede/slechte trade' = in winst-termen
WINNAAR (pl>0) vs VERLIEZER (pl<0). Dus herlabelen en opnieuw zoeken: scheiden de korte-termijn
prijs-vorm features de echte winnaars van de verliezers? En levert dat Σwinst op?
"""
import numpy as np
import pandas as pd
from gate_window import build_matrix, cohens_d, SYM
from gate_eval import demean_by_month

WINVERLIES = lambda pl: "win" if pl > 0 else "verlies"


def relabel(df):
    df = df.copy()
    df["wl"] = df["pl"].map(WINVERLIES)
    return df


def cohens_d_wl(df, feat):
    g = df[df.wl == "win"][feat].values.astype(float)
    b = df[df.wl == "verlies"][feat].values.astype(float)
    return cohens_d(g, b)


def within_month_d_wl(df, feat):
    dm = demean_by_month(df, feat)
    return cohens_d(dm[df.wl == "win"].values, dm[df.wl == "verlies"].values)


def best_threshold_wl(df, feat, min_keep=0.90):
    v = df[feat].values; fin = v[~np.isnan(v)]
    if len(fin) < 20:
        return None
    win = df.wl.values == "win"; los = df.wl.values == "verlies"
    nw, nl = win.sum(), los.sum()
    best = None
    for q in np.linspace(1, 99, 99):
        thr = np.percentile(fin, q)
        for side in ("hoog", "laag"):
            aan = ((v >= thr) if side == "hoog" else (v <= thr)) & ~np.isnan(v)
            keep = (aan & win).sum() / nw
            drop = (los & ~aan).sum() / nl
            if keep >= min_keep and drop > 0 and (best is None or drop > best[0]):
                best = (drop, keep, thr, side)
    return best


def eval_gate(df, feat, thr, side):
    v = df[feat].values
    aan = ((v >= thr) if side == "hoog" else (v <= thr)) & ~np.isnan(v)
    win = df.wl.values == "win"; los = df.wl.values == "verlies"
    return dict(keep=(aan & win).sum() / win.sum(), drop=(los & ~aan).sum() / los.sum(),
                spl0=df.pl.sum(), spl1=df[aan].pl.sum(),
                verl0=int((df.pl < 0).sum()), verl1=int((df[aan].pl < 0).sum()),
                n0=len(df), n1=int(aan.sum()))


if __name__ == "__main__":
    dfs = {sym: relabel(build_matrix(sid)) for sym, sid in SYM.items()}
    base = dfs["DOGEAI"]
    featcols = [c for c in base.columns if c not in ("label", "best_upside", "pl", "ym", "wl")]
    for sym, df in dfs.items():
        print(f"{sym}: win={int((df.wl=='win').sum())} verlies={int((df.wl=='verlies').sum())}")

    # rank op winnaar-vs-verliezer, globaal + binnen-maand
    rows = []
    for fc in featcols:
        dg = cohens_d_wl(base, fc); dw = within_month_d_wl(base, fc)
        if not np.isnan(dg):
            rows.append((fc, dg, dw))
    rk = pd.DataFrame(rows, columns=["feature", "d_globaal", "d_binnenmaand"])
    rk = rk.reindex(rk.d_binnenmaand.abs().sort_values(ascending=False).index)
    print("\n==== features die WINNAAR vs VERLIEZER scheiden (DOGEAI) ====")
    print(rk.head(12).to_string(index=False, formatters={"d_globaal": "{:.2f}".format, "d_binnenmaand": "{:.2f}".format}))

    print("\n==== GATE op winnaar/verliezer: drempel op DOGEAI (keep_win>=0.90), P&L + cross-coin ====")
    for feat in rk.head(5).feature.tolist():
        bt = best_threshold_wl(base, feat, min_keep=0.90)
        if not bt:
            print(f"\n  {feat}: geen drempel met keep_win>=0.90 en drop_verlies>0"); continue
        drop, keep, thr, side = bt
        print(f"\n  {feat}  gate AAN als {'>=' if side=='hoog' else '<='} {thr:.3f}")
        for sym, df in dfs.items():
            r = eval_gate(df, feat, thr, side)
            print(f"    {sym:7} win behouden={r['keep']*100:5.1f}%  verlies voorkomen={r['drop']*100:5.1f}%  "
                  f"Σpl {r['spl0']:.1f}→{r['spl1']:.1f} ({r['spl1']-r['spl0']:+.1f})  "
                  f"verliezers {r['verl0']}→{r['verl1']}")
