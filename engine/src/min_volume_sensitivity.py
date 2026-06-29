#!/usr/bin/env python3
"""
min_volume_sensitivity.py — Epic K, Feature 2 (O3, LAAG 1). READ-ONLY.

O3-kernvraag: hoeveel verandert er als min_volume ±20/30/50% afwijkt? Dit script meet LAAG 1 — de
**kandidaat-ratio** (de volume-poort `brain_volume_found`): voor elke factor f tellen we per tick of
ÉÉN buy-rule (20-23) zijn volume_check haalt met min_volume*f. Dat is de zuivere gevoeligheid van de
kandidaat-set voor de schaal, volledig in-memory (geen DB-mutatie, geen refire).

LAAG 2 (Σprofit / #trades) vereist een VOLLEDIGE refire per factor (de schaal zit in de prefix-checksum)
en muteert coin_fires — dat is een aparte, zware terminal-taak; zie het findings-doc.

Usage:
  min_volume_sensitivity.py [--sample N] [symbol_id ...]
    --sample N : meet elke N-de tick (default 1 = alle). Hoger = sneller, ratio blijft representatief.
    default munten: alle met een positieve buy-rule min_volume.
"""
import sys
import time

from db import brain
from rule_engine import RuleEngine
from volume import check_volumeud_3, volume_settings

RULES = (20, 21, 22, 23)
FACTORS = [0.5, 0.7, 0.8, 1.0, 1.2, 1.3, 1.5]    # ±50/30/20% rond de huidige schaal


def coins_with_rules(conn):
    with conn.cursor() as c:
        c.execute("SELECT DISTINCT crs.trading_symbol_id sid, co.symbol FROM coin_rule_settings crs "
                  "JOIN coins co ON co.id=crs.trading_symbol_id "
                  "WHERE crs.rule_number IN (20,21,22,23) AND crs.min_volume>0 ORDER BY sid")
        return [(r["sid"], r["symbol"]) for r in c.fetchall()]


def candidate_ratio(eng, dts, factor):
    """% ticks waarop minstens één buy-rule de volume_check haalt met min_volume*factor."""
    rs = []
    for r in RULES:
        mv = eng.minvol.get(r)
        if mv is None or mv >= 1e11:
            continue
        rs.append((float(mv) * factor, volume_settings(r)))
    if not rs:
        return None
    hits = 0
    for dt in dts:
        rows = eng._vol_rows(dt, 60)
        if any(check_volumeud_3(rows, mv, s) for mv, s in rs):
            hits += 1
    return 100.0 * hits / len(dts) if dts else None


def main():
    sample = 1
    if "--sample" in sys.argv:
        i = sys.argv.index("--sample")
        sample = int(sys.argv[i + 1])
        del sys.argv[i:i + 2]
    args = [int(a) for a in sys.argv[1:]]

    conn = brain()
    coins = [(s, n) for (s, n) in coins_with_rules(conn) if not args or s in args]
    conn.close()

    print(f"\nEpic K — O3 laag 1: kandidaat-ratio vs min_volume-factor. sample=1/{sample}. READ-ONLY.\n")
    hdr = f"{'munt':<11}{'n_meet':>8}  " + "".join(f"x{f:<6}" for f in FACTORS)
    print(hdr)
    print("-" * len(hdr))
    print(f"{'(basis x1.0)':<11}{'':>8}  " + "".join(f"{'':7}" for _ in FACTORS))

    for sym, name in coins:
        eng = RuleEngine(sym)
        s = eng.series.get("volumeud")
        dts = s["dt"][::sample] if s else []
        t0 = time.time()
        ratios = {f: candidate_ratio(eng, dts, f) for f in FACTORS}
        base = ratios.get(1.0)
        eng.close()
        cells = []
        for f in FACTORS:
            r = ratios[f]
            if r is None:
                cells.append(f"{'—':>7}")
            elif f == 1.0:
                cells.append(f"{r:>6.1f}*")        # de basis-ratio
            else:
                cells.append(f"{r:>6.1f} ")
        print(f"{name:<11}{len(dts):>8}  " + "".join(cells) + f"   ({time.time()-t0:.0f}s)")

    print("\nLezen: elke cel = kandidaat-ratio (%) bij die min_volume-factor. x1.0* = de huidige schaal.")
    print("Sterke daling naar rechts (hogere min_volume) / stijging naar links = de poort is gevoelig "
          "voor de schaal. Vlak = ruw-ongevoelig (vroege onnauwkeurige schatting minder erg).")


if __name__ == "__main__":
    main()
