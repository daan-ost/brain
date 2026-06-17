#!/usr/bin/env python3
"""
Sell-side validation — replay the legacy selling process for DOGEAI closed trades
and compare selling_price / selling_date / profit_loss / highest/lowest_profit_loss
against wp_trading_simulation (the oracle). READ-ONLY on bot_signals.

Faithful to docs/methodology/selling-process.md:
  - walk the volumeud price series forward from the buy (max 1500 min);
  - CHECK 1 absolute floor (min_sl1*buy), CHECK 2 hardcoded age/profit ladder,
    CHECK 3 trailing-stop breach, else a new stop from the min_sl1/min_sl2 floor;
  - selling_price = stop * stoploss_multiplier (synthetic);
    profit_loss = round((selling_price-buy)/buy*100, 3).
Rule 101 is STUBBED in this pass (no sell-signal multiplier) — this should already
reproduce the 0.988/0.99 floor sells (mostly the losing trades). The HP ratchet is
inert per the spec, so it is omitted here.
"""
import bisect
import json

import pymysql

from sell_rule101 import rule_engine_101

SYM = 2525
SELL_MULT = 0.9996      # wp_trading_symbols.stoploss_multiplier (DOGEAI)
ROUNDING = 16           # wp_trading_symbols.roundingup
MAX_MIN = 1500

src = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                      database="bot_signals", cursorclass=pymysql.cursors.DictCursor)


def q(sql, args=()):
    with src.cursor() as c:
        c.execute(sql, args)
        return c.fetchall()


def parse_sl(raw):
    s = json.loads(raw) if raw else {}
    g = lambda *ks, d=None: next((float(s[k]) for k in ks if k in s and s[k] not in (None, "")), d)
    ap = s.get("array_profit")
    return {
        "min_sl1": g("min_sl1", "min_sl", d=0.988),
        "min1": g("minutes_in_trade1", "minutes_in_trade", d=6),
        "min_sl2": g("min_sl2", d=g("min_sl1", "min_sl", d=0.99)),
        "min2": g("minutes_in_trade2", d=15),
        "minimal_profit": g("minimal_profit", d=0.8),
        # highest-profit ratchet (lock_profit). Defaults = legacy functions_br.php:4779-4787.
        "hp1": g("hp_setting1", d=-0.003), "hp2": g("hp_setting2", d=-0.002),
        "hp3": g("hp_setting3", d=-0.0015), "hp4": g("hp_setting4", d=0.001),
        "hp5": g("hp_setting5", d=0.001), "hp6": g("hp_setting6", d=4.0),
        "hp7": 15.0,    # legacy HARD-overrides the JSON to 15 (functions_br.php:4805)
        # CHECK-2 age/profit ladder (now configurable; default = legacy hardcoded array)
        "array_profit": [[float(m), float(t)] for m, t in ap] if ap else [[5, -0.4], [7, -0.1], [8, 0.0], [20, 0.5]],
    }


# SL_settings per rule (allrules ID == rule_number)
sl_by_rule = {int(r["ID"]): parse_sl(r["SL_settings"]) for r in q("SELECT ID, SL_settings FROM wp_trading_allrules")}

# volumeud series for DOGEAI (datetime, price, value), once
rows = q("SELECT datetime, price, value FROM wp_trading_indicator WHERE trading_symbol_id=%s AND indicator='volumeud' "
         "AND price IS NOT NULL ORDER BY datetime", (SYM,))
DT = [r["datetime"] for r in rows]
PX = [float(r["price"]) for r in rows]
VV = [float(r["value"]) if r["value"] is not None else 0.0 for r in rows]

# rule 101 sell-subrules (ordered by sort)
SUBRULES_101 = q("SELECT ID, sort, subrulename, def1_value, def2_value, b_min, b_max, operator, "
                 "value_condition, condition_rule FROM wp_trading_rules WHERE rule_number=101 AND active=1 ORDER BY sort, ID")


def lock_profit(profit, minutes, hi, buy, market, sl):
    """The trailing floor (legacy lock_profit, functions_br.php:4744). hi = highest_profit_loss
    in PERCENT, incl. the current tick. First match wins; the ratchet ratchets the stop up as
    the trade's peak profit grows (this is the piece the 87% version left inert)."""
    if market < buy * sl["min_sl1"]:                                   # gate: below hard floor
        return market * 0.9999
    if minutes < sl["min1"] and profit < sl["minimal_profit"]:         # young + not-yet-in-profit
        return buy * sl["min_sl1"]
    if minutes < sl["min2"] and profit < sl["minimal_profit"]:         # mid + not-yet-in-profit
        return buy * sl["min_sl2"]
    if hi >= 0.15:                                                     # HIGHEST-PROFIT RATCHET
        if hi < 0.21:  return buy + sl["hp1"] * buy
        if hi < 0.30:  return buy + sl["hp2"] * buy
        if hi < 0.40:  return buy + sl["hp3"] * buy
        if hi < 0.50:  return buy + sl["hp4"] * buy
        if hi < 0.70:  return buy + sl["hp5"] * buy
        if hi < 5:     return buy + ((hi / sl["hp6"]) / 100) * buy     # save ~25% of peak
        return buy + ((hi - sl["hp7"]) / 100) * buy                    # save ~50% of peak
    return buy * sl["min_sl1"]                                         # fallback (hi<0.15, past gates)


