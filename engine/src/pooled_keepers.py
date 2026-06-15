#!/usr/bin/env python3
"""
pooled_keepers — which CALCULATIONS are intrinsically good good/bad discriminators, POOLED over ALL
executed trades (every rule, both coins)? This is the "keeper" lens (Daan's): even if a calc's marginal
engine-gate value is small because other rules already catch those bad, a calc that separates a LARGE
NUMBER of bad across the whole population at ~0 good loss is worth KEEPING for the future (new rules,
new coins, the labeler, ML). This is how skewness was found to be good.

Metric (principle 2, bad-edge, keeps ALL good in-sample):
  pooled_drop  = # bad removed pooled over both coins at the bad-edge threshold (all good kept).
  cohens_d     = good/bad separation.
  cc_drop      = the CROSS-COIN robust count: derive the threshold on one coin, count bad removed on the
                 HELD-OUT coin (min over both directions), with good_keep on the test coin (want ~1.0).
A keeper = high pooled_drop AND a healthy cc_drop at high good_keep (not a one-coin artefact).

Runs BOTH the existing 31 cache calcs (from indicator_metrics via opt_lib, reproduces the skewness
finding as a benchmark) AND the 124 NEW features (new_feat_lib). Read-only, creates nothing.

Usage: pooled_keepers.py [top_n]
"""
import math
import os
import sys

import numpy as np
import pandas as pd

import opt_lib as o
from new_feat_lib import REGISTRY, FAMILIES
from rule_engine import RuleEngine

TOPN = int(sys.argv[1]) if len(sys.argv) > 1 else 30
HERE = os.path.dirname(os.path.abspath(__file__))


def cohens_d(good, bad):
    g, b = np.asarray(good, float), np.asarray(bad, float)
    if len(g) < 2 or len(b) < 2:
        return 0.0
    sg, sb = g.std(ddof=1), b.std(ddof=1)
    p = math.sqrt(((len(g) - 1) * sg ** 2 + (len(b) - 1) * sb ** 2) / (len(g) + len(b) - 2))
    return abs(g.mean() - b.mean()) / p if p else 0.0


def _passes(v, bound, thr):
    v = np.asarray(v, float)
    return (v >= thr) if bound == "lower" else (v <= thr)


def score_pooled(gD, bD, gN, bN):
    """Given good/bad value arrays per coin, return the best bad-edge condition pooled + cross-coin.
    Returns None if no in-sample drop exists."""
    gAll = np.concatenate([gD, gN]) if len(gD) or len(gN) else np.array([])
    bAll = np.concatenate([bD, bN]) if len(bD) or len(bN) else np.array([])
    if len(gAll) < 5 or len(bAll) < 5:
        return None
    conds = o.bad_edge_conditions(gAll, bAll)
    if not conds:
        return None
    best = max(conds, key=lambda c: c["drop_insample"])
    bound, thr = best["bound"], best["threshold"]

    # cross-coin: derive on one coin, count bad removed on the held-out coin + good_keep there
    def cc(train_g, train_b, test_g, test_b):
        if len(train_g) < 5 or len(train_b) < 5 or len(test_g) < 3 or len(test_b) < 3:
            return None
        tc = {c["bound"]: c for c in o.bad_edge_conditions(train_g, train_b)}
        if bound not in tc:
            return None
        t = tc[bound]["threshold"]
        n_drop = int((~_passes(test_b, bound, t)).sum())
        gk = float(_passes(test_g, bound, t).mean())
        return n_drop, round(gk, 3)

    d2n = cc(gD, bD, gN, bN)     # derive DOGEAI, count on NOS
    n2d = cc(gN, bN, gD, bD)     # derive NOS, count on DOGEAI
    cc_drops = [x[0] for x in (d2n, n2d) if x]
    cc_gks = [x[1] for x in (d2n, n2d) if x]
    return {
        "pooled_drop": best["drop_insample"], "bound": bound, "threshold": round(thr, 5),
        "cohens_d": round(cohens_d(gAll, bAll), 3),
        "n_good": [int(len(gD)), int(len(gN))], "n_bad": [int(len(bD)), int(len(bN))],
        "cc_drop_min": min(cc_drops) if cc_drops else None,
        "cc_drop_d2n": d2n[0] if d2n else None, "cc_gk_d2n": d2n[1] if d2n else None,
        "cc_drop_n2d": n2d[0] if n2d else None, "cc_gk_n2d": n2d[1] if n2d else None,
        "cc_good_keep_min": min(cc_gks) if cc_gks else None,
    }


def new_feature_table():
    trades = o.load_trades()
    trades = trades[trades["cls"].isin(["goed", "slecht"])]
    # compute each feature at each trade, per coin
    vals = {n: {2525: {"goed": [], "slecht": []}, 244: {"goed": [], "slecht": []}} for n in REGISTRY}
    for sym, g in trades.groupby("sym"):
        eng = RuleEngine(int(sym))
        for _, tr in g.iterrows():
            for n, (fam, fn) in REGISTRY.items():
                v = fn(eng, tr["datetime"])
                if v is None:
                    continue
                v = float(v)
                if math.isnan(v) or math.isinf(v):
                    continue
                vals[n][int(sym)][tr["cls"]].append(v)
        eng.close()
    rows = []
    for n, (fam, _) in REGISTRY.items():
        s = score_pooled(np.array(vals[n][2525]["goed"]), np.array(vals[n][2525]["slecht"]),
                         np.array(vals[n][244]["goed"]), np.array(vals[n][244]["slecht"]))
        if s:
            rows.append({"calc": n, "family": fam, "kind": "NEW", **s})
    return pd.DataFrame(rows)


