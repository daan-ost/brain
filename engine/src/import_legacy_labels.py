#!/usr/bin/env python3
"""
Import legacy hand-labels into brain.coin_moment_labels (Epic L). Three legacy sources:
  1. wp_trading_simulation.result (1=goed/2=middel/3=slecht)  -> source='legacy' (kwaliteit-referentie,
     the "legacy" column). Per-coin rebuild (DELETE + insert), keyed (coin, datetime, rule).
  2. wp_trading_simulation_trades_result.ok_trade (1=ja/2=nee/3=geen-volume) -> the owner's explicit
     ok/niet-ok, imported as source='manual' moment-level decisions (rule=0), ONLY-IF-ABSENT so in-app
     marks set via the screen are never overwritten.
  3. wp_trading_simulation.best_selling_datetime -> per-trade beste sell-datum override (snapped onto
     our tick grid). Only-if-absent on best_sell_datetime so hand edits via the screen are not lost.
Both align the +5s legacy buy-time onto our tick grid (align_legacy_dt). bot_signals stays read-only.
No args = ALL coins with either source (full migration). Re-run per coin after it's built in brain so
its labels snap to the fresh ticks.

Usage: import_legacy_labels.py [symbol_id ...]
"""
import sys

from align import align_legacy_dt
from db import brain, legacy

KLASSE = {1: "goed", 2: "middel", 3: "slecht"}    # wp_trading_simulation.result (kwaliteit)
DECISION = {1: "yes", 3: "no"}                    # middel(2) -> no yes/no decision
# wp_trading_simulation_trades_result.ok_trade = de eigenaar's expliciete ok/niet-ok:
OK_DECISION = {1: "yes", 2: "no", 3: "no_volume"} # 1=ja, 2=nee, 3=geen volume

leg = legacy()
dst = brain()
dst.autocommit(False)

# args = expliciete coins; geen args = ALLE coins met legacy-labels (result OF ok_trade) — de
# volledige migratie. Voor coins zonder brain-ticks valt snapping terug op (legacy_dt - 5s); her-draai
# de import nadat die coin in brain is gebouwd (idempotent: per-coin rebuild).
if sys.argv[1:]:
    syms = [int(a) for a in sys.argv[1:]]
else:
    with leg.cursor() as c:
        c.execute("SELECT DISTINCT trading_symbol_id AS s FROM wp_trading_simulation WHERE result IN (1,2,3) "
                  "UNION SELECT DISTINCT trading_symbol_ID FROM wp_trading_simulation_trades_result WHERE ok_trade IN (1,2,3)")
        syms = sorted(int(r["s"]) for r in c.fetchall())
print(f"coins: {len(syms)}")

total = 0
snapped_n = 0
ok_total = 0
for sym in syms:
    with leg.cursor() as c:
        c.execute("SELECT datetime, rule, result, best_selling_datetime FROM wp_trading_simulation "
                  "WHERE trading_symbol_id=%s AND (result IN (1,2,3) OR best_selling_datetime IS NOT NULL)", (sym,))
        rows = c.fetchall()
    with dst.cursor() as c:
        c.execute("SELECT symbol FROM coins WHERE id=%s", (sym,))
        r = c.fetchone()
        # tick grid for this coin — to snap the +5s legacy times onto our moments
        c.execute("SELECT datetime FROM indicators WHERE trading_symbol_id=%s "
                  "AND indicator='volumeud' AND price IS NOT NULL ORDER BY datetime", (sym,))
        ticks = [t["datetime"] for t in c.fetchall()]
    symbol = r["symbol"] if r else str(sym)

    # rebuild: drop this coin's legacy rows first (snapping changes their datetime → no stale dupes)
    with dst.cursor() as c:
        c.execute("DELETE FROM coin_moment_labels WHERE source='legacy' AND trading_symbol_id=%s", (sym,))

    n = best_n = 0
    with dst.cursor() as c:
        for row in rows:
            res = row["result"]
            dt = align_legacy_dt(row["datetime"], ticks)   # -5s live wait -> 16:24:01 = signal 16:23:56
            if dt != row["datetime"]:
                snapped_n += 1
            best_dt = align_legacy_dt(row["best_selling_datetime"], ticks) if row["best_selling_datetime"] else None
            # rows zonder result (alleen best_selling_datetime) krijgen géén nieuwe klasse-row -- skip insert,
            # zet alleen best_sell_datetime op een bestaande (zeldzaam) of laat aan engine over.
            if res in (1, 2, 3):
                res = int(res)
                c.execute(
                    "INSERT INTO coin_moment_labels "
                    "(trading_symbol_id, symbol, datetime, rule, decision, manual_klasse, best_sell_datetime, "
                    " source, legacy_result, set_by, set_at, created_at, updated_at) "
                    "VALUES (%s,%s,%s,%s,%s,%s,%s,'legacy',%s,'import',NOW(),NOW(),NOW()) "
                    # COALESCE: NULL overschrijft geen bestaande waarde (geldt voor decision EN best_sell_datetime)
                    "ON DUPLICATE KEY UPDATE decision=COALESCE(VALUES(decision), decision), "
                    " manual_klasse=VALUES(manual_klasse), legacy_result=VALUES(legacy_result), "
                    " best_sell_datetime=COALESCE(best_sell_datetime, VALUES(best_sell_datetime)), updated_at=NOW()",
                    (sym, symbol, dt, row["rule"], DECISION.get(res), KLASSE.get(res), best_dt, res))
                n += 1
                if best_dt is not None:
                    best_n += 1
    dst.commit()

    # ok_trade -> de eigenaar's ok/niet-ok als MANUAL decision (moment-niveau, rule=0). Only-if-absent:
    # in-app marks (source=manual, via het scherm gezet) blijven; legacy vult de rest. Dedup per
    # gesnapte datumtijd (latere ID wint).
    with leg.cursor() as c:
        c.execute("SELECT datetime, ok_trade FROM wp_trading_simulation_trades_result "
                  "WHERE trading_symbol_ID=%s AND ok_trade IN (1,2,3) ORDER BY ID", (sym,))
        ok_rows = c.fetchall()
    ok_by_dt = {}
    for r in ok_rows:
        ok_by_dt[align_legacy_dt(r["datetime"], ticks)] = OK_DECISION[int(r["ok_trade"])]
    ok_n = 0
    with dst.cursor() as c:
        for dt, dec in ok_by_dt.items():
            c.execute(
                "INSERT INTO coin_moment_labels "
                "(trading_symbol_id, symbol, datetime, rule, decision, source, set_by, set_at, created_at, updated_at) "
                "VALUES (%s,%s,%s,0,%s,'manual','legacy-ok',NOW(),NOW(),NOW()) "
                "ON DUPLICATE KEY UPDATE id=id",   # only-if-absent: in-app manual-label niet overschrijven
                (sym, symbol, dt, dec))
            ok_n += int(c.rowcount == 1)
    dst.commit()
    print(f"  {symbol} ({sym}): {n} legacy-labels (waarvan {best_n} met beste sell-datum), {ok_n} ok/niet-ok")
    total += n
    ok_total += ok_n

leg.close()
dst.close()
print(f"=== import_legacy_labels — {total} legacy (source=legacy) + {ok_total} ok/niet-ok (source=manual), "
      f"{snapped_n} snapped ===")
