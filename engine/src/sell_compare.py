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
VARIANTS = ["bare", "no_ratchet", "full"]


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


_lock, _r101 = sell_engine.lock_profit, sell_engine.rule_engine_101
MODES = {"bare": (floor_only, no_rule101), "no_ratchet": (floor_only, _r101), "full": (_lock, _r101)}

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

print("\n=== Gerealiseerde winst/verlies-ratio per rule: vorige -> nieuwe sell-engine ===")
print(f"{'rule':>5} {'no_ratchet':>11} {'full':>8}   ΣprofitΔ")
for rule in RULES:
    _, n0, nl0 = agg("no_ratchet", None, rule)
    s0, *_ = agg("no_ratchet", None, rule)
    sf, nf, nlf = agg("full", None, rule)
    if not nf:
        continue
    d = sf - s0
    arrow = "UP" if wv(nf, nlf) > wv(n0, nl0) + 0.02 else ("DOWN" if wv(nf, nlf) < wv(n0, nl0) - 0.02 else "==")
    print(f"{rule:>5} {wv(n0, nl0):>11.2f} {wv(nf, nlf):>8.2f}  {d:>+8.1f}%  {arrow}")

print("\n=== EFFECT van de ratchet (full vs no_ratchet): wat verandert er ===")
for sym in COINS:
    for rule in RULES + [None]:
        keys = [(ss, rr, dt) for (vv, ss, rr, dt) in results if vv == "full" and ss == sym and (rule is None or rr == rule)]
        if not keys:
            continue
        saved = sum(1 for k in keys if results[("no_ratchet", *k)] < 0 <= results[("full", *k)])
        broke = sum(1 for k in keys if results[("full", *k)] < 0 <= results[("no_ratchet", *k)])
        d = agg("full", sym, rule)[0] - agg("no_ratchet", sym, rule)[0]
        print(f"  {symbols[sym]} rule {str(rule or 'ALLE'):>4}: verlies→winst {saved:>3}, winst→verlies {broke:>3}, ΔΣprofit {d:>+7.1f}%")
