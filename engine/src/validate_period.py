#!/usr/bin/env python3
"""
Scaled validation: evaluate my engine at the EXACT datetimes legacy evaluated
(the oracle datetimes), and compare per-subrule value + pass, and the overall
fire verdict. This sidesteps the trade-datetime offset and isolates any diverging
subrule. READ-ONLY on bot_signals.  Usage: validate_period.py [from] [to]
"""
import bisect
import json
import sys
from collections import defaultdict
from datetime import timedelta

import pymysql

from calc import subrule_value
from volume import missingdata, check_volumeud_3

SYM, RULE, MIN_VOLUME = 2525, 21, 14448
FROM = sys.argv[1] if len(sys.argv) > 1 else "2025-02-14 00:00:00"
TO = sys.argv[2] if len(sys.argv) > 2 else "2025-02-21 00:00:00"

src = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="bot_signals", cursorclass=pymysql.cursors.DictCursor)


def sq(sql, args):
    with src.cursor() as c:
        c.execute(sql, args); return c.fetchall()


# in-memory series (+6h margin)
series = {}
for r in sq("SELECT indicator, datetime, value, price FROM wp_trading_indicator "
            "WHERE trading_symbol_id=%s AND datetime>=DATE_SUB(%s, INTERVAL 6 HOUR) AND datetime<%s "
            "AND value IS NOT NULL ORDER BY datetime", (SYM, FROM, TO)):
    s = series.setdefault(r["indicator"], {"dt": [], "v": [], "p": []})
    s["dt"].append(r["datetime"]); s["v"].append(float(r["value"]))
    s["p"].append(float(r["price"]) if r["price"] is not None else None)


def idx(ind, T):
    s = series.get(ind)
    return (s, bisect.bisect_right(s["dt"], T)) if s else (None, 0)


def vals_window(ind, n, T):
    s, i = idx(ind, T)
    if not s:
        return [], []
    lo = max(0, i - n)
    return s["v"][lo:i][::-1], [p for p in s["p"][lo:i][::-1] if p is not None]


def vol_rows(T, minutes, n=None):
    s, i = idx("volumeud", T)
    if not s:
        return []
    cut = T - timedelta(minutes=minutes); out = []; j = i - 1
    while j >= 0 and s["dt"][j] >= cut and (n is None or len(out) < n):
        out.append({"datetime": s["dt"][j], "value": s["v"][j], "price": s["p"][j]}); j -= 1
    return out


def passes(v, lo, hi):
    if v is None:
        return None
    # calc.py already rounds each value to its legacy precision; compare as-is (no over-rounding).
    if lo is not None and v < float(lo):
        return False
    if hi is not None and v > float(hi):
        return False
    return True


def eval_value(sr, T):
    """Return (value, volume_check_bool_or_None) — pass is computed later vs the oracle's bounds."""
    name, ind = sr["subrulename"], sr["indicator"]
    def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
    vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
    if name == "volume_check":
        rows60 = vol_rows(T, 60)
        ok = check_volumeud_3(rows60, MIN_VOLUME)
        return (round(float(rows60[0]["value"])) if (ok and rows60) else 0.0), ok
    if name == "missingdata":
        return round(missingdata(vol_rows(T, 300, def1)), 4), None
    n = def1 if name != "currentvalue" else 1
    vals, prices = vals_window(ind, n, T)
    return subrule_value(name, vc, vals, prices), None


def oracle_bound(settings, key):
    if not settings:
        return None
    b = json.loads(settings).get(key)
    return None if b in (None, "", "null") else float(b)


subrules = sq("SELECT ID, sort, indicator, subrulename, def1_value, b_min, b_max, value_condition "
              "FROM wp_trading_rules WHERE rule_number=%s AND active=1 ORDER BY sort, ID", (RULE,))
sr_by_id = {s["ID"]: s for s in subrules}
odts = [r["datetime"] for r in sq(
    "SELECT DISTINCT datetime FROM wp_trading_simulation_trades_indicator "
    "WHERE trading_symbol_ID=%s AND rule_number=%s AND datetime>=%s AND datetime<%s ORDER BY datetime",
    (SYM, RULE, FROM, TO))]
print(f"Validating at {len(odts)} legacy-evaluated datetimes ({FROM}..{TO})")

agg = defaultdict(lambda: {"val_ok": 0, "pass_ok": 0, "n": 0})
my_fire = leg_fire = fire_agree = 0
for T in odts:
    orows = {r["rule_ID"]: r for r in sq(
        "SELECT rule_ID, value, result_ok, settings FROM wp_trading_simulation_trades_indicator "
        "WHERE trading_symbol_ID=%s AND rule_number=%s AND datetime=%s", (SYM, RULE, T))}
    my_pass = True
    for sr in subrules:
        v, vbool = eval_value(sr, T)
        orc = orows.get(sr["ID"])
        if orc is None:
            continue
        # pass using the boundary the legacy used AT THAT TIME (from oracle settings)
        if sr["subrulename"] == "volume_check":
            mypass = vbool
        else:
            blo = oracle_bound(orc["settings"], "boundary_low")
            bhi = oracle_bound(orc["settings"], "boundary_high")
            mypass = passes(v, blo, bhi)
        if mypass is False:
            my_pass = False
        a = agg[sr["ID"]]; a["n"] += 1
        if v is not None and abs(round(v, 1) - round(float(orc["value"]), 1)) < 0.05:
            a["val_ok"] += 1
        if mypass is not None and int(mypass) == orc["result_ok"]:
            a["pass_ok"] += 1
    leg = len(orows) == len(subrules) and all(r["result_ok"] == 1 for r in orows.values())
    my_fire += my_pass; leg_fire += leg; fire_agree += (my_pass == leg)

print("-" * 78)
print(f"{'id':>5} {'indicator':<13}{'subrulename':<15}{'value-match':>14}{'pass-match':>14}")
for sr in subrules:
    a = agg[sr["ID"]]
    if a["n"] == 0:
        continue
    vm = f"{a['val_ok']}/{a['n']}"; pm = f"{a['pass_ok']}/{a['n']}"
    flag = "  <-- DIVERGES" if a["pass_ok"] < a["n"] else ""
    print(f"{sr['ID']:>5} {sr['indicator']:<13}{sr['subrulename']:<15}{vm:>14}{pm:>14}{flag}")
print("-" * 78)
print(f"FIRE verdict: mine={my_fire}  legacy={leg_fire}  agree={fire_agree}/{len(odts)}")
src.close()
