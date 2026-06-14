#!/usr/bin/env python3
"""
EPIC B — query helpers over the Parquet feature store.

The headline question for new-rule discovery: across ALL indicator x lookback(1..20) x
metric combinations, which ones best separate GOOD (result=1) from BAD (result=3) rule
fires? Each strong separator is a candidate new-rule condition / ML feature.

Separation score = Cohen's d = |mean_good - mean_bad| / pooled_std.

Usage: feature_query.py [symbol_id] [top_n]      (reads engine/data/features/*_<sym>_*.parquet)
"""
import glob
import os
import sys

import duckdb

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
TOPN = int(sys.argv[2]) if len(sys.argv) > 2 else 25

HERE = os.path.dirname(os.path.abspath(__file__))
FEAT = os.path.join(HERE, "..", "data", "features", f"features_{SYM}_*.parquet")
CTX = os.path.join(HERE, "..", "data", "features", f"context_{SYM}_*.parquet")
if not glob.glob(FEAT):
    sys.exit(f"no feature parquet for symbol {SYM} — run feature_store.py first")

# per (indicator, lookback, metric): mean/std for good (result=1) and bad (result=3) fires
sql = f"""
WITH j AS (
  SELECT f.indicator, f.lookback, f.metric, f.value, c.result
  FROM read_parquet('{FEAT}') f
  JOIN read_parquet('{CTX}') c ON f.symbol=c.symbol AND f.datetime=c.datetime
  WHERE c.rule_fire > 0 AND c.result IN (1,3)
)
SELECT indicator, lookback, metric,
  AVG(value) FILTER (result=1) AS mean_good,
  AVG(value) FILTER (result=3) AS mean_bad,
  STDDEV_SAMP(value) FILTER (result=1) AS sd_good,
  STDDEV_SAMP(value) FILTER (result=3) AS sd_bad,
  COUNT(*) FILTER (result=1) AS n_good,
  COUNT(*) FILTER (result=3) AS n_bad
FROM j GROUP BY 1,2,3
HAVING n_good >= 5 AND n_bad >= 5
"""
df = duckdb.sql(sql).df()
if df.empty:
    sys.exit("not enough labeled good/bad fires in the store (need >=5 each). Build a wider window.")

import math
def cohens_d(r):
    sg, sb, ng, nb = r.sd_good, r.sd_bad, r.n_good, r.n_bad
    if not sg or not sb or ng < 2 or nb < 2:
        return 0.0
    pooled = math.sqrt(((ng - 1) * sg**2 + (nb - 1) * sb**2) / (ng + nb - 2))
    return abs(r.mean_good - r.mean_bad) / pooled if pooled else 0.0

df["d"] = df.apply(cohens_d, axis=1)
df = df.sort_values("d", ascending=False).head(TOPN)

print(f"=== feature separation (good vs bad fires) — symbol {SYM} ===")
print(f"top {TOPN} of {len(duckdb.sql(sql).df())} (indicator x lookback x metric), by Cohen's d\n")
show = df[["indicator", "lookback", "metric", "mean_good", "mean_bad", "d", "n_good", "n_bad"]].copy()
for col in ("mean_good", "mean_bad", "d"):
    show[col] = show[col].round(4)
print(show.to_string(index=False))
print("\nd >= 0.8 = large separation (candidate new-rule condition); 0.5-0.8 medium; <0.2 noise.")
