#!/usr/bin/env python3
"""
EPIC B — the per-rule lookback feature store (store of record = Parquet).

For every in-scope datetime, for every indicator, for lookback 1..20, compute the full
window_metrics set and persist it long/tidy:
    (symbol, datetime, indicator, lookback, metric, value)
plus a per-datetime context table:
    (symbol, datetime, in_good_period, period_upside, rule_fire, result)

In-scope datetimes = every volumeud datetime inside a promising period (the good ground
truth) UNION every legacy rule-fire (20/21/22/23). Compute once, query fast.

Leak-free: lookback values at T use only indicator rows with datetime <= T (as-of).
READ-ONLY on bot_signals. Writes Parquet to engine/data/features/.

Usage: feature_store.py [symbol_id] [from] [to] [gap_minutes] [mode]
       mode: full (good periods + fires, default) | fires (labeled fires only, fast — for the
             good/bad separation sweep over the whole label history)
       defaults: 2525  <full>  15  full
"""
import bisect
import os
import sys
from datetime import timedelta

import duckdb
import pandas as pd
import pymysql

from calc import window_metrics
from promising import PromisingEngine
from cluster_promising import scan_periods, best_entry

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
FROM = sys.argv[2] if len(sys.argv) > 2 else None
TO = sys.argv[3] if len(sys.argv) > 3 else None
GAP = int(sys.argv[4]) if len(sys.argv) > 4 else 15
MODE = sys.argv[5] if len(sys.argv) > 5 else "full"
INDICATORS = ["vzo", "phobos", "obv-x-value", "mfi", "volumeud"]
MAX_LOOKBACK = 20
METRICS = ["first_value", "last_value", "diff_previous_number", "max_diff_number",
           "lowest_value", "highest_value", "range_percentage",
           "standard_deviation", "volatility", "skewness"]

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "data", "features")
os.makedirs(OUT, exist_ok=True)

src = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="bot_signals", cursorclass=pymysql.cursors.DictCursor)


def sq(sql, args):
    with src.cursor() as c:
        c.execute(sql, args); return c.fetchall()


_from_sql = FROM or "1970-01-01"
_to_sql = TO or "2099-01-01"

# Volume baseline: vzo/phobos/mfi/obv-x-value are bounded (~-130..130 / 0..100), but volumeud
# is an UNBOUNDED, coin-dependent raw number (DOGEAI ±1.6M vs NOS ±56k). Absolute volume metrics
# don't transfer across coins and drift with the absolute level. So we normalize volumeud to
# RELATIVE volume = value / min_volume (the legacy baseline, exactly check_volumeud_3's rel_vol).
# Scale-free metrics (volatility, range_percentage, skewness) are unchanged by this; only the
# magnitude metrics (first/last/lowest/highest/std/max_diff) become coin-comparable multiples.
import json as _json
_mv = sq("SELECT settings FROM wp_trading_symbols_rule WHERE trading_symbol_id=%s "
         "AND rule_id IN (20,21,22,23) ORDER BY rule_id LIMIT 1", (SYM,))
VOL_BASE = float(_json.loads(_mv[0]["settings"]).get("min_volume", 1)) if _mv else 1.0
if VOL_BASE <= 0:
    VOL_BASE = 1.0

# per-indicator as-of series (+6h margin for lookback)
series = {}
for r in sq("SELECT indicator, datetime, value FROM wp_trading_indicator "
            "WHERE trading_symbol_id=%s AND datetime>=DATE_SUB(%s, INTERVAL 6 HOUR) AND datetime<%s "
            "AND value IS NOT NULL ORDER BY datetime", (SYM, _from_sql, _to_sql)):
    s = series.setdefault(r["indicator"], {"dt": [], "v": []})
    val = float(r["value"])
    if r["indicator"] == "volumeud":
        val = val / VOL_BASE          # -> relative volume (coin-comparable)
    s["dt"].append(r["datetime"]); s["v"].append(val)
print(f"volume baseline (min_volume) = {VOL_BASE:g} — volumeud stored as relative volume")


def asof_window(ind, T, n):
    """The n most recent values of `ind` with datetime <= T, newest-first."""
    s = series.get(ind)
    if not s:
        return []
    i = bisect.bisect_right(s["dt"], T)
    lo = max(0, i - n)
    return s["v"][lo:i][::-1]


