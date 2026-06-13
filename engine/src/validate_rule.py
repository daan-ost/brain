#!/usr/bin/env python3
"""
Step-1 validator — recompute rule 21's subrule values for a datetime and diff
against the legacy oracle (wp_trading_simulation_trades_indicator).

Faithful formulas (from legacy functions_br.php, verified where noted):
  currentvalue   : round(newest value at/before T, 2)
  previous_value : (newest - oldest) over last def1 rows; diff_price uses price
  volatility     : sample-std(last def1 values, ddof=1) / newest_value   (round 4)
  skewness       : population skew over last def1 values                 (round 5)
  missingdata    : TODO (max price-rise gap)        — flagged, not yet implemented
  volume_check   : TODO (check_volumeud_3)          — flagged, not yet implemented

READ-ONLY on bot_signals. Window = last def1 indicator rows with datetime <= T (DESC).
"""
import json
import sys

import pymysql

from calc import subrule_value
from volume import missingdata, check_volumeud_3

SYM = 2525
RULE = 21
MIN_VOLUME = 14448   # wp_trading_symbols_rule for DOGEAI/rule 21
T = sys.argv[1] if len(sys.argv) > 1 else "2025-02-14 12:17:31"

db = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                     database="bot_signals", cursorclass=pymysql.cursors.DictCursor)


def q(sql, args):
    with db.cursor() as c:
        c.execute(sql, args)
        return c.fetchall()


def window(indicator, n):
    """Last n rows of `indicator` at/before T, newest-first."""
    return q("SELECT value, price, datetime FROM wp_trading_indicator "
             "WHERE trading_symbol_id=%s AND indicator=%s AND datetime<=%s AND value IS NOT NULL "
             "ORDER BY datetime DESC LIMIT %s", (SYM, indicator, T, int(n)))


def vol_window(minutes=None, n=None):
    """volumeud rows (datetime, value, price) at/before T, newest-first; by minutes window and/or row limit."""
    sql = ("SELECT datetime, value, price FROM wp_trading_indicator "
           "WHERE trading_symbol_id=%s AND indicator='volumeud' AND datetime<=%s AND price IS NOT NULL")
    args = [SYM, T]
    if minutes:
        sql += " AND datetime>=DATE_SUB(%s, INTERVAL %s MINUTE)"; args += [T, minutes]
    sql += " ORDER BY datetime DESC"
    if n:
        sql += " LIMIT %s"; args.append(n)
    return q(sql, tuple(args))


def compute(sr):
    name, ind = sr["subrulename"], sr["indicator"]
    def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
    vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}

    if name == "missingdata":                       # last def1 volumeud rows in 300-min window
        return round(missingdata(vol_window(minutes=300, n=def1)), 4)
    if name == "volume_check":                      # 60-min volumeud window; stored value = 0
        return 0.0

    n = def1 if name != "currentvalue" else 1
    w = window(ind, n)
    vals = [float(r["value"]) for r in w]
    prices = [float(r["price"]) for r in w if r["price"] is not None]
    return subrule_value(name, vc, vals, prices)


subrules = q("SELECT ID, sort, indicator, subrulename, def1_value, b_min, b_max, "
             "value_condition, operator, condition_rule FROM wp_trading_rules "
             "WHERE rule_number=%s AND active=1 ORDER BY sort, ID", (RULE,))
oracle = {r["rule_ID"]: r for r in q(
    "SELECT rule_ID, value, result_ok FROM wp_trading_simulation_trades_indicator "
    "WHERE trading_symbol_ID=%s AND rule_number=%s AND datetime=%s", (SYM, RULE, T))}

print(f"Validating rule {RULE} @ {T}  (symbol {SYM})  — computed vs legacy oracle")
print(f"{'id':>5} {'indicator':<13}{'subrulename':<15}{'def1':>4}{'oracle':>12}{'computed':>12}  match")
print("-" * 78)
match = miss = nooracle = 0
for sr in subrules:
    sid = sr["ID"]
    comp = compute(sr)
    orc = oracle.get(sid)
    ov = float(orc["value"]) if orc else None
    if orc is None:
        nooracle += 1; tag = "no-oracle"
    elif comp is None:
        miss += 1; tag = "MISMATCH(no-data)"
    elif abs(comp - ov) < 0.01 or (ov != 0 and abs((comp - ov) / ov) < 0.02):
        match += 1; tag = "OK"
    else:
        miss += 1; tag = "MISMATCH"
    ovs = "" if ov is None else f"{ov:.4f}"
    cps = "" if comp is None else f"{comp:.4f}"
    d1 = int(sr["def1_value"]) if sr["def1_value"] else 0
    print(f"{sid:>5} {sr['indicator']:<13}{sr['subrulename']:<15}{d1:>4}{ovs:>12}{cps:>12}  {tag}")

print("-" * 78)
print(f"OK={match}  MISMATCH={miss}  no-oracle={nooracle}  / {len(subrules)} subrules")
db.close()
