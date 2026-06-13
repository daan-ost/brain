#!/usr/bin/env python3
"""
Populate the redundant feature-store (the "redundant opslaan").

Reads READ-ONLY from bot_signals, computes each rule-21 subrule value for every
volume-gated candidate datetime in a window, and stores them in the brain DB tables
(engine_runs / engine_signals / engine_subrule_values) so the values can be browsed
and tested — instead of recomputing on the fly every time.

Usage: populate_engine.py [from] [to]   (default a 2-day demo window)
"""
import bisect
import json
import sys

import pymysql

from calc import subrule_value

SYM, RULE = 2525, 21
FROM = sys.argv[1] if len(sys.argv) > 1 else "2025-02-14 00:00:00"
TO = sys.argv[2] if len(sys.argv) > 2 else "2025-02-16 00:00:00"

src = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="bot_signals", cursorclass=pymysql.cursors.DictCursor)
dst = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="brain", cursorclass=pymysql.cursors.DictCursor)


def sq(sql, args):
    with src.cursor() as c:
        c.execute(sql, args)
        return c.fetchall()


# 1) load indicator series into per-indicator sorted arrays (+6h pre-margin for lookback)
print("Loading indicator series...")
rows = sq("SELECT indicator, datetime, value, price FROM wp_trading_indicator "
          "WHERE trading_symbol_id=%s AND datetime>=DATE_SUB(%s, INTERVAL 6 HOUR) AND datetime<%s "
          "AND value IS NOT NULL ORDER BY datetime", (SYM, FROM, TO))
series = {}
for r in rows:
    s = series.setdefault(r["indicator"], {"dt": [], "v": [], "p": []})
    s["dt"].append(r["datetime"]); s["v"].append(float(r["value"]))
    s["p"].append(float(r["price"]) if r["price"] is not None else None)


def asof_window(indicator, n, T):
    s = series.get(indicator)
    if not s:
        return [], []
    i = bisect.bisect_right(s["dt"], T)        # count of rows with datetime <= T
    lo = max(0, i - n)
    return s["v"][lo:i][::-1], [p for p in s["p"][lo:i][::-1] if p is not None]  # newest-first


# 2) candidate datetimes (volume gate) + subrules
cands = [r["datetime"] for r in sq(
    "SELECT DISTINCT datetime FROM wp_trading_indicator WHERE trading_symbol_id=%s "
    "AND datetime>=%s AND datetime<%s AND volume_found=1 ORDER BY datetime", (SYM, FROM, TO))]
subrules = sq("SELECT ID, sort, indicator, subrulename, def1_value, b_min, b_max, value_condition "
              "FROM wp_trading_rules WHERE rule_number=%s AND active=1 ORDER BY sort, ID", (RULE,))
print(f"{len(cands)} volume-gated candidates × {len(subrules)} subrules")


def passes(val, b_min, b_max):
    if val is None:
        return None
    if b_min is not None and val < float(b_min):
        return False
    if b_max is not None and val > float(b_max):
        return False
    return True


# 3) compute + store
with dst.cursor() as c:
    c.execute("INSERT INTO engine_runs (trading_symbol_id, symbol, rule_number, period_from, period_to, "
              "notes, created_at, updated_at) VALUES (%s,'DOGEAI',%s,%s,%s,%s,NOW(),NOW())",
              (SYM, RULE, FROM, TO, "Step-1 faithful replay; 20/21 subrules implemented (volumeud/volume_check TODO)"))
    run_id = c.lastrowid
dst.commit()

fires = 0
sig_rows, val_rows = [], []
for T in cands:
    passed, failed_sort, computed = True, None, []
    for sr in subrules:
        name, ind = sr["subrulename"], sr["indicator"]
        vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
        if name in ("missingdata", "volume_check") or (name == "previous_value" and ind == "volumeud"):
            computed.append((sr, None, None)); continue          # not yet implemented → unknown
        n = int(sr["def1_value"]) if (sr["def1_value"] and name != "currentvalue") else 1
        vals, prices = asof_window(ind, n, T)
        val = subrule_value(name, vc, vals, prices)
        ok = passes(val, sr["b_min"], sr["b_max"])
        computed.append((sr, val, ok))
        if ok is False and failed_sort is None:
            failed_sort, passed = sr["sort"], False
    if passed:
        fires += 1
    sig_rows.append((run_id, T, int(passed), failed_sort))
    # store later once we have signal ids — do per-signal insert to get id
    with dst.cursor() as c:
        c.execute("INSERT INTO engine_signals (run_id, datetime, passed, failed_at_sort) VALUES (%s,%s,%s,%s)",
                  (run_id, T, int(passed), failed_sort))
        sid = c.lastrowid
    for sr, val, ok in computed:
        val_rows.append((sid, sr["sort"], sr["ID"], sr["indicator"], sr["subrulename"],
                         sr["def1_value"], val, sr["b_min"], sr["b_max"],
                         (None if ok is None else int(ok))))

with dst.cursor() as c:
    c.executemany("INSERT INTO engine_subrule_values (signal_id, sort, subrule_id, indicator, subrulename, "
                  "def1, computed_value, b_min, b_max, passed) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", val_rows)
    c.execute("UPDATE engine_runs SET candidates=%s, fires=%s WHERE id=%s", (len(cands), fires, run_id))
dst.commit()

print(f"Stored run #{run_id}: {len(cands)} candidates, {fires} provisional fires "
      f"(NOTE: fires not final — volumeud/volume_check/missingdata subrules not yet evaluated).")
print(f"  engine_signals rows: {len(cands)}   engine_subrule_values rows: {len(val_rows)}")
src.close(); dst.close()