def determine_stop(buy, market, minutes, profit, hi, stop_prev, sl, i, buy_dt, max_price):
    floor_price = round(buy * sl["min_sl1"], ROUNDING)
    if market < floor_price:                                            # CHECK 1 — absolute floor
        return market, round(market * SELL_MULT, ROUNDING), "sell"
    if minutes >= 5:                                                    # CHECK 2 — age/profit ladder
        for m, tp in sl["array_profit"]:
            if minutes > m and profit < tp:
                return market, round(market * SELL_MULT, ROUNDING), "sell"
    if stop_prev is not None and stop_prev > market:                   # CHECK 3 — trailing breach
        return market, round(market * SELL_MULT, ROUNDING), "sell"

    lock = lock_profit(profit, minutes, hi, buy, market, sl)
    os101, mult = rule_engine_101(DT, PX, VV, i, buy_dt, buy, market, SUBRULES_101, max_price)
    if mult != "":
        re_stop = mult * market
        new_stop = re_stop
        if lock > re_stop and os101 != "overrule":     # take the higher (tighter) floor
            new_stop = lock
    else:
        new_stop = lock
    new_stop = round(new_stop, ROUNDING)
    orderstatus = "sell" if os101 == "sell" else "hold"
    if new_stop > market:                              # implied stop above price → exit
        orderstatus, new_stop = "sell", market
    if new_stop < floor_price:
        return floor_price, round(floor_price * SELL_MULT, ROUNDING), orderstatus
    return new_stop, round(new_stop * SELL_MULT, ROUNDING), orderstatus


def replay(buy_dt, buy, sl):
    i = bisect.bisect_right(DT, buy_dt)
    stop_prev, hi, lo, max_price = None, 0.0, 0.0, buy
    while i < len(DT) and (DT[i] - buy_dt).total_seconds() <= MAX_MIN * 60:
        T, market = DT[i], PX[i]
        minutes = (T - buy_dt).total_seconds() / 60.0
        profit = round((market - buy) / buy * 100, 2)
        hi = max(hi, profit); lo = min(lo, profit)
        max_price = max(max_price, market)
        breach = stop_prev is not None and market < stop_prev
        stop, selling_price, orderstatus = determine_stop(buy, market, minutes, profit, hi, stop_prev, sl, i, buy_dt, max_price)
        if orderstatus == "sell" or breach:
            if breach:
                stop = market if stop > market else stop_prev
                selling_price = stop
            pl = round((selling_price - buy) / buy * 100, 3)
            return dict(selling_price=selling_price, selling_date=T, profit_loss=pl, hi=hi, lo=lo)
        stop_prev = stop
        i += 1
    return None


trades = q("SELECT ID, datetime buy_dt, price buy_price, rule, selling_price, selling_date, "
           "profit_loss, highest_profit_loss, lowest_profit_loss FROM wp_trading_simulation "
           "WHERE trading_symbol_id=%s AND selling_price IS NOT NULL ORDER BY datetime", (SYM,))

agree = dict(price=0, date=0, pl=0, pl_close=0, dir=0, hi=0, lo=0, n=0, none=0)
my_total_pl = oracle_total_pl = 0.0
examples = []
for t in trades:
    sl = sl_by_rule.get(int(t["rule"]), parse_sl(None))
    r = replay(t["buy_dt"], float(t["buy_price"]), sl)
    if r is None:
        agree["none"] += 1
        continue
    agree["n"] += 1
    near = lambda a, b, tol: (b is not None) and abs(a - float(b)) < tol
    opl = float(t["profit_loss"]) if t["profit_loss"] is not None else None
    agree["price"] += near(r["selling_price"], t["selling_price"], 1e-6)
    agree["date"] += t["selling_date"] is not None and r["selling_date"] == t["selling_date"]
    agree["pl"] += near(r["profit_loss"], t["profit_loss"], 0.01)
    agree["pl_close"] += near(r["profit_loss"], t["profit_loss"], 0.5)          # net-P&L tolerance
    agree["dir"] += opl is not None and ((r["profit_loss"] >= 0) == (opl >= 0))  # win/loss agreement
    agree["hi"] += near(r["hi"], t["highest_profit_loss"], 0.01)
    agree["lo"] += near(r["lo"], t["lowest_profit_loss"], 0.01)
    my_total_pl += r["profit_loss"]
    if opl is not None:
        oracle_total_pl += opl
        if not near(r["profit_loss"], opl, 0.5):
            examples.append((r["profit_loss"] - opl, t["ID"], opl, r["profit_loss"], str(t["selling_date"]), str(r["selling_date"])))

n = agree["n"]
print("=" * 66)
print(f"SELL validation — DOGEAI, {n} replayed (+{agree['none']} no-sell-in-1500m) of {len(trades)} closed trades")
print(f"  selling_price (exact) : {agree['price']}/{n}")
print(f"  selling_date (exact)  : {agree['date']}/{n}")
print(f"  profit_loss (exact)   : {agree['pl']}/{n}")
print(f"  profit_loss (±0.5%)   : {agree['pl_close']}/{n}   <- net-P&L fidelity")
print(f"  win/loss direction    : {agree['dir']}/{n}")
print(f"  highest_pl            : {agree['hi']}/{n}")
print(f"  lowest_pl             : {agree['lo']}/{n}")
print(f"  TOTAL P&L: mine {my_total_pl:+.1f}%  vs  legacy {oracle_total_pl:+.1f}%")
print("=" * 66)
over = sum(d for d, *_ in examples if d > 0)
under = sum(d for d, *_ in examples if d < 0)
print(f"  mismatches >0.5%: {len(examples)}  |  I-overshoot +{over:.0f}%  I-undershoot {under:.0f}%  (net {over+under:+.0f}%)")
if examples:
    print("biggest divergences (Δ=my−oracle, id, oracle_pl, my_pl, oracle_sell_dt, my_sell_dt):")
    for e in sorted(examples, key=lambda x: -abs(x[0]))[:10]:
        print(f"   Δ{e[0]:+6.2f}  id={e[1]}  oracle={float(e[2]):+.2f}  mine={e[3]:+.2f}  {e[4]} -> {e[5]}")
src.close()