def existing_calc_table():
    """Pooled over all trades, per (indicator, lookback, calc) from the indicator_metrics cache."""
    long = o.load_long()
    long = long[long["cls"].isin(["goed", "slecht"])]
    rows = []
    for (ind, lb, calc), g in long.groupby(["indicator", "lookback", "calc"]):
        gD = g[(g["sym"] == 2525) & (g["cls"] == "goed")]["value"].values
        bD = g[(g["sym"] == 2525) & (g["cls"] == "slecht")]["value"].values
        gN = g[(g["sym"] == 244) & (g["cls"] == "goed")]["value"].values
        bN = g[(g["sym"] == 244) & (g["cls"] == "slecht")]["value"].values
        s = score_pooled(gD, bD, gN, bN)
        if s:
            unsafe = o.scale_unsafe(ind, calc)
            rows.append({"calc": f"{ind}/{calc}/lb{int(lb)}", "family": "EXISTING-31",
                         "kind": "OLD" + ("*" if unsafe else ""), **s})
    return pd.DataFrame(rows)


def _print(df, title, n):
    print(f"\n=== {title} ===")
    print(f"{'calc':40} {'kind':5} {'bnd':5} {'pool':>4} {'ccMin':>5} {'d':>5} {'ccGK':>5}  bad D/N | d2n n2d")
    for _, r in df.head(n).iterrows():
        ccm = r["cc_drop_min"]; ccm = "  - " if ccm is None else f"{ccm:>4}"
        gk = r["cc_good_keep_min"]; gk = "  -  " if gk is None else f"{gk:.2f}"
        d2n = f"{r['cc_drop_d2n']}@{r['cc_gk_d2n']}" if r['cc_drop_d2n'] is not None else "-"
        n2d = f"{r['cc_drop_n2d']}@{r['cc_gk_n2d']}" if r['cc_drop_n2d'] is not None else "-"
        print(f"{r['calc']:40} {r['kind']:5} {r['bound']:5} {r['pooled_drop']:>4} {ccm:>5} "
              f"{r['cohens_d']:>5.2f} {gk:>5}  {r['n_bad'][0]}/{r['n_bad'][1]} | {d2n} {n2d}")


if __name__ == "__main__":
    new = new_feature_table()
    old = existing_calc_table()
    comb = pd.concat([new, old], ignore_index=True)
    # rank by CROSS-COIN robust drop first (the real keeper signal), then pooled drop, then d
    comb["cc_sort"] = comb["cc_drop_min"].fillna(-1)
    comb = comb.sort_values(["cc_sort", "pooled_drop", "cohens_d"], ascending=False)

    print("Pooled keeper analysis — bad separated across ALL executed trades (both coins), all good kept.")
    print("pool=in-sample pooled bad dropped; ccMin=cross-coin robust count (min of derive-D-count-N / "
          "derive-N-count-D); d=Cohen's d; ccGK=min cross-coin good_keep (~1.0=keeps good). kind OLD*=volumeud "
          "level metric (cache-relative; discriminator OK but engine-scale-unsafe).")

    _print(comb, f"TOP {TOPN} keepers — NEW + EXISTING combined, by cross-coin robust bad-count", TOPN)
    _print(new.sort_values(["pooled_drop", "cohens_d"], ascending=False), "NEW features by POOLED bad-count", 20)
    _print(old.sort_values(["pooled_drop", "cohens_d"], ascending=False), "EXISTING 31 calcs by POOLED bad-count (benchmark: where's skewness?)", 20)

    # per-calc-FAMILY aggregate for NEW: which base calc TYPE is consistently strong (the 'skewness is good' insight)
    print("\n=== NEW feature base-calc aggregate (max over lookbacks) — which calc TYPE is a keeper? ===")
    new2 = new.copy()
    new2["base"] = new2["calc"].str.replace(r"_lb\d+$", "", regex=True).str.replace(r"_\d+_\d+$", "", regex=True)
    agg = new2.groupby("base").agg(max_pool=("pooled_drop", "max"), max_ccmin=("cc_drop_min", "max"),
                                   max_d=("cohens_d", "max"), n=("calc", "count")).reset_index()
    agg = agg.sort_values(["max_ccmin", "max_pool", "max_d"], ascending=False)
    print(f"{'base calc':34} {'maxPool':>7} {'maxCcMin':>8} {'maxD':>5} {'#lb':>4}")
    for _, r in agg.head(25).iterrows():
        cc = "  -" if pd.isna(r["max_ccmin"]) else f"{int(r['max_ccmin'])}"
        print(f"{r['base']:34} {int(r['max_pool']):>7} {cc:>8} {r['max_d']:>5.2f} {int(r['n']):>4}")

    outp = os.path.join(HERE, "..", "out", "opt", "pooled_keepers.json")
    comb.drop(columns=["cc_sort"]).to_json(outp, orient="records", indent=2)
    print(f"\n-> {os.path.relpath(outp, HERE)}")
    g_tot = comb.iloc[0]["n_good"]; b_tot = comb.iloc[0]["n_bad"]
    print(f"population: good {sum(g_tot)} (D {g_tot[0]}/N {g_tot[1]}), bad {sum(b_tot)} (D {b_tot[0]}/N {b_tot[1]})")
