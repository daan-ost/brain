#!/usr/bin/env python3
"""
THE one import: copy the raw indicator series + minimal coin settings from the read-only legacy
bot_signals into the brain DB. This is the ONLY thing we take from bot_signals — everything else
(rules, fires, promising, good/bad) is rebuilt by us in brain. Both DBs live on the same MySQL
server, so the copy is a fast server-side INSERT..SELECT.

Idempotent: clears the symbol's rows first. Default coins: DOGEAI (2525) + NOS (244).

Usage: import_indicators.py [symbol_id ...]      (default: 2525 244)
"""
import sys

import pymysql

from outlier_guard import null_price_outliers

SYMS = [int(a) for a in sys.argv[1:]] or [2525, 244]
INDICATORS = ("vzo", "phobos", "obv-x-value", "mfi", "volumeud")

conn = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                       database="brain", autocommit=True)
in_list = ",".join(f"'{i}'" for i in INDICATORS)
sym_list = ",".join(str(s) for s in SYMS)

with conn.cursor() as c:
    for sym in SYMS:
        c.execute("DELETE FROM indicators WHERE trading_symbol_id=%s", (sym,))
        c.execute("DELETE FROM coins WHERE id=%s", (sym,))

    # coins (minimal settings)
    c.execute(f"""
        INSERT INTO coins (id, symbol, timeframe, stoploss_multiplier, roundingup, created_at, updated_at)
        SELECT s.ID, s.symbol, s.timeframe, s.stoploss_multiplier, s.roundingup, NOW(), NOW()
        FROM bot_signals.wp_trading_symbols s WHERE s.ID IN ({sym_list})
    """)
    print(f"coins copied: {c.rowcount}")

    # indicators (the series) — the 5 base RAW indicator values from legacy. We still copy legacy's
    # `volume_found` because the live engine uses it as candidate-gate; brain's OWN flag is computed
    # separately into `brain_volume_found` (compute_volume_found.py). For TradingView/new coins
    # without a legacy source, `volume_found` will be 0 and only `brain_volume_found` will be filled.
    c.execute(f"""
        INSERT INTO indicators (trading_symbol_id, symbol, indicator, datetime, value, price, volume_found)
        SELECT i.trading_symbol_id, s.symbol, i.indicator, i.datetime, i.value, i.price, i.volume_found
        FROM bot_signals.wp_trading_indicator i
        JOIN bot_signals.wp_trading_symbols s ON s.ID = i.trading_symbol_id
        WHERE i.trading_symbol_id IN ({sym_list}) AND i.indicator IN ({in_list}) AND i.value IS NOT NULL
    """)
    print(f"indicator rows copied: {c.rowcount} (legacy volume_found copied; brain_volume_found via compute_volume_found.py)")

    c.execute("SELECT trading_symbol_id, COUNT(*) n, MIN(datetime) f, MAX(datetime) t FROM indicators GROUP BY trading_symbol_id")
    for r in c.fetchall():
        print(f"  symbol {r[0]}: {r[1]} rows  {r[2]} .. {r[3]}")

# Outlier-guard (LEIDEND): de legacy-feed bevat soms één corrupte schaal-glitch (bv. price=23044
# waar de echte prijs ~0,02304 is). Die zou downstream absurde winst veroorzaken in de sell-engine.
# Zet price=NULL voor elke tick die >OUTLIER_FACTOR afwijkt van de mediaan van zijn buren, per
# (symbol, indicator). Draait NA de INSERT..SELECT zodat een re-import de glitch wel kopieert maar
# de guard hem meteen weer wegneemt. bot_signals blijft ongemoeid — we corrigeren alleen brain.
nulled = null_price_outliers(conn, SYMS, INDICATORS)
if nulled:
    total = sum(nulled.values())
    detail = ", ".join(f"{sym}/{ind}={n}" for (sym, ind), n in nulled.items())
    print(f"outlier-guard: price=NULL gezet voor {total} corrupte tick(s) — {detail}")
else:
    print("outlier-guard: geen prijs-outliers gevonden")
conn.close()
