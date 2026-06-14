#!/usr/bin/env python3
"""
Persist the promising periods + rule-fires for a coin into the brain DB so the
day-navigator screens can browse them. READ from bot_signals, WRITE to brain only
(bot_signals is never written). Idempotent: clears the symbol's rows first.

Usage: persist_to_brain.py [symbol_id] [from] [to] [gap_minutes]
       defaults: 2525  <full>  15
"""
import sys

from config import CLUSTER_GAP_MINUTES
from db import brain, legacy
from promising import PromisingEngine
from cluster_promising import scan_periods, best_entry

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
FROM = sys.argv[2] if len(sys.argv) > 2 else None
TO = sys.argv[3] if len(sys.argv) > 3 else None
GAP = int(sys.argv[4]) if len(sys.argv) > 4 else CLUSTER_GAP_MINUTES

# brain = our store (write). legacy = OFFLINE reference only (the recorded trades + result label).
src = legacy()
dst = brain()
dst.autocommit(False)


def rd(sql, args=()):
    with src.cursor() as c:
        c.execute(sql, args); return c.fetchall()


_coin = None
with dst.cursor() as c:
    c.execute("SELECT symbol FROM coins WHERE id=%s", (SYM,))
    _coin = c.fetchone()
SYMBOL = _coin["symbol"] if _coin else str(SYM)
_from_sql = FROM or "1970-01-01"
_to_sql = TO or "2099-01-01"
LABEL = f"promising_v1_gap{GAP}"

# --- compute periods (promising reads brain.indicators) ---
eng = PromisingEngine(SYM, "asc")
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
# in_good_period = the PER-FIRE promising verdict (is THIS exact moment a good entry with
# upside ahead within the hour?), NOT mere membership of a wide period span. A fire after the
# peak (bought too late, on the way down) gets verdict='' -> not good, matching its bad result.
trades = rd("SELECT datetime, rule, result, price, profit_loss, selling_date "
            "FROM wp_trading_simulation WHERE trading_symbol_id=%s AND rule IN (20,21,22,23) "
            "AND datetime>=%s AND datetime<%s ORDER BY datetime", (SYM, _from_sql, _to_sql))
good_count = 0
with dst.cursor() as c:
    for t in trades:
        pid = period_of(t["datetime"])                    # containing period (for the chart overlay)
        p = eng.promising(t["datetime"])
        good = 1 if (p and p["verdict"] == "buy") else 0   # the per-fire good/bad label
        good_count += good
        c.execute(
            "INSERT INTO coin_fires (trading_symbol_id, symbol, datetime, rule, result, in_good_period, "
            "period_id, profit_loss, buy_price, selling_datetime, created_at, updated_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())",
            (SYM, SYMBOL, t["datetime"], int(t["rule"]),
             int(t["result"]) if t["result"] is not None else None,
             good, pid,
             float(t["profit_loss"]) if t["profit_loss"] is not None else None,
             float(t["price"]) if t["price"] is not None else None,
             t["selling_date"]))

dst.commit()
inside = good_count
print(f"=== persist_to_brain — {SYMBOL} ({SYM}) ===")
print(f"periods: {len(periods)}  |  fires: {len(trades)}  "
      f"({inside} inside promising / {len(trades)-inside} outside)")
src.close(); dst.close()
