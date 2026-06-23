#!/usr/bin/env python3
"""
relvol_sensitivity.py — gevoeligheids-check op de relvol-BASISLIJN (Daans "pas op het einde"-stap).

min_volume is zelf maar een gemiddeld-volume-schatting; voor de coin-agnostische rule telt alleen de
VERHOUDING tussen de basislijnen. Een uniforme schaling verandert niets (de gedeelde drempel schaalt
mee), dus we houden DOGEAI vast op zijn laagste min_volume en variëren ALLEEN NOS rond zijn standaard
(510): ×0,5 / ×1,0 / ×1,5. Per variant draaien we de volle coin-agnostische funnel + validatie en
rapporteren de compacte stand per munt, zodat zichtbaar wordt of een andere verhouding NOS over de lat
tilt zonder DOGEAI te schaden.

Draaien (vanuit engine/src):  NUMBA_DISABLE_JIT=1 python -m discovery.relvol_sensitivity
"""
from discovery.pooled import run_pooled

COINS = [(2525, "DOGEAI"), (244, "NOS")]
NOS_BASE = 510.0
FACTORS = (0.5, 1.0, 1.5)

if __name__ == "__main__":
    summary = []
    for f in FACTORS:
        nos_base = NOS_BASE * f
        print("\n" + "#" * 90)
        print(f"###### NOS-basislijn × {f}  (vol_base = {nos_base:g}; DOGEAI vast op laagste min_volume) ######")
        print("#" * 90)
        _struct, results, keepers, _sub = run_pooled(
            COINS, vol_bases={"NOS": nos_base},
            out_name=f"pooled_rule_nosx{f}.json", n_perm=1000)
        summary.append((f, results, keepers))

    print("\n" + "=" * 90)
    print("  GEVOELIGHEIDS-SAMENVATTING — NOS-basislijn-factor vs. de stand per munt")
    print("=" * 90)
    for f, results, keepers in summary:
        print(f"\n  NOS × {f}:")
        for nm in ("DOGEAI", "NOS"):
            r = results[nm]
            cls = r["cls"]
            tot = cls["goed"] + cls["middel"] + cls["slecht"]
            badpct = 100 * cls["slecht"] / tot if tot else 0
            print(f"    {nm:7s} sel {100*r['selectivity']:.3f}% | gem {r['mean']:+.3f}%/trade | "
                  f"slecht {badpct:.0f}% | CPCV {r['cpcv']['mean_oos']:+.3f}% | p={r['perm']['p']:.3f}"
                  + ("  ← haalt de lat" if keepers[nm] else ""))
