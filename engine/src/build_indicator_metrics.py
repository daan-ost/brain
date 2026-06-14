#!/usr/bin/env python3
"""
Fill indicator_metrics (the calculation cache) for a coin: per (datetime, indicator, lookback)
all ~29 window calculations. Scope = every datetime inside a promising period + every trade.
Reads ONLY brain (indicators + coin_periods + coin_fires + coin_rule_settings); writes the brain
table AND a Parquet mirror (engine/data/metrics/). Idempotent per symbol.

Usage: build_indicator_metrics.py [symbol_id ...]   (default: 2525 244)
"""
import bisect
import os
import sys

import duckdb
import pandas as pd

from db import brain
from calc import window_metrics, WINDOW_METRIC_KEYS

SYMS = [int(a) for a in sys.argv[1:]] or [2525, 244]
INDICATORS = ["vzo", "phobos", "obv-x-value", "mfi", "volumeud"]
MAX_LB = 20
COLS = ["trading_symbol_id", "symbol", "datetime", "indicator", "lookback", *WINDOW_METRIC_KEYS]
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "data", "metrics")
os.makedirs(OUT, exist_ok=True)

conn = brain()


def q(sql, args=()):
    with conn.cursor() as c:
        c.execute(sql, args); return c.fetchall()


for SYM in SYMS:
    row = q("SELECT symbol FROM coins WHERE id=%s", (SYM,))
    SYMBOL = row[0]["symbol"] if row else str(SYM)
    mv = q("SELECT min_volume FROM coin_rule_settings WHERE trading_symbol_id=%s AND min_volume IS NOT NULL "
           "ORDER BY min_volume LIMIT 1", (SYM,))
    VOL_BASE = float(mv[0]["min_volume"]) if mv else 1.0

    # per-indicator as-of series (volumeud normalised to relative volume)
    series = {}
    for r in q("SELECT indicator, datetime, value FROM indicators WHERE trading_symbol_id=%s AND value IS NOT NULL "
               "ORDER BY datetime", (SYM,)):
        s = series.setdefault(r["indicator"], {"dt": [], "v": []})
        v = float(r["value"]) / VOL_BASE if r["indicator"] == "volumeud" else float(r["value"])
        s["dt"].append(r["datetime"]); s["v"].append(v)

    vdt = series.get("volumeud", {}).get("dt", [])

    # in-scope datetimes: every volumeud dt inside a promising period + every trade
    dts = set(r["datetime"] for r in q("SELECT datetime FROM coin_fires WHERE trading_symbol_id=%s", (SYM,)))
    for p in q("SELECT period_from, period_to FROM coin_periods WHERE trading_symbol_id=%s", (SYM,)):
        lo = bisect.bisect_left(vdt, p["period_from"]); hi = bisect.bisect_right(vdt, p["period_to"])
        dts.update(vdt[lo:hi])
    dts = sorted(dts)

    def asof(ind, T, n):
        s = series.get(ind)
        if not s:
            return []
        i = bisect.bisect_right(s["dt"], T)
        return s["v"][max(0, i - n):i][::-1]      # newest-first

    rows = []
    for T in dts:
        for ind in INDICATORS:
            w = asof(ind, T, MAX_LB)
            if not w:
                continue
            for n in range(1, MAX_LB + 1):
                m = window_metrics(w[:n])
                if not m:
                    continue
                rows.append((SYM, SYMBOL, T, ind, n, *[m.get(k) for k in WINDOW_METRIC_KEYS]))

    df = pd.DataFrame(rows, columns=COLS)
    # write brain (idempotent) — bulk insert
    with conn.cursor() as c:
        c.execute("DELETE FROM indicator_metrics WHERE trading_symbol_id=%s", (SYM,))
        ph = ",".join(["%s"] * len(COLS))
        ins = f"INSERT INTO indicator_metrics ({','.join('`'+c2+'`' for c2 in COLS)}) VALUES ({ph})"
        c.executemany(ins, rows)
    conn.commit()
    # write Parquet mirror
    fpath = os.path.join(OUT, f"indicator_metrics_{SYM}.parquet")
    duckdb.sql("COPY df TO '%s' (FORMAT PARQUET)" % fpath)
    print(f"{SYMBOL} ({SYM}): {len(dts)} datetimes -> {len(rows):,} rows (brain + {os.path.relpath(fpath, HERE)})")

conn.close()
