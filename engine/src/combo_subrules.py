#!/usr/bin/env python3
"""
COMBINATION search for a main rule: find a PAIR of bad-edge subrule conditions that EACH keep the
good trades and TOGETHER drop more bad — for rules where no single safe condition exists (e.g. 20).

Both conditions are AND'd: a trade is kept only if it satisfies BOTH. With bad-edge thresholds each
condition keeps ~100% of good (buffer), so the pair keeps good while dropping the UNION of the bad
each catches. Validated out-of-sample (train 70% / test 30% per coin): the pair's good_keep on
held-out good must stay ~1.0. Non-volumeud only (avoids the volume-normalisation mismatch in the
rule engine). Creates nothing.

Usage: combo_subrules.py [rule] [top_k_candidates] [min_train_drop]   (default 20 60 2)
"""
import glob
import os
import sys

import duckdb
import numpy as np
import pandas as pd

from db import brain
from calc import WINDOW_METRIC_KEYS

RULE = int(sys.argv[1]) if len(sys.argv) > 1 else 20
TOPK = int(sys.argv[2]) if len(sys.argv) > 2 else 60
MIN_DROP = int(sys.argv[3]) if len(sys.argv) > 3 else 2
HERE = os.path.dirname(os.path.abspath(__file__))
GLOB = os.path.join(HERE, "..", "data", "metrics", "indicator_metrics_*.parquet")

conn = brain()
with conn.cursor() as c:
    c.execute("SELECT trading_symbol_id, datetime, rule, best_upside FROM coin_fires "
              "WHERE is_executed=1 AND best_upside IS NOT NULL AND rule=%s", (RULE,))
    tr = pd.DataFrame(c.fetchall())
conn.close()
tr["cls"] = tr["best_upside"].apply(lambda u: "goed" if u >= 3 else ("slecht" if u < 0.5 else "middel"))
tr["split"] = "train"
for sym, g in tr.groupby("trading_symbol_id"):
    cut = g["datetime"].sort_values().iloc[int(len(g) * 0.7)]
    tr.loc[(tr.trading_symbol_id == sym) & (tr.datetime > cut), "split"] = "test"
tr["tid"] = tr["trading_symbol_id"].astype(str) + "|" + tr["datetime"].astype(str)

calc_cols = [k for k in WINDOW_METRIC_KEYS]
con = duckdb.connect(); con.register("tr", tr)
wide = con.execute(f"""
    SELECT t.trading_symbol_id||'|'||t.datetime AS tid, m.indicator, m.lookback,
           {','.join('m.'+c for c in calc_cols)}
    FROM read_parquet('{GLOB}') m JOIN tr t
      ON m.trading_symbol_id=t.trading_symbol_id AND m.datetime=t.datetime
""").df()
long = wide.melt(id_vars=["tid", "indicator", "lookback"], value_vars=calc_cols,
                 var_name="calc", value_name="value").dropna(subset=["value"])
long["feat"] = long.indicator + "|" + long.lookback.astype(str) + "|" + long.calc
mat = long.pivot_table(index="tid", columns="feat", values="value", aggfunc="first")

meta = tr.set_index("tid")[["cls", "split"]].reindex(mat.index)
is_good = (meta.cls == "goed").values
is_bad = (meta.cls == "slecht").values
is_tr = (meta.split == "train").values
is_te = (meta.split == "test").values

# candidate conditions: bad-edge on TRAIN, keep all train-good by construction, non-volumeud
cands = []   # (feat, bound, thr, train_drop, keep_mask_over_all_trades)
for feat in mat.columns:
    if feat.startswith("volumeud|"):
        continue
    v = mat[feat].values
    g_tr = v[is_tr & is_good]; b_tr = v[is_tr & is_bad]
    g_tr = g_tr[~np.isnan(g_tr)]; b_tr = b_tr[~np.isnan(b_tr)]
    if len(g_tr) < 5 or len(b_tr) < 5:
        continue
    gmin, gmax = g_tr.min(), g_tr.max()
    bb = b_tr[b_tr < gmin]; ba = b_tr[b_tr > gmax]
    drop_low = int((b_tr < bb.max()).sum()) if len(bb) else 0
    drop_high = int((b_tr > ba.min()).sum()) if len(ba) else 0
    if max(drop_low, drop_high) < MIN_DROP:
        continue
    if drop_low >= drop_high:
        thr = float(bb.max()); keep = (v >= thr) | np.isnan(v); cands.append((feat, "lower", thr, drop_low, keep))
    else:
        thr = float(ba.min()); keep = (v <= thr) | np.isnan(v); cands.append((feat, "upper", thr, drop_high, keep))

cands.sort(key=lambda x: -x[3])
cands = cands[:TOPK]
ng_te = int((is_te & is_good).sum()); nb_te = int((is_te & is_bad).sum())
print(f"=== combo_subrules — rule {RULE} === {len(cands)} kandidaten · test goed={ng_te} slecht={nb_te}")
if ng_te < 3 or nb_te < 3:
    sys.exit("te weinig test-data")

best = []
for i in range(len(cands)):
    for j in range(i + 1, len(cands)):
        f1, b1, t1, d1, k1 = cands[i]; f2, b2, t2, d2, k2 = cands[j]
        comb = k1 & k2
        gk = float((comb & is_te & is_good).sum() / max((is_te & is_good).sum(), 1))
        bd = int(((~comb) & is_te & is_bad).sum())
        if gk >= 0.99 and bd > 0:
            best.append((gk, bd, f1, t1, f2, t2))
best.sort(key=lambda x: (-x[0], -x[1]))
print(f"\nVEILIGE paren (test good_keep >= 0.99), top 12 — beide condities AND:")
for gk, bd, f1, t1, f2, t2 in best[:12]:
    print(f"  good_keep={gk:.2f}  test_bad_gedropt={bd}/{nb_te}  |  {f1} @ {round(t1,3)}  AND  {f2} @ {round(t2,3)}")
if not best:
    print("  geen veilig paar gevonden")
