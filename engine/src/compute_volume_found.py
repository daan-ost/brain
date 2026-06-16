#!/usr/bin/env python3
"""
Compute brain's OWN volume_found flag (column `brain_volume_found`) per volumeud tick, independent of
legacy. Per tick run `check_volumeud_3` with the live per-coin/per-rule settings; if ANY buy-rule
(20-23) volume-check passes → brain_volume_found=1, else 0.

This is a SHADOW field. The live engine still uses `volume_found` (legacy-copied) as the candidate-gate
— this lets us MEASURE the impact of switching before changing the engine's behaviour. For future coins
(TradingView) there is no legacy `volume_found`, so brain_volume_found is the only source.

Run AFTER seed_rules.py (which fills coin_rule_settings.min_volume — required for the check).

Usage: compute_volume_found.py [symbol_id ...]   (default: all coins in `coins`)
"""
import sys
import time

from db import brain
from rule_engine import RuleEngine
from volume import check_volumeud_3, volume_settings

RULES = (20, 21, 22, 23)
BATCH = 500                    # write every N ticks to keep transactions short


def compute_for(sym):
    eng = RuleEngine(sym)
    # rule -> (min_volume, settings); skip rules with no min_volume (defensive)
    rs = []
    for r in RULES:
        mv = eng.minvol.get(r)
        if mv is None or mv >= 1e11:
            continue
        rs.append((r, float(mv), volume_settings(r)))
    if not rs:
        print(f"  coin {sym}: no min_volume per rule → volume_found stays 0"); eng.close(); return 0

    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime FROM indicators WHERE trading_symbol_id=%s AND indicator='volumeud' "
                  "AND value IS NOT NULL ORDER BY datetime", (sym,))
        dts = [r["datetime"] for r in c.fetchall()]
    n_ticks = len(dts)

    flips = []                  # the ticks that should become volume_found=1 (default is 0)
    t0 = time.time()
    for dt in dts:
        rows = eng._vol_rows(dt, 60)
        if any(check_volumeud_3(rows, mv, s) for _, mv, s in rs):
            flips.append(dt)
    elapsed = time.time() - t0

    # write in batches: reset to 0, then flip the hits to 1 (column brain_volume_found, NOT volume_found)
    with conn.cursor() as c:
        c.execute("UPDATE indicators SET brain_volume_found=0 WHERE trading_symbol_id=%s AND indicator='volumeud'", (sym,))
        for i in range(0, len(flips), BATCH):
            chunk = flips[i:i + BATCH]
            ph = ",".join(["%s"] * len(chunk))
            c.execute(f"UPDATE indicators SET brain_volume_found=1 WHERE trading_symbol_id=%s "
                      f"AND indicator='volumeud' AND datetime IN ({ph})", (sym, *chunk))
    conn.commit()
    conn.close()
    eng.close()
    print(f"  coin {sym}: {len(flips)}/{n_ticks} brain_volume_found=1 ({100*len(flips)/n_ticks:.1f}%) "
          f"in {elapsed:.1f}s")
    return len(flips)


def main():
    args = [int(a) for a in sys.argv[1:]]
    if not args:
        conn = brain()
        with conn.cursor() as c:
            c.execute("SELECT id FROM coins ORDER BY id")
            args = [r["id"] for r in c.fetchall()]
        conn.close()
    print(f"compute volume_found for coins: {args}")
    for sym in args:
        compute_for(sym)


if __name__ == "__main__":
    main()
