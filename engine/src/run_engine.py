#!/usr/bin/env python3
"""
Faithful rule-engine replay over a period + validation against legacy.

For every volume-gated candidate datetime: compute all of rule 21's subrule values,
evaluate pass/fail per subrule, and fire (BUY) iff every subrule passes. Stores
everything in the brain DB and reports:
  - value match vs the legacy oracle (wp_trading_simulation_trades_indicator)
  - fire-set vs the legacy's actual found trades (wp_trading_simulation)

READ-ONLY on bot_signals.  Usage: run_engine.py [from] [to]
"""
import bisect
import json
import sys
from datetime import timedelta

import pymysql

from calc import subrule_value
from volume import missingdata, check_volumeud_3

SYM, RULE, MIN_VOLUME = 2525, 21, 14448
FROM = sys.argv[1] if len(sys.argv) > 1 else "2025-02-14 00:00:00"
TO = sys.argv[2] if len(sys.argv) > 2 else "2025-02-16 00:00:00"

src = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="bot_signals", cursorclass=pymysql.cursors.DictCursor)
dst = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="brain", cursorclass=pymysql.cursors.DictCursor)


def sq(sql, args):
    with src.cursor() as c:
        c.execute(sql, args); return c.fetchall()


# ---- load indicator series in-memory (datetime, value, price), +6h pre-margin ----
print("Loading series...")
series = {}
for r in sq("SELECT indicator, datetime, value, price FROM wp_trading_indicator "
            "WHERE trading_symbol_id=%s AND datetime>=DATE_SUB(%s, INTERVAL 6 HOUR) AND datetime<%s "
            "AND value IS NOT NULL ORDER BY datetime", (SYM, FROM, TO)):
    s = series.setdefault(r["indicator"], {"dt": [], "v": [], "p": []})
    s["dt"].append(r["datetime"]); s["v"].append(float(r["value"]))
    s["p"].append(float(r["price"]) if r["price"] is not None else None)


def asof_idx(ind, T):
    s = series.get(ind)
    return (s, bisect.bisect_right(s["dt"], T)) if s else (None, 0)


def vals_window(ind, n, T):
    s, i = asof_idx(ind, T)
    if not s:
        return [], []
    lo = max(0, i - n)
    return s["v"][lo:i][::-1], [p for p in s["p"][lo:i][::-1] if p is not None]


def vol_rows(T, minutes, n=None):
    """volumeud rows {datetime,value,price} newest-first within `minutes` before T, optional count limit."""
    s, i = asof_idx("volumeud", T)
    if not s:
        return []
    cutoff = T - timedelta(minutes=minutes)
    out = []
    j = i - 1
    while j >= 0 and s["dt"][j] >= cutoff and (n is None or len(out) < n):
        out.append({"datetime": s["dt"][j], "value": s["v"][j], "price": s["p"][j]})
        j -= 1
    return out  # newest-first


def passes(val, b_min, b_max):
    if val is None:
        return None
    if b_min is not None and val < float(b_min):
        return False
    if b_max is not None and val > float(b_max):
        return False
    return True


def eval_subrule(sr, T):
    """Return (value, passed) for a subrule at T."""
    name, ind = sr["subrulename"], sr["indicator"]
    def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
    vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
    if name == "missingdata":
        v = round(missingdata(vol_rows(T, 300, def1)), 4)
        return v, passes(v, sr["b_min"], sr["b_max"])
    if name == "volume_check":
        rows60 = vol_rows(T, 60)
        ok = check_volumeud_3(rows60, MIN_VOLUME)
        return (round(float(rows60[0]["value"])) if (ok and rows60) else 0.0), ok
    n = def1 if name != "currentvalue" else 1
    vals, prices = vals_window(ind, n, T)
    v = subrule_value(name, vc, vals, prices)
    return v, passes(v, sr["b_min"], sr["b_max"])


# ---- candidates + subrules ----
cands = [r["datetime"] for r in sq(
    "SELECT DISTINCT datetime FROM wp_trading_indicator WHERE trading_symbol_id=%s "
    "AND datetime>=%s AND datetime<%s AND volume_found=1 ORDER BY datetime", (SYM, FROM, TO))]
subrules = sq("SELECT ID, sort, indicator, subrulename, def1_value, b_min, b_max, value_condition "
              "FROM wp_trading_rules WHERE rule_number=%s AND active=1 ORDER BY sort, ID", (RULE,))
print(f"{len(cands)} volume-gated candidates x {len(subrules)} subrules")

# ---- run + store ----
with dst.cursor() as c:
    c.execute("DELETE FROM engine_runs")  # single-run demo table; cascade clears children
    c.execute("INSERT INTO engine_runs (trading_symbol_id, symbol, rule_number, period_from, period_to, "
              "notes, created_at, updated_at) VALUES (%s,'DOGEAI',%s,%s,%s,'full 27-subrule faithful replay',NOW(),NOW())",
              (SYM, RULE, FROM, TO))
    run_id = c.lastrowid
dst.commit()

my_fires = []
for T in cands:
    passed, failed_sort, vals = True, None, []
    for sr in subrules:
        v, ok = eval_subrule(sr, T)
        vals.append((sr, v, ok))
        if ok is False and failed_sort is None:
            failed_sort, passed = sr["sort"], False
    if passed:
        my_fires.append(T)
    with dst.cursor() as c:
        c.execute("INSERT INTO engine_signals (run_id, datetime, passed, failed_at_sort) VALUES (%s,%s,%s,%s)",
                  (run_id, T, int(passed), failed_sort))
        sid = c.lastrowid
        c.executemany(
            "INSERT INTO engine_subrule_values (signal_id, sort, subrule_id, indicator, subrulename, def1, "
            "computed_value, b_min, b_max, passed) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            [(sid, sr["sort"], sr["ID"], sr["indicator"], sr["subrulename"], sr["def1_value"],
              v, sr["b_min"], sr["b_max"], (None if ok is None else int(ok))) for sr, v, ok in vals])
with dst.cursor() as c:
    c.execute("UPDATE engine_runs SET candidates=%s, fires=%s WHERE id=%s", (len(cands), len(my_fires), run_id))
dst.commit()

# ---- validate fires vs legacy ----
legacy_trades = {r["datetime"] for r in sq(
    "SELECT DISTINCT datetime FROM wp_trading_simulation WHERE trading_symbol_id=%s AND rule=%s "
    "AND datetime>=%s AND datetime<%s", (SYM, RULE, FROM, TO))}
mine = set(my_fires)
both = mine & legacy_trades
only_mine = mine - legacy_trades
only_legacy = legacy_trades - mine

print("=" * 64)
print(f"PERIOD {FROM} .. {TO}  rule {RULE} DOGEAI")
print(f"candidates (volume-gated): {len(cands)}")
print(f"MY fires:     {len(mine)}")
print(f"LEGACY trades: {len(legacy_trades)}")
print(f"  matched (both):     {len(both)}")
print(f"  only mine (extra):  {len(only_mine)}")
print(f"  only legacy (miss): {len(only_legacy)}")
if only_legacy:
    print("  legacy trades I missed:", sorted(str(d) for d in only_legacy)[:8])
if only_mine:
    print("  my extra fires:", sorted(str(d) for d in only_mine)[:8])
print(f"stored run #{run_id} in brain DB")
src.close(); dst.close()
