#!/usr/bin/env python3
"""
Daan's exact check: PER RULE, PER calculation (window metrics), PER lookback (1..20), take the
STRICT [min, max] over all GOOD trades (result=1) of that rule, then count how many BAD trades
(result=3) of that rule fall OUTSIDE that band. Those are the bad trades that drop out if the
rule is tightened/extended on that (calculation, lookback).

Strict min/max keeps 100% of the good trades by construction; we only ever drop bad trades that
sit outside the good envelope. (Caveat: this is descriptive/in-sample — with few good trades the
band rests on a handful of points; an unseen good trade could fall outside. Use out-of-sample
before committing a change. This check is to SEE the options on what we have.)

Usage: per_rule_band.py [symbol_id] [rule|all] [top_n]
       reads engine/data/features/*_<sym>_*.parquet (full mode)
"""
import glob
import os
import sys

import duckdb
import pandas as pd

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
RULE = sys.argv[2] if len(sys.argv) > 2 else "all"
TOPN = int(sys.argv[3]) if len(sys.argv) > 3 else 12

# window-shape metrics only (exclude the trivial level values ~ "current value")
SHAPE = ("skewness", "volatility", "range_percentage", "standard_deviation",
         "max_diff_number", "diff_previous_number")

HERE = os.path.dirname(os.path.abspath(__file__))
FEAT = os.path.join(HERE, "..", "data", "features", f"features_{SYM}_*.parquet")
CTX = os.path.join(HERE, "..", "data", "features", f"context_{SYM}_*.parquet")
if not glob.glob(FEAT):
    sys.exit(f"no feature parquet for symbol {SYM} — run feature_store.py (full mode) first")

rules = [20, 21, 22, 23] if RULE == "all" else [int(RULE)]
metric_list = "','".join(SHAPE)

for rule in rules:
    df = duckdb.sql(f"""
        SELECT f.indicator, f.lookback, f.metric, f.value, c.result
        FROM read_parquet('{FEAT}') f
        JOIN read_parquet('{CTX}') c ON f.symbol=c.symbol AND f.datetime=c.datetime
        WHERE c.rule_fire = {rule} AND c.result IN (1,3) AND f.metric IN ('{metric_list}')
    """).df()
    ng = df[df.result == 1]["value"].groupby([df.indicator, df.lookback, df.metric]).size()
    n_good = df[df.result == 1].datetime.nunique() if "datetime" in df else df[df.result == 1].drop_duplicates(["indicator", "lookback", "metric"]).shape[0]
    good_n = df[df.result == 1].groupby(["indicator", "lookback", "metric"]).size().max() if not df.empty else 0
    bad_n = df[df.result == 3].groupby(["indicator", "lookback", "metric"]).size().max() if not df.empty else 0

    print(f"\n===== RULE {rule} — symbol {SYM} =====")
    if df.empty or good_n == 0 or bad_n == 0:
        print(f"  too few labeled trades (good={good_n}, bad={bad_n}) — skip")
        continue
    print(f"  good trades (result=1)={int(good_n)}   bad trades (result=3)={int(bad_n)}")

    rows = []
    for k, g in df.groupby(["indicator", "lookback", "metric"]):
        good = g[g.result == 1]["value"]
        bad = g[g.result == 3]["value"]
        if len(good) < 3 or len(bad) < 3:
            continue
        lo, hi = good.min(), good.max()
        outside = ((bad < lo) | (bad > hi)).sum()
        rows.append((*k, round(lo, 3), round(hi, 3), int(outside), len(bad), round(outside / len(bad) * 100, 0)))
    res = pd.DataFrame(rows, columns=["indicator", "lookback", "metric", "good_min", "good_max",
                                      "bad_outside", "bad_n", "drop%"])
    if res.empty:
        print("  no feature with enough good+bad — skip"); continue
    res = res.sort_values(["bad_outside", "drop%"], ascending=False).head(TOPN)
    print(res.to_string(index=False))

print("\nbad_outside = # bad trades (result=3) outside the good [min,max] -> dropped if you tighten")
print("the rule on that (indicator, lookback, metric). 0 good trades are lost (strict envelope).")
