#!/usr/bin/env python3
"""
Run buy rules 20/21/22/23 over a window and overlay them on the promising periods.

Promising = the owner's good-moment definition (independent of rules); it finds MORE
than the rules. This shows, per coin: how many promising periods each rule (and the
union) actually catches, and how many rule-fires land OUTSIDE any promising period
(candidate bad entries the precision layer must drop).

READ-ONLY on bot_signals.
Usage: rules_vs_promising.py [symbol_id] [from] [to] [gap_minutes]
       defaults: 2525  <full>  15
"""
import bisect
import sys
from datetime import timedelta

import pymysql

from calc import subrule_value
from volume import missingdata, check_volumeud_3, volume_settings
from promising import PromisingEngine
from cluster_promising import scan_periods, best_entry

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
FROM = sys.argv[2] if len(sys.argv) > 2 else None
TO = sys.argv[3] if len(sys.argv) > 3 else None
GAP = int(sys.argv[4]) if len(sys.argv) > 4 else 15
RULES = [20, 21, 22, 23]

src = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="bot_signals", cursorclass=pymysql.cursors.DictCursor)


def sq(sql, args):
    with src.cursor() as c:
        c.execute(sql, args); return c.fetchall()


# in-memory indicator series (+6h margin before FROM for lookback)
_from_sql = FROM or "1970-01-01"
_to_sql = TO or "2099-01-01"
series = {}
for r in sq("SELECT indicator, datetime, value, price FROM wp_trading_indicator "
            "WHERE trading_symbol_id=%s AND datetime>=DATE_SUB(%s, INTERVAL 6 HOUR) AND datetime<%s "
            "AND value IS NOT NULL ORDER BY datetime", (SYM, _from_sql, _to_sql)):
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
    if v == "PASS":
        return True
    if lo is not None and v < float(lo):
        return False
    if hi is not None and v > float(hi):
        return False
    return True


def fire_at(subrules, min_volume, vol_settings, T):
    """True iff every subrule passes at T (current boundaries = live behaviour)."""
    for sr in subrules:
        name = sr["subrulename"]
        def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
        if name == "volume_check":
            ok = check_volumeud_3(vol_rows(T, 60), min_volume, vol_settings)
            if not ok:
                return False
            continue
        if name == "missingdata":
            import json as _j
            v = round(missingdata(vol_rows(T, 300, def1)), 4)
            if passes(v, sr["b_min"], sr["b_max"]) is False:
                return False
            continue
        import json as _j
        vc = _j.loads(sr["value_condition"]) if sr["value_condition"] else {}
        n = def1 if name != "currentvalue" else 1
        vals, prices = vals_window(sr["indicator"], n, T)
        v = subrule_value(name, vc, vals, prices)
        if passes(v, sr["b_min"], sr["b_max"]) is False:
            return False
    return True


# promising periods
eng = PromisingEngine(SYM, "asc", conn=src)
periods, moments, _ = scan_periods(eng, FROM, TO, GAP)
period_spans = [(p[0][0] - timedelta(minutes=5), p[-1][0] + timedelta(minutes=5)) for p in periods]


def in_a_period(F):
    for a, b in period_spans:
        if a <= F <= b:
            return True
    return False


print(f"=== rules_vs_promising — symbol {SYM}, {FROM or 'start'}..{TO or 'end'}, gap={GAP}m ===")
print(f"promising periods (good ground truth): {len(periods)}  (from {len(moments)} raw moments)")

# ---------- PRIMARY: actual legacy trades = the real rule fires (with results) ----------
print("\n[A] ACTUAL legacy fires (wp_trading_simulation) overlaid on promising periods")
print("-" * 78)
all_trades = sq("SELECT datetime, rule, result FROM wp_trading_simulation "
                "WHERE trading_symbol_id=%s AND rule IN (20,21,22,23) AND datetime>=%s AND datetime<%s "
                "ORDER BY datetime", (SYM, _from_sql, _to_sql))
caught_any = set()
for rule in RULES:
    tr = [t for t in all_trades if t["rule"] == rule]
    if not tr:
        continue
    fires = [t["datetime"] for t in tr]
    good = sum(t["result"] == 1 for t in tr); bad = sum(t["result"] == 3 for t in tr)
    inside = sum(in_a_period(F) for F in fires)
    caught = sum(any(span[0] <= F <= span[1] for F in fires) for span in period_spans)
    for i, span in enumerate(period_spans):
        if any(span[0] <= F <= span[1] for F in fires):
            caught_any.add(i)
    print(f"rule {rule}: {len(tr)} fires ({good} goed / {bad} slecht) | "
          f"{inside} inside a promising period, {len(tr)-inside} outside | catches {caught}/{len(periods)} periods")
tot = len(all_trades)
tot_good = sum(t["result"] == 1 for t in all_trades); tot_bad = sum(t["result"] == 3 for t in all_trades)
tot_inside = sum(in_a_period(t["datetime"]) for t in all_trades)
print("-" * 78)
print(f"UNION: {tot} fires ({tot_good} goed / {tot_bad} slecht) | {tot_inside}/{tot} inside a promising period")
print(f"RULES' RECALL on good moments: catch {len(caught_any)}/{len(periods)} periods "
      f"({len(periods)-len(caught_any)} MISSED) — promising finds far more than the rules.")

# ---------- SECONDARY: live re-eval with CURRENT boundaries (approximate) ----------
print("\n[B] live re-eval with CURRENT boundaries (approximate — boundary drift; 21 is exact)")
print("-" * 78)
for rule in RULES:
    subrules = sq("SELECT ID, sort, indicator, subrulename, def1_value, b_min, b_max, value_condition "
                  "FROM wp_trading_rules WHERE rule_number=%s AND active=1 ORDER BY sort, ID", (rule,))
    if not subrules:
        continue
    import json as _j
    msr = sq("SELECT settings FROM wp_trading_symbols_rule WHERE trading_symbol_id=%s AND rule_id=%s LIMIT 1", (SYM, rule))
    min_volume = float(_j.loads(msr[0]["settings"]).get("min_volume", 1e12)) if msr else 1e12
    vset = volume_settings(rule)
    cands = [r["datetime"] for r in sq(
        "SELECT datetime FROM wp_trading_indicator WHERE trading_symbol_id=%s AND indicator='volumeud' "
        "AND volume_found=1 AND datetime>=%s AND datetime<%s ORDER BY datetime", (SYM, _from_sql, _to_sql))]
    fires = [T for T in cands if fire_at(subrules, min_volume, vset, T)]
    legacy_n = sum(t["rule"] == rule for t in all_trades)
    print(f"rule {rule}: {len(cands)} vol-gated cands -> {len(fires)} live fires  (legacy actual: {legacy_n})")
src.close()
