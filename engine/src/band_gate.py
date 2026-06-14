#!/usr/bin/env python3
"""
EPIC (rule tuning) — the band-gate analysis Daan asked for:

  "Per calculation/lookback, what is the min and max over all GOOD trades, and if I set
   the rule on that band, which BAD trades fall away?"

GOOD = promising best-entries (is_best_entry=1).  BAD = rule-fires OUTSIDE any promising
period (rule_fire>0 AND in_good_period=0) — the automatable bad label.

For each (indicator, lookback, metric) it computes the good envelope [min,max] (and a
trimmed [p2.5,p97.5]) and counts how many BAD fires fall outside it = droppable. A gate
that drops many bad while keeping all good is a candidate rule condition / refinement.

Usage: band_gate.py [symbol_id] [top_n]   (reads engine/data/features/*_<sym>_*.parquet)
"""
import glob
import os
import sys

import duckdb
import pandas as pd

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
TOPN = int(sys.argv[2]) if len(sys.argv) > 2 else 25

HERE = os.path.dirname(os.path.abspath(__file__))
FEAT = os.path.join(HERE, "..", "data", "features", f"features_{SYM}_*.parquet")
CTX = os.path.join(HERE, "..", "data", "features", f"context_{SYM}_*.parquet")
if not glob.glob(FEAT):
    sys.exit(f"no feature parquet for symbol {SYM} — run feature_store.py (full mode) first")

con = duckdb.connect()
con.execute(f"CREATE VIEW f AS SELECT * FROM read_parquet('{FEAT}')")
con.execute(f"CREATE VIEW c AS SELECT * FROM read_parquet('{CTX}')")

n_good = con.execute("SELECT COUNT(*) FROM c WHERE is_best_entry=1").fetchone()[0]
n_bad = con.execute("SELECT COUNT(*) FROM c WHERE rule_fire>0 AND in_good_period=0").fetchone()[0]

# good envelope per feature
good = con.execute("""
SELECT f.indicator, f.lookback, f.metric,
       MIN(value) lo, MAX(value) hi,
       QUANTILE_CONT(value,0.025) q_lo, QUANTILE_CONT(value,0.975) q_hi,
       COUNT(*) ng
FROM f JOIN c ON f.datetime=c.datetime WHERE c.is_best_entry=1
GROUP BY 1,2,3
""").df()

# bad values per feature
bad = con.execute("""
SELECT f.indicator, f.lookback, f.metric, f.value
FROM f JOIN c ON f.datetime=c.datetime WHERE c.rule_fire>0 AND c.in_good_period=0
""").df()

key = ["indicator", "lookback", "metric"]
bad = bad.merge(good, on=key, how="inner")
bad["out_strict"] = (bad.value < bad.lo) | (bad.value > bad.hi)
bad["out_trim"] = (bad.value < bad.q_lo) | (bad.value > bad.q_hi)
agg = bad.groupby(key).agg(n_bad=("value", "size"),
                           drop_strict=("out_strict", "sum"),
                           drop_trim=("out_trim", "sum")).reset_index()
agg = agg.merge(good[key + ["lo", "hi", "q_lo", "q_hi"]], on=key)
# trimmed band keeps ~95% of good by construction; report bad-drop at full vs trimmed good-retention
agg["drop%_keepall"] = (agg.drop_strict / agg.n_bad * 100).round(1)
agg["drop%_trim"] = (agg.drop_trim / agg.n_bad * 100).round(1)
agg = agg.sort_values(["drop_strict", "drop_trim"], ascending=False).head(TOPN)

print(f"=== band_gate — symbol {SYM} ===")
print(f"GOOD (promising best-entries): {n_good} | BAD (fires outside promising): {n_bad}\n")
print("Top single-feature gates: good envelope [lo,hi]; how many BAD fall outside it.\n")
show = agg[["indicator", "lookback", "metric", "lo", "hi", "drop_strict", "drop%_keepall", "drop_trim", "drop%_trim"]].copy()
for col in ("lo", "hi"):
    show[col] = show[col].round(3)
show = show.rename(columns={"drop_strict": "bad_drop", "drop_trim": "bad_drop_t"})
print(show.to_string(index=False))
print(f"\nbad_drop = bad fires outside the good [min,max] (0 good lost). "
      f"_t = outside trimmed [p2.5,p97.5] (~5% good lost, more bad dropped).")
print(f"Goal: stack gates until per-rule #bad <= #good. A single gate dropping "
      f"{int(agg.drop_strict.max())}/{n_bad} bad at zero good loss is the best starting filter.")
