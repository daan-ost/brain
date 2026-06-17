#!/usr/bin/env python3
"""
Effect van de sell-engine op de trades — per coin, per rule en totaal. Draait de sell-engine in 3
varianten over de executed fires en toont de TOTALE profit (winst+verlies), het aantal slechte trades
(verlies), en hoeveel verliezers winnaars worden door de ratchet (full vs no_ratchet).

Varianten:
  bare        — kaal: geen ratchet, geen rule-101 (alleen harde bodem + leeftijd-ladder).
  no_ratchet  — ratchet UIT, rule-101 AAN (de vorige 87%-versie van de sell-engine).
  full        — ratchet AAN + rule-101 (de huidige, verbeterde sell-engine).

Read-only (brain). Monkey-patcht alleen in-memory de lock/rule-101 om de varianten te draaien.
Usage: sell_compare.py [symbol_id ...]      (default: 2525 244)
"""
import sys

import sell_engine
from db import brain

COINS = [int(a) for a in sys.argv[1:]] or [2525, 244]
RULES = [20, 21, 22, 23]
VARIANTS = ["bare", "no_ratchet", "full", "smooth"]


def floor_only(profit, minutes, hi, buy, market, sl):
    """Lock zonder ratchet (vorige versie): alleen bodem + leeftijd-floor."""
    if market < buy * sl["min_sl1"]:
        return market * 0.9999
    if minutes < sl["min1"] and profit < sl["minimal_profit"]:
        return buy * sl["min_sl1"]
    if minutes < sl["min2"] and profit < sl["minimal_profit"]:
        return buy * sl["min_sl2"]
    return buy * sl["min_sl1"]


def no_rule101(*a, **k):
    return "hold", ""


def lock_smooth(profit, minutes, hi, buy, market, sl):
    """winst-lock met de dode zone (+5..+15% piek) gedicht: neem het MAX van de milde tier
    (piek/hp6) en de bewaar-50%-tier (piek-hp7). Geen klif meer, en grote winnaars houden hun
    bescherming. Alleen de twee hoogste tiers verschillen van de getrouwe lock_profit."""
    if market < buy * sl["min_sl1"]:
        return market * 0.9999
    if minutes < sl["min1"] and profit < sl["minimal_profit"]:
        return buy * sl["min_sl1"]
    if minutes < sl["min2"] and profit < sl["minimal_profit"]:
        return buy * sl["min_sl2"]
    if hi >= 0.15:
        if hi < 0.21:  return buy + sl["hp1"] * buy
        if hi < 0.30:  return buy + sl["hp2"] * buy
        if hi < 0.40:  return buy + sl["hp3"] * buy
        if hi < 0.50:  return buy + sl["hp4"] * buy
        if hi < 0.70:  return buy + sl["hp5"] * buy
        return buy + max((hi / sl["hp6"]) / 100, (hi - sl["hp7"]) / 100) * buy
    return buy * sl["min_sl1"]


_lock, _r101 = sell_engine.lock_profit, sell_engine.rule_engine_101
MODES = {"bare": (floor_only, no_rule101), "no_ratchet": (floor_only, _r101),
         "full": (_lock, _r101), "smooth": (lock_smooth, _r101)}

results, symbols = {}, {}
for sym in COINS:
    eng = sell_engine.SellEngine(sym)
    with brain().cursor() as c:
        c.execute("SELECT datetime, buy_price, rule, symbol FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND is_executed=1 AND buy_price IS NOT NULL ORDER BY datetime", (sym,))
        fires = [(f["datetime"], float(f["buy_price"]), f["rule"], f["symbol"]) for f in c.fetchall()]
    symbols[sym] = fires[0][3] if fires else str(sym)
    for variant, (lk, r101) in MODES.items():
        sell_engine.lock_profit, sell_engine.rule_engine_101 = lk, r101
        for dt, buy, rule, _ in fires:
            r = eng.sell(dt, buy, rule)
            results[(variant, sym, rule, dt)] = r["profit_loss"] if r else 0.0
    eng.close()
sell_engine.lock_profit, sell_engine.rule_engine_101 = _lock, _r101


def agg(variant, sym=None, rule=None):
    vals = [v for (vv, ss, rr, _), v in results.items()
            if vv == variant and (sym is None or ss == sym) and (rule is None or rr == rule)]
    return sum(vals), len(vals), sum(1 for v in vals if v < 0)


def wv(n, nl):
    """winst/verlies-ratio: (winnaars) / (verliezers)."""
    return (n - nl) / nl if nl else float("inf")


def block(title, sym):
    print(f"\n=== {title} ===")
    print(f"{'rule':>5} {'variant':>11} {'trades':>7} {'verlies':>8} {'Σprofit%':>10} {'gem%':>7} {'W/V':>6}")
    for rule in RULES + [None]:
        for variant in VARIANTS:
            s, n, nl = agg(variant, sym, rule)
            if n == 0:
                continue
            print(f"{str(rule or 'ALLE'):>5} {variant:>11} {n:>7} {nl:>8} {s:>+10.1f} {(s / n):>+7.2f} {wv(n, nl):>6.2f}")
        if agg("full", sym, rule)[1]:
            print()


for sym in COINS:
    block(f"{symbols[sym]} ({sym})", sym)

print("=== TOTAAL (alle coins) ===")
print(f"{'variant':>11} {'trades':>7} {'verlies':>8} {'Σprofit%':>10} {'gem%':>7} {'W/V':>6}")
for variant in VARIANTS:
    s, n, nl = agg(variant)
    print(f"{variant:>11} {n:>7} {nl:>8} {s:>+10.1f} {(s / n if n else 0):>+7.2f} {wv(n, nl):>6.2f}")

print("\n=== Winst/verlies-ratio + Σprofit per rule (alle coins) over de varianten ===")
print(f"{'rule':>5} | {'no_ratchet':>16} | {'full (getrouw)':>16} | {'smooth (dode zone dicht)':>24}")
for rule in RULES + [None]:
    cells = []
    for variant in ["no_ratchet", "full", "smooth"]:
        s, n, nl = agg(variant, None, rule)
        cells.append(f"W/V {wv(n, nl):.2f}  Σ{s:+.0f}%")
    print(f"{str(rule or 'TOT'):>5} | {cells[0]:>16} | {cells[1]:>16} | {cells[2]:>24}")


def effect(base, new, label):
    print(f"\n=== {label} ===")
    for sym in COINS:
        for rule in RULES + [None]:
            keys = [(ss, rr, dt) for (vv, ss, rr, dt) in results if vv == new and ss == sym and (rule is None or rr == rule)]
            if not keys:
                continue
            saved = sum(1 for k in keys if results[(base, *k)] < 0 <= results[(new, *k)])
            broke = sum(1 for k in keys if results[(new, *k)] < 0 <= results[(base, *k)])
            d = agg(new, sym, rule)[0] - agg(base, sym, rule)[0]
            print(f"  {symbols[sym]} rule {str(rule or 'ALLE'):>4}: verlies→winst {saved:>3}, winst→verlies {broke:>3}, ΔΣprofit {d:>+7.1f}%")


effect("no_ratchet", "full", "EFFECT winst-lock (full vs no_ratchet)")
effect("full", "smooth", "EFFECT dode zone dichten (smooth vs full)")
