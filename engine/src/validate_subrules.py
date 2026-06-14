#!/usr/bin/env python3
"""
SAFETY VALIDATION for the dry-run subrule candidates: do GOOD trades stay?

The dry-run band keeps 100% of good IN-SAMPLE by construction. The real question: does a band
fit on past good trades still contain FUTURE good trades? We derive each candidate's band edge on
the first 70% of trades (per coin, by time) and measure on the held-out 30%:
  - good_keep  = fraction of held-out GOOD trades that still satisfy the condition (must stay high)
  - bad_drop   = fraction of held-out BAD trades the condition drops (the benefit)

A candidate is SAFE only if good_keep ~ 1.0 out-of-sample. Creates nothing.
Usage: validate_subrules.py [min_train_drop]   (default 5)
"""
import glob
import os
import sys

import duckdb
import numpy as np
import pandas as pd

from db import brain
from calc import WINDOW_METRIC_KEYS

MIN_DROP = int(sys.argv[1]) if len(sys.argv) > 1 else 5
HERE = os.path.dirname(os.path.abspath(__file__))
GLOB = os.path.join(HERE, "..", "data", "metrics", "indicator_metrics_*.parquet")

conn = brain()
with conn.cursor() as c:
    c.execute("SELECT trading_symbol_id, datetime, rule, best_upside FROM coin_fires "
              "WHERE is_executed=1 AND best_upside IS NOT NULL")
    trades = pd.DataFrame(c.fetchall())
conn.close()
trades["cls"] = trades["best_upside"].apply(lambda u: "goed" if u >= 3 else ("slecht" if u < 0.5 else "middel"))
# per-coin time split: first 70% train, last 30% test
trades["split"] = "train"
for sym, g in trades.groupby("trading_symbol_id"):
    cut = g["datetime"].sort_values().iloc[int(len(g) * 0.7)]
    trades.loc[(trades.trading_symbol_id == sym) & (trades.datetime > cut), "split"] = "test"

calc_cols = list(WINDOW_METRIC_KEYS)
con = duckdb.connect()
con.register("trades", trades)
wide = con.execute(f"""
    SELECT t.rule, t.cls, t.split, m.indicator, m.lookback, {','.join('m.'+c for c in calc_cols)}
    FROM read_parquet('{GLOB}') m
    JOIN trades t ON m.trading_symbol_id=t.trading_symbol_id AND m.datetime=t.datetime
""").df()
long = wide.melt(id_vars=["rule", "cls", "split", "indicator", "lookback"], value_vars=calc_cols,
                 var_name="calc", value_name="value").dropna(subset=["value"])

for rule in (20, 21, 22, 23):
    sub = long[long.rule == rule]
    rows = []
    for (ind, lb, calc), g in sub.groupby(["indicator", "lookback", "calc"]):
        tr_good = g[(g.split == "train") & (g.cls == "goed")]["value"]
        tr_bad = g[(g.split == "train") & (g.cls == "slecht")]["value"]
        te_good = g[(g.split == "test") & (g.cls == "goed")]["value"]
        te_bad = g[(g.split == "test") & (g.cls == "slecht")]["value"]
        if len(tr_good) < 5 or len(tr_bad) < 5 or len(te_good) < 3 or len(te_bad) < 3:
            continue
        gmin, gmax = tr_good.min(), tr_good.max()
        # pick bound by TRAIN bad drop (same rule as the dry run)
        if int((tr_bad < gmin).sum()) >= int((tr_bad > gmax).sum()):
            bound, thr = "lower", gmin
            tr_drop = int((tr_bad < gmin).sum())
            good_keep = float((te_good >= gmin).mean()); bad_drop = float((te_bad < gmin).mean())
        else:
            bound, thr = "upper", gmax
            tr_drop = int((tr_bad > gmax).sum())
            good_keep = float((te_good <= gmax).mean()); bad_drop = float((te_bad > gmax).mean())
        if tr_drop < MIN_DROP:
            continue
        rows.append((ind, calc, lb, bound, round(thr, 3), tr_drop,
                     round(good_keep, 2), round(bad_drop, 2), len(te_good), len(te_bad)))
    res = pd.DataFrame(rows, columns=["indicator", "calc", "lookback", "bound", "threshold",
                                      "train_drop", "test_good_keep", "test_bad_drop", "n_te_good", "n_te_bad"])
    print(f"\n===== RULE {rule} =====")
    if res.empty:
        print(f"  geen kandidaten met train_drop >= {MIN_DROP} en genoeg test-data"); continue
    # rank by SAFETY (good_keep) then benefit
    res = res.sort_values(["test_good_keep", "test_bad_drop"], ascending=False).head(12)
    print(res.to_string(index=False))

print("\ntest_good_keep = fractie vastgehouden GOEDE trades die de conditie OVERLEEFT (moet ~1.00).")
print("test_bad_drop  = fractie vastgehouden SLECHTE trades die de conditie dropt (de winst).")
print("VEILIG = test_good_keep ~ 1.00. Lager => de conditie kilt goede trades op ongeziene data.")