# ---- in-scope datetimes + context ----
ctx = {}   # datetime -> context dict
if MODE == "full":
    eng = PromisingEngine(SYM, "asc", conn=src)
    periods, _, _ = scan_periods(eng, FROM, TO, GAP)
    vdt = eng.DT
    for per in periods:
        upside = best_entry(per)[1]
        a, b = per[0][0], per[-1][0]
        lo = bisect.bisect_left(vdt, a); hi = bisect.bisect_right(vdt, b)
        for j in range(lo, hi):
            T = vdt[j]
            c = ctx.setdefault(T, dict(in_good_period=0, period_upside=None, rule_fire=0, result=0))
            c["in_good_period"] = 1
            c["period_upside"] = float(upside)

for t in sq("SELECT datetime, rule, result FROM wp_trading_simulation "
            "WHERE trading_symbol_id=%s AND rule IN (20,21,22,23) AND datetime>=%s AND datetime<%s",
            (SYM, _from_sql, _to_sql)):
    c = ctx.setdefault(t["datetime"], dict(in_good_period=0, period_upside=None, rule_fire=0, result=0))
    c["rule_fire"] = int(t["rule"])
    c["result"] = int(t["result"]) if t["result"] is not None else 0

datetimes = sorted(ctx)
print(f"=== feature_store — symbol {SYM}, {FROM or 'start'}..{TO or 'end'} ===")
print(f"in-scope datetimes: {len(datetimes)}  "
      f"({sum(c['in_good_period'] for c in ctx.values())} in good periods, "
      f"{sum(c['rule_fire']>0 for c in ctx.values())} rule-fires)")

# ---- compute features (long) ----
rows = []
for T in datetimes:
    for ind in INDICATORS:
        w20 = asof_window(ind, T, MAX_LOOKBACK)
        if not w20:
            continue
        for n in range(1, MAX_LOOKBACK + 1):
            m = window_metrics(w20[:n])
            if not m:
                continue
            for metric in METRICS:
                val = m.get(metric)
                if val is not None:
                    rows.append((SYM, T, ind, n, metric, float(val)))

feat = pd.DataFrame(rows, columns=["symbol", "datetime", "indicator", "lookback", "metric", "value"])
context = pd.DataFrame(
    [(SYM, T, c["in_good_period"], c["period_upside"], c["rule_fire"], c["result"])
     for T, c in sorted(ctx.items())],
    columns=["symbol", "datetime", "in_good_period", "period_upside", "rule_fire", "result"])

tag = (FROM or "start").replace(" ", "_").replace(":", "")[:10]
fpath = os.path.join(OUT, f"features_{SYM}_{tag}.parquet")
cpath = os.path.join(OUT, f"context_{SYM}_{tag}.parquet")
duckdb.sql("COPY feat TO '%s' (FORMAT PARQUET)" % fpath)
duckdb.sql("COPY context TO '%s' (FORMAT PARQUET)" % cpath)
print(f"wrote {len(feat):,} feature rows -> {os.path.relpath(fpath, HERE)}")
print(f"wrote {len(context):,} context rows -> {os.path.relpath(cpath, HERE)}")

# ---- demo: the owner's example query ----
print("\n--- example query: per-lookback range of a metric, good vs bad ---")
q = f"""
SELECT f.indicator, f.lookback, f.metric,
       MIN(f.value) AS lo, MAX(f.value) AS hi, AVG(f.value) AS avg, COUNT(*) AS n
FROM '{fpath}' f JOIN '{cpath}' c ON f.datetime=c.datetime
WHERE f.indicator='vzo' AND f.lookback=15 AND f.metric='skewness' AND c.in_good_period=1
GROUP BY 1,2,3
"""
print("good moments, vzo, lookback 15, skewness:")
print(duckdb.sql(q).df().to_string(index=False))

q2 = f"""
SELECT CASE WHEN c.result=1 THEN 'goed' WHEN c.result=3 THEN 'slecht' ELSE 'overig' END AS label,
       MIN(f.value) lo, MAX(f.value) hi, ROUND(AVG(f.value),4) avg, COUNT(*) n
FROM '{fpath}' f JOIN '{cpath}' c ON f.datetime=c.datetime
WHERE f.indicator='vzo' AND f.lookback=15 AND f.metric='skewness' AND c.rule_fire>0
GROUP BY 1 ORDER BY 1
"""
print("\nrule-fires, vzo lookback 15 skewness — good vs bad separation:")
print(duckdb.sql(q2).df().to_string(index=False))
src.close()
