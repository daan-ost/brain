#!/usr/bin/env python3
"""
PRECISION sweep — attack 'too many bad trades'.

Among the rule-FIRES, GOOD = in_good_period=1 (the fire is a real promising entry), BAD =
in_good_period=0 (fired but not promising). For each (indicator, lookback, metric) we take
the good fires' band [p5,p95] and ask: how many BAD fires fall OUTSIDE it (droppable) while
keeping the GOOD ones? A strong, single-feature gate is a candidate rule refinement.

Crucially this is validated OUT-OF-SAMPLE: the band is derived on the first 70% of fires
(by time) and the drop/keep rates are measured on the last 30%. A gate that only works
in-sample is rejected — with ~1000 features and few good fires, spurious bands are the norm.

Usage: precision_gate.py [symbol_id] [rule|all] [top_n]
       reads engine/data/features/*_<sym>_*.parquet (build full mode first)
"""
import glob
import os
import sys

import duckdb
import numpy as np
import pandas as pd

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
RULE = sys.argv[2] if len(sys.argv) > 2 else "all"
TOPN = int(sys.argv[3]) if len(sys.argv) > 3 else 20

HERE = os.path.dirname(os.path.abspath(__file__))
FEAT = os.path.join(HERE, "..", "data", "features", f"features_{SYM}_*.parquet")
CTX = os.path.join(HERE, "..", "data", "features", f"context_{SYM}_*.parquet")
if not glob.glob(FEAT):
    sys.exit(f"no feature parquet for symbol {SYM} — run feature_store.py (full mode) first")

rule_filter = "" if RULE == "all" else f"AND c.rule_fire = {int(RULE)}"
df = duckdb.sql(f"""
    SELECT f.datetime, f.indicator, f.lookback, f.metric, f.value, c.in_good_period
    FROM read_parquet('{FEAT}') f
    JOIN read_parquet('{CTX}') c ON f.symbol=c.symbol AND f.datetime=c.datetime
    WHERE c.rule_fire > 0 {rule_filter}
""").df()

# time split on the fire datetimes
fire_dts = np.sort(df["datetime"].unique())
if len(fire_dts) < 20:
    sys.exit(f"too few fires ({len(fire_dts)}) for symbol {SYM} rule {RULE} to split — pool rules (all).")
cut = fire_dts[int(len(fire_dts) * 0.7)]
df["split"] = np.where(df["datetime"] <= cut, "train", "test")

key = ["indicator", "lookback", "metric"]
rows = []
for k, g in df.groupby(key):
    gtr = g[(g.split == "train") & (g.in_good_period == 1)]["value"]
    if len(gtr) < 8:
        continue
    lo, hi = np.percentile(gtr, 5), np.percentile(gtr, 95)
    te = g[g.split == "test"]
    good_te = te[te.in_good_period == 1]["value"]
    bad_te = te[te.in_good_period == 0]["value"]
    if len(good_te) < 3 or len(bad_te) < 5:
        continue
    good_keep = ((good_te >= lo) & (good_te <= hi)).mean()
    bad_drop = ((bad_te < lo) | (bad_te > hi)).mean()
    rows.append((*k, round(lo, 3), round(hi, 3), round(good_keep, 2), round(bad_drop, 2),
                 len(good_te), len(bad_te)))

res = pd.DataFrame(rows, columns=["indicator", "lookback", "metric", "lo", "hi",
                                  "good_keep_oos", "bad_drop_oos", "n_good_te", "n_bad_te"])
if res.empty:
    sys.exit("no feature had enough good fires to form a band — pool rules or widen the window.")
# want: drop lots of bad while keeping >=80% of good, out-of-sample
res = res[res.good_keep_oos >= 0.8].sort_values("bad_drop_oos", ascending=False).head(TOPN)

ng = int((df[df.split == "train"].in_good_period == 1).sum())
print(f"=== precision_gate — symbol {SYM}, rule {RULE} ===")
print(f"fires: {len(fire_dts)} (train≤{pd.Timestamp(cut).strftime('%Y-%m-%d')} / test after). "
      f"good fires train={ng}. OUT-OF-SAMPLE band = good[p5,p95] from train, measured on test:\n")
print(res.to_string(index=False))
print("\nbad_drop_oos = fraction of TEST bad fires outside the good band (dropped, 0 good lost ideally);")
print("good_keep_oos = fraction of TEST good fires kept. Stack gates with high drop + high keep -> ratio up.")
