#!/usr/bin/env python3
"""
Import legacy hand-labels into brain.coin_moment_labels (Epic L). Reads ONLY the legacy
wp_trading_simulation.result (1=goed / 2=middel / 3=slecht) and writes source='legacy' rows keyed
by (trading_symbol_id, datetime, rule). Idempotent (ON DUPLICATE KEY UPDATE on the natural key
cml_natural). Daan's own labels (source='manual') are never touched. bot_signals stays read-only.

These are the reference labels: the labeler shows legacy next to Daan's own so divergence is
visible, and promising.py can validate/tune the auto-classification against them.

Usage: import_legacy_labels.py [symbol_id ...]   (default: 2525 244)
"""
import sys

from db import brain, legacy

KLASSE = {1: "goed", 2: "middel", 3: "slecht"}
DECISION = {1: "yes", 3: "no"}        # middel(2) -> no yes/no decision
SCOPE_RULES = (20, 21, 22, 23)

syms = [int(a) for a in sys.argv[1:]] or [2525, 244]

leg = legacy()
dst = brain()
dst.autocommit(False)
total = 0
for sym in syms:
    with leg.cursor() as c:
        c.execute("SELECT datetime, rule, result FROM wp_trading_simulation "
                  "WHERE trading_symbol_id=%s AND result IN (1,2,3) AND rule IN (20,21,22,23)", (sym,))
        rows = c.fetchall()
    with dst.cursor() as c:
        c.execute("SELECT symbol FROM coins WHERE id=%s", (sym,))
        r = c.fetchone()
    symbol = r["symbol"] if r else str(sym)

    n = 0
    with dst.cursor() as c:
        for row in rows:
            res = int(row["result"])
            c.execute(
                "INSERT INTO coin_moment_labels "
                "(trading_symbol_id, symbol, datetime, rule, decision, manual_klasse, "
                " source, legacy_result, set_by, set_at, created_at, updated_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,'legacy',%s,'import',NOW(),NOW(),NOW()) "
                "ON DUPLICATE KEY UPDATE decision=VALUES(decision), manual_klasse=VALUES(manual_klasse), "
                " legacy_result=VALUES(legacy_result), updated_at=NOW()",
                (sym, symbol, row["datetime"], row["rule"], DECISION.get(res), KLASSE.get(res), res))
            n += 1
    dst.commit()
    print(f"  {symbol} ({sym}): {n} legacy labels imported")
    total += n

leg.close()
dst.close()
print(f"=== import_legacy_labels — {total} rows into coin_moment_labels (source=legacy) ===")
