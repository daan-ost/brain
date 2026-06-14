#!/usr/bin/env python3
"""
Persist the promising periods + rule-fires for a coin into the brain DB so the
day-navigator screens can browse them. READ from bot_signals, WRITE to brain only
(bot_signals is never written). Idempotent: clears the symbol's rows first.

Usage: persist_to_brain.py [symbol_id] [from] [to] [gap_minutes]
       defaults: 2525  <full>  15
"""
import sys

import pymysql

from config import FORWARD_MINUTES
from promising import PromisingEngine
from cluster_promising import scan_periods, best_entry

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
FROM = sys.argv[2] if len(sys.argv) > 2 else None
TO = sys.argv[3] if len(sys.argv) > 3 else None
GAP = int(sys.argv[4]) if len(sys.argv) > 4 else FORWARD_MINUTES

src = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="bot_signals", cursorclass=pymysql.cursors.DictCursor)
dst = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="brain", cursorclass=pymysql.cursors.DictCursor, autocommit=False)


def rd(sql, args=()):
    with src.cursor() as c:
        c.execute(sql, args); return c.fetchall()


sym_row = rd("SELECT symbol FROM wp_trading_symbols WHERE ID=%s", (SYM,))
SYMBOL = sym_row[0]["symbol"] if sym_row else str(SYM)
_from_sql = FROM or "1970-01-01"
_to_sql = TO or "2099-01-01"
LABEL = f"promising_v1_gap{GAP}"

# --- compute periods ---
eng = PromisingEngine(SYM, "asc", conn=src)
periods, _, _ = scan_periods(eng, FROM, TO, GAP)

# --- clear + insert ---
with dst.cursor() as c:
    c.execute("DELETE FROM coin_fires WHERE trading_symbol_id=%s", (SYM,))
    c.execute("DELETE FROM coin_periods WHERE trading_symbol_id=%s", (SYM,))

spans = []   # (from, to, period_db_id, best_dt)
with dst.cursor() as c:
    for per in periods:
        be = best_entry(per)   # (dt, highest, lowest_10, highest_dt)
        c.execute(
            "INSERT INTO coin_periods (trading_symbol_id, symbol, period_from, period_to, best_entry, "
            "best_upside, best_lowest10, peak_datetime, n_moments, gap_minutes, label_version, created_at, updated_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())",
            (SYM, SYMBOL, per[0][0], per[-1][0], be[0], round(be[1], 3), round(be[2], 3),
             be[3], len(per), GAP, LABEL))
        spans.append((per[0][0], per[-1][0], c.lastrowid))


def period_of(dt):
    for a, b, pid in spans:
        if a <= dt <= b:
            return pid
    return None


# --- fires (recorded legacy trades) ---
trades = rd("SELECT datetime, rule, result, price, profit_loss, selling_date "
            "FROM wp_trading_simulation WHERE trading_symbol_id=%s AND rule IN (20,21,22,23) "
            "AND datetime>=%s AND datetime<%s ORDER BY datetime", (SYM, _from_sql, _to_sql))
with dst.cursor() as c:
    for t in trades:
        pid = period_of(t["datetime"])
        c.execute(
            "INSERT INTO coin_fires (trading_symbol_id, symbol, datetime, rule, result, in_good_period, "
            "period_id, profit_loss, buy_price, selling_datetime, created_at, updated_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())",
            (SYM, SYMBOL, t["datetime"], int(t["rule"]),
             int(t["result"]) if t["result"] is not None else None,
             1 if pid else 0, pid,
             float(t["profit_loss"]) if t["profit_loss"] is not None else None,
             float(t["price"]) if t["price"] is not None else None,
             t["selling_date"]))

dst.commit()
inside = sum(1 for t in trades if period_of(t["datetime"]))
print(f"=== persist_to_brain — {SYMBOL} ({SYM}) ===")
print(f"periods: {len(periods)}  |  fires: {len(trades)}  "
      f"({inside} inside promising / {len(trades)-inside} outside)")
src.close(); dst.close()
