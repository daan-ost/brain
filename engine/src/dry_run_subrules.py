#!/usr/bin/env python3
"""
DRY RUN — propose, per main rule, candidate NEW subrules that would prevent bad trades while
keeping the good ones. Creates NOTHING. Reports: per rule, the (indicator, calc, lookback, bound,
threshold) conditions ranked by how many BAD trades they drop at zero good-trade loss.

GOOD = executed trade, best_upside >= 3% (the opportunity was real).
BAD  = executed trade, best_upside < 0.5% (slecht — to prevent).
A condition is the good band's edge: lower bound = good_min (drops bad below it) or upper bound =
good_max (drops bad above it); both keep ALL good by construction. Pooled over both coins.

In-sample/descriptive (small good samples are fragile — validate out-of-sample before committing).
Usage: dry_run_subrules.py [min_drop]   (only show conditions dropping >= min_drop bad; default 5)
"""
import glob
import os
import sys

import duckdb
import pandas as pd

from db import brain
from calc import WINDOW_METRIC_KEYS

MIN_DROP = int(sys.argv[1]) if len(sys.argv) > 1 else 5
HERE = os.path.dirname(os.path.abspath(__file__))
GLOB = os.path.join(HERE, "..", "data", "metrics", "indicator_metrics_*.parquet")
if not glob.glob(GLOB):
    sys.exit("no indicator_metrics parquet — run build_indicator_metrics.py first")

# trades (executed) with class
conn = brain()
with conn.cursor() as c:
    c.execute("SELECT trading_symbol_id, datetime, rule, best_upside FROM coin_fires "
              "WHERE is_executed=1 AND best_upside IS NOT NULL")
    trades = pd.DataFrame(c.fetchall())
conn.close()
trades["cls"] = trades["best_upside"].apply(lambda u: "goed" if u >= 3 else ("slecht" if u < 0.5 else "middel"))

calc_cols = list(WINDOW_METRIC_KEYS)
con = duckdb.connect()
con.register("trades", trades)
wide = con.execute(f"""
    SELECT t.rule, t.cls, m.indicator, m.lookback, {','.join('m.'+c for c in calc_cols)}
    FROM read_parquet('{GLOB}') m
    JOIN trades t ON m.trading_symbol_id=t.trading_symbol_id AND m.datetime=t.datetime
""").df()

long = wide.melt(id_vars=["rule", "cls", "indicator", "lookback"], value_vars=calc_cols,
                 var_name="calc", value_name="value").dropna(subset=["value"])

for rule in (20, 21, 22, 23):
    sub = long[long.rule == rule]
    ng = sub[sub.cls == "goed"]["value"].groupby([sub.indicator, sub.lookback, sub.calc]).size()
    rows = []
    for (ind, lb, calc), g in sub.groupby(["indicator", "lookback", "calc"]):
        good = g[g.cls == "goed"]["value"]
        bad = g[g.cls == "slecht"]["value"]
        if len(good) < 5 or len(bad) < 5:
            continue
        # Principle 2 (brain-rule-tuning): place the threshold at the BAD EDGE (in the gap),
        # not the good edge — leave a buffer for future good trades.
        gmin, gmax = good.min(), good.max()
        bad_below = bad[bad < gmin]                  # exclusion zone for a lower bound
        bad_above = bad[bad > gmax]                  # exclusion zone for an upper bound
        drop_low = int((bad < bad_below.max()).sum()) if len(bad_below) else 0   # b_min at bad edge
        drop_high = int((bad > bad_above.min()).sum()) if len(bad_above) else 0  # b_max at bad edge
        if drop_low == 0 and drop_high == 0:
            continue
        if drop_low >= drop_high:
            rows.append((ind, calc, lb, "lower", round(float(bad_below.max()), 3), drop_low, len(good), len(bad)))
        else:
            rows.append((ind, calc, lb, "upper", round(float(bad_above.min()), 3), drop_high, len(good), len(bad)))
    res = pd.DataFrame(rows, columns=["indicator", "calc", "lookback", "bound", "threshold",
                                      "bad_prevented", "n_good", "n_bad"])
    print(f"\n===== RULE {rule} =====")
    if res.empty:
        print("  too few good/bad trades to analyse"); continue
    ng_tot = res.n_good.max(); nb_tot = res.n_bad.max()
    print(f"  good (best_upside>=3%)={ng_tot}  bad (best_upside<0.5%)={nb_tot}")
    res = res[res.bad_prevented >= MIN_DROP].sort_values("bad_prevented", ascending=False).head(12)
    if res.empty:
        print(f"  geen enkele conditie dropt >= {MIN_DROP} slechte (0 goede verloren)"); continue
    print(res.to_string(index=False))

print("\nLees: 'subrule = <calc> van <indicator>, lookback <lb>, <bound>grens <threshold>' "
      "voorkomt <bad_prevented> slechte trades, 0 goede verloren. IN-SAMPLE — out-of-sample toetsen voor invoeren.")
