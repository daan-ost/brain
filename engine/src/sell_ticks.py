#!/usr/bin/env python3
"""
Write the per-tick sell trail to brain.coin_sell_ticks — Daan's "store, per datetime, ALL values"
(Epic S). For every real trade (executed coin_fires) the sell-engine walks the price forward and we
record, at each volumeud tick: marketprice, profit, peak profit, the absolute floor (minimum_price),
the lock_profit ratchet (lock_price, pre-clamp), the rule-101 multiplier, and the resulting stop +
orderstatus. This is the instrument to see the max price reached in a hold and to debug the rule-101
sell-signal timing (the remaining fidelity gap).

Idempotent per symbol (delete + reinsert). READ-ONLY on bot_signals; writes only brain.

Usage: sell_ticks.py <symbol_id> [--run]      (default DRY-RUN: counts + a sample, writes nothing)

NOTE (scope): v1 covers executed fires (the actual trades — "bij de trade"). Promising-moment trails
are a follow-up: they need the O(n·window) promising scan from sell_promising.py (give it a suffix-max
before a full-history run), so they are intentionally not included here yet.
"""
import sys

from db import brain
from sell_engine import SellEngine

SELL_VERSION = "v2-ratchet"

args = [a for a in sys.argv[1:] if not a.startswith("--")]
SYM = int(args[0]) if args else 2525
RUN = "--run" in sys.argv

eng = SellEngine(SYM)
dst = brain()
with dst.cursor() as c:
    # Meerdere rules kunnen op hetzelfde tijdstip fire'n + executed worden. De sell-trail is
    # afhankelijk van (datetime, buy_price, rule); we dedupliceren op datetime (laagste rule wint)
    # zodat de natural-key (coin, datetime, tick) niet botst — de UI toont één trail per trade-moment.
    c.execute("SELECT datetime, buy_price, MIN(rule) rule, MAX(symbol) symbol FROM coin_fires "
              "WHERE trading_symbol_id=%s AND is_executed=1 AND buy_price IS NOT NULL "
              "GROUP BY datetime, buy_price ORDER BY datetime", (SYM,))
    fires = c.fetchall()
SYMBOL = fires[0]["symbol"] if fires else str(SYM)
trades = [(f["datetime"], float(f["buy_price"]), f["rule"]) for f in fires]
print(f"=== sell_ticks — {SYMBOL} ({SYM}): {len(trades)} executed trades ===")

if not RUN:
    for dt, buy, rule in trades[:5]:
        r = eng.sell(dt, buy, rule, trace=True)
        print(f"  {dt} rule{rule}: {len(r['ticks']) if r else 0} ticks -> pl={r['profit_loss'] if r else None}")
    print("DRY-RUN (niets geschreven). Pas --run toe om coin_sell_ticks te vullen.")
    eng.close(); dst.close(); sys.exit(0)

dst.autocommit(False)
rows = traded = 0
with dst.cursor() as c:
    c.execute("DELETE FROM coin_sell_ticks WHERE trading_symbol_id=%s", (SYM,))
    for dt, buy, rule in trades:
        r = eng.sell(dt, buy, rule, trace=True)
        if not r:
            continue
        traded += 1
        batch = [(SYM, SYMBOL, dt, t["tick_dt"], t["minutes"], t["marketprice"], t["profit"], t["highest_profit"],
                  t["minimum_price"], t["lock_price"], t["rule101_mult"], t["stoploss_price"], t["selling_price"],
                  t["orderstatus"], SELL_VERSION) for t in r["ticks"]]
        c.executemany(
            "INSERT INTO coin_sell_ticks (trading_symbol_id, symbol, datetime, tick_datetime, minutes_in_trade, "
            "marketprice, profit, highest_profit, minimum_price, lock_price, rule101_mult, stoploss_price, "
            "selling_price, orderstatus, sell_version, created_at, updated_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())", batch)
        rows += len(batch)
dst.commit()
print(f"  geschreven: {rows} tick-rijen voor {traded} trades")
eng.close(); dst.close()
