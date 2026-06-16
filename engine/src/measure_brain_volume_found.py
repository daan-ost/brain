#!/usr/bin/env python3
"""
Measure the IMPACT of switching the candidate-gate from `volume_found` (legacy) to `brain_volume_found`
(brain's own). Read-only: shadows the re-fire with brain_volume_found as the gate, classifies every
NEW trade by best_upside, and reports the would-be ratio change per rule. The live engine is NOT
touched.

Pipeline mirrors persist_to_brain: per coin, run all rules with the brain_volume_found gate, apply the
single-position dedup (executed vs shadow), classify executed buys on best_upside, and compare against
the current coin_fires (which were built with the legacy gate).

Usage: measure_brain_volume_found.py    # writes engine/out/opt/brain_vf_impact.json + prints summary
"""
import bisect
import datetime as dt
import json
import os
import sys

from db import brain
from rule_engine import RuleEngine
from sell_engine import SellEngine
from config import FORWARD_MINUTES

COINS = [2525, 244]
RULES = (20, 21, 22, 23)
GOOD = 3.0
BAD = 0.5
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "out", "opt", "brain_vf_impact.json")


def shadow_fires_for(sym, gate_col):
    """Run rule_engine.fires() per rule, but with gate_col instead of volume_found as candidate-gate.
    Returns dict rule -> list of fire datetimes (raw, before dedup)."""
    eng = RuleEngine(sym)
    # swap the candidate flag in the loaded series
    conn = brain()
    with conn.cursor() as c:
        c.execute(f"SELECT datetime, {gate_col} vf FROM indicators WHERE trading_symbol_id=%s "
                  f"AND indicator='volumeud' AND value IS NOT NULL ORDER BY datetime", (sym,))
        rows = c.fetchall()
    conn.close()
    # rebuild the vf array in eng.series in the SAME order/length as eng.series['volumeud']['dt']
    s = eng.series["volumeud"]
    new_vf = []
    idx = 0
    vfmap = {r["datetime"]: int(r["vf"]) for r in rows}
    for d in s["dt"]:
        new_vf.append(vfmap.get(d, 0))
    s["vf"] = new_vf
    out = {}
    for r in RULES:
        out[r] = eng.fires(r)
    eng.close()
    return out


def dedup_and_classify(sym, fires_by_rule):
    """Single-position dedup (first-fire-opens, later overlaps become shadows) + best_upside per
    executed. Mirrors persist_to_brain.py's loop. Returns dict rule -> (good_count, bad_count, executed)."""
    sell_eng = SellEngine(sym)
    DT, PX = sell_eng.DT, sell_eng.PX

    def price_at(d):
        i = bisect.bisect_right(DT, d)
        return PX[i - 1] if i > 0 else None

    def best_upside(d, buy):
        if not buy:
            return None
        lo = bisect.bisect_left(DT, d)
        hi = bisect.bisect_right(DT, d + dt.timedelta(minutes=FORWARD_MINUTES))
        return (max(PX[lo:hi]) - buy) / buy * 100 if lo < hi else None

    # flatten + sort by datetime; on ties, keep stable rule order
    all_fires = []
    for r, dts in fires_by_rule.items():
        for d in dts:
            all_fires.append((d, r))
    all_fires.sort()

    per_rule = {r: {"executed_good": 0, "executed_bad": 0, "executed_mid": 0,
                    "executed_total": 0, "shadow_total": 0} for r in RULES}
    open_until = None
    for d, r in all_fires:
        if open_until is not None and d < open_until:
            per_rule[r]["shadow_total"] += 1
            continue
        # executed
        buy = price_at(d)
        bu = best_upside(d, buy) if buy else None
        per_rule[r]["executed_total"] += 1
        if bu is not None:
            if bu >= GOOD:
                per_rule[r]["executed_good"] += 1
            elif bu < BAD:
                per_rule[r]["executed_bad"] += 1
            else:
                per_rule[r]["executed_mid"] += 1
        # sell to compute open_until
        sres = sell_eng.sell(d, buy, r) if buy else None
        open_until = sres["selling_date"] if sres else d
    sell_eng.close()
    return per_rule


def current_state(sym):
    """The CURRENT coin_fires (built with the legacy gate) — per rule good/bad/mid counts."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT rule, "
                  "SUM(is_executed=1) as ex, "
                  "SUM(is_executed=1 AND best_upside>=%s) as g, "
                  "SUM(is_executed=1 AND best_upside<%s) as b, "
                  "SUM(is_executed=1 AND best_upside>=%s AND best_upside<%s) as m, "
                  "SUM(is_executed=0) as sh "
                  "FROM coin_fires WHERE trading_symbol_id=%s AND best_upside IS NOT NULL "
                  "GROUP BY rule ORDER BY rule", (GOOD, BAD, BAD, GOOD, sym))
        rows = c.fetchall()
    conn.close()
    return {r["rule"]: {"executed_good": int(r["g"] or 0), "executed_bad": int(r["b"] or 0),
                        "executed_mid": int(r["m"] or 0), "executed_total": int(r["ex"] or 0),
                        "shadow_total": int(r["sh"] or 0)} for r in rows}


def fmt(label, d):
    g, b, m, ex, sh = d["executed_good"], d["executed_bad"], d["executed_mid"], d["executed_total"], d["shadow_total"]
    ratio = g / b if b else float("inf")
    return f"  {label}: executed {ex} (goed {g} / mid {m} / slecht {b}) ratio {ratio:.2f}  | shadows {sh}"


def main():
    report = {}
    for sym in COINS:
        print(f"\n=== coin {sym} ===")
        # CURRENT state (legacy gate, from coin_fires)
        cur = current_state(sym)
        # NEW state (brain_volume_found gate, shadow re-fire)
        fires = shadow_fires_for(sym, "brain_volume_found")
        new = dedup_and_classify(sym, fires)
        report[sym] = {"current_legacy_gate": cur, "shadow_brain_gate": new}
        for r in RULES:
            print(f"--- rule {r} ---")
            print(fmt("HUIDIG (legacy vf)", cur.get(r, {"executed_good": 0, "executed_bad": 0, "executed_mid": 0, "executed_total": 0, "shadow_total": 0})))
            print(fmt("SHADOW (brain vf)", new[r]))
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w") as f:
        json.dump(report, f, indent=2, default=str)
    print(f"\nrapport -> {OUT}")
    # pooled summary
    print("\n=== TOTAAL gepoold (beide coins) ===")
    for label, key in (("HUIDIG (legacy gate)", "current_legacy_gate"),
                       ("SHADOW (brain_volume_found)", "shadow_brain_gate")):
        g = sum(report[s][key].get(r, {}).get("executed_good", 0) for s in COINS for r in RULES)
        b = sum(report[s][key].get(r, {}).get("executed_bad", 0) for s in COINS for r in RULES)
        m = sum(report[s][key].get(r, {}).get("executed_mid", 0) for s in COINS for r in RULES)
        print(f"  {label}: goed {g} / mid {m} / slecht {b}  ratio {g/b:.2f}" if b
              else f"  {label}: goed {g} / mid {m} / slecht 0")


if __name__ == "__main__":
    main()
