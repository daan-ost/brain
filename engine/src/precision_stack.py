#!/usr/bin/env python3
"""
PRECISION stacker — greedily stack single-feature gates until #bad <= #good, honestly
out-of-sample.

Each gate keeps fires whose (indicator,lookback,metric) value sits inside the GOOD fires'
[p5,p95] band (derived on the TRAIN 70%). We greedily add the gate that drops the most BAD
survivors while keeping >=90% of the GOOD survivors — selecting ONLY on train. Then the final
stacked gate set is applied to the held-out TEST 30% and the surviving good/bad ratio is
reported. That test ratio is the honest answer to 'can we reach #bad <= #good?'.

Usage: precision_stack.py [symbol_id] [rule|all] [max_gates]
"""
import glob
import os
import sys

import duckdb
import numpy as np
import pandas as pd

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
RULE = sys.argv[2] if len(sys.argv) > 2 else "all"
MAXG = int(sys.argv[3]) if len(sys.argv) > 3 else 6

HERE = os.path.dirname(os.path.abspath(__file__))
FEAT = os.path.join(HERE, "..", "data", "features", f"features_{SYM}_*.parquet")
CTX = os.path.join(HERE, "..", "data", "features", f"context_{SYM}_*.parquet")
if not glob.glob(FEAT):
    sys.exit(f"no feature parquet for symbol {SYM} — run feature_store.py (full mode) first")

flt = "" if RULE == "all" else f"AND c.rule_fire = {int(RULE)}"
df = duckdb.sql(f"""
    SELECT f.datetime, f.indicator||'|'||f.lookback||'|'||f.metric AS feat, f.value, c.in_good_period
    FROM read_parquet('{FEAT}') f
    JOIN read_parquet('{CTX}') c ON f.symbol=c.symbol AND f.datetime=c.datetime
    WHERE c.rule_fire > 0 {flt}
""").df()

wide = df.pivot_table(index="datetime", columns="feat", values="value", aggfunc="first")
good = df.groupby("datetime")["in_good_period"].first().reindex(wide.index).values.astype(bool)
dts = wide.index.values
order = np.argsort(dts)
wide, good, dts = wide.iloc[order], good[order], dts[order]
cut = int(len(dts) * 0.7)
tr = np.arange(len(dts)) < cut
te = ~tr
if good[tr].sum() < 8 or good[te].sum() < 3 or (~good[te]).sum() < 5:
    sys.exit(f"too few good/bad fires to split for symbol {SYM} rule {RULE}.")

# per-feature band from train-good; pass matrix (NaN -> pass, i.e. don't gate on missing)
feats = wide.columns
bands = {}
passmat = pd.DataFrame(True, index=wide.index, columns=feats)
for f in feats:
    v = wide[f]
    gv = v[tr & good]
    if gv.notna().sum() < 8:
        bands[f] = None
        continue
    lo, hi = np.nanpercentile(gv, 5), np.nanpercentile(gv, 95)
    bands[f] = (lo, hi)
    passmat[f] = v.isna() | ((v >= lo) & (v <= hi))


def stats(mask):
    g = int((mask & good).sum()); b = int((mask & ~good).sum())
    return g, b


chosen = []
surv = np.ones(len(dts), bool)
g0_tr, b0_tr = stats(surv & tr)
print(f"=== precision_stack — symbol {SYM}, rule {RULE} ===")
print(f"start  train good={g0_tr} bad={b0_tr} ratio={g0_tr/max(b0_tr,1):.2f}")
for _ in range(MAXG):
    cur_g, cur_b = stats(surv & tr)
    if cur_b <= cur_g:
        break
    best = None
    for f in feats:
        if bands[f] is None or f in chosen:
            continue
        p = passmat[f].values
        ns = surv & p
        gk, bk = stats(ns & tr)
        if gk < 0.9 * cur_g:                 # keep >=90% of current good (train)
            continue
        dropped = cur_b - bk
        if dropped > 0 and (best is None or dropped > best[1]):
            best = (f, dropped, gk, bk)
    if not best:
        break
    f = best[0]
    chosen.append(f)
    surv &= passmat[f].values
    gk, bk = stats(surv & tr)
    print(f"  + {f:34} train -> good={gk} bad={bk} ratio={gk/max(bk,1):.2f}")

# honest out-of-sample: apply the chosen stack to TEST
te_surv = np.ones(len(dts), bool)
for f in chosen:
    te_surv &= passmat[f].values
gt, bt = stats(te_surv & te)
g0t, b0t = stats(te)
print(f"\nOUT-OF-SAMPLE (test):")
print(f"  before: good={g0t} bad={b0t} ratio={g0t/max(b0t,1):.2f}")
print(f"  after {len(chosen)} gates: good={gt} bad={bt} ratio={gt/max(bt,1):.2f}  "
      f"(kept {gt}/{g0t} good, dropped {b0t-bt}/{b0t} bad)")
print("\ngates:", " AND ".join(chosen) if chosen else "(none)")
